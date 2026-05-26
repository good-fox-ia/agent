<?php

declare(strict_types=1);

namespace App\Service\Telegram\Callback\Handler;

use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Callback\CallbackDTO;
use App\Service\Telegram\Chat\ChatHistoryFormatter;
use App\Service\Telegram\Chat\ChatSummaryPresenter;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\TelegramService;
use App\Service\Telegram\UserMessageSender;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Обробляє кнопку «Показати все листування» (callback_data: sh:{chatId}).
 */
final class HistoryProcessor
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ChatRepository $chats,
        private readonly ChatHistoryFormatter $historyFormatter,
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(string $data): bool
    {
        return str_starts_with($data, ChatSummaryPresenter::HISTORY_CALLBACK_PREFIX);
    }

    public function process(CallbackDTO $callback): void
    {
        if (!$this->handles($callback)) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, callback історії пропущено');

            return;
        }

        $logicalChatId = substr($callback->data, strlen(ChatSummaryPresenter::HISTORY_CALLBACK_PREFIX));
        if ($logicalChatId === '') {
            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload(['id' => $callback->fromId]);
            $this->documentManager->flush();

            $logicalChat = $this->chats->findOneByIdForUser($logicalChatId, $user);
            if ($logicalChat === null) {
                if ($callback->callbackId !== '') {
                    $this->telegram->answerCallbackQuery($callback->callbackId, 'Бесіду не знайдено');
                }

                return;
            }

            if ($callback->callbackId !== '') {
                $this->telegram->answerCallbackQuery($callback->callbackId);
            }

            $isGroup = $callback->chatId < 0;
            $chunks = $this->historyFormatter->formatChunks($logicalChat);

            foreach ($chunks as $chunk) {
                $sent = $isGroup
                    ? $this->messageSender->send($callback->chatId, $chunk, true)
                    : $this->messageSender->sendToUser($user, $chunk);

                $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, null, $logicalChat);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Помилка callback історії бесіди: {error}', ['error' => $e->getMessage()]);
            if ($callback->callbackId !== '') {
                try {
                    $this->telegram->answerCallbackQuery($callback->callbackId, 'Помилка надсилання');
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
