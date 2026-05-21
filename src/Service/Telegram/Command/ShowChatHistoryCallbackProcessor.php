<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Chat\ChatHistoryFormatter;
use App\Service\Telegram\Chat\ChatSummaryPresenter;
use App\Service\Telegram\TelegramMessageHelper;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\TelegramService;
use App\Service\Telegram\TelegramUserMessageSender;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Обробляє кнопку «Показати все листування» (callback_data: sh:{chatId}).
 */
final class ShowChatHistoryCallbackProcessor
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ChatRepository $chats,
        private readonly ChatHistoryFormatter $historyFormatter,
        private readonly TelegramService $telegram,
        private readonly TelegramUserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(array $callbackQuery): bool
    {
        $data = (string) ($callbackQuery['data'] ?? '');

        return str_starts_with($data, ChatSummaryPresenter::HISTORY_CALLBACK_PREFIX);
    }

    public function process(array $callbackQuery): void
    {
        if (!$this->handles($callbackQuery)) {
            return;
        }

        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $from = $callbackQuery['from'] ?? null;
        $message = $callbackQuery['message'] ?? null;

        if (!is_array($from) || !isset($from['id']) || !is_array($message)) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, callback історії пропущено');

            return;
        }

        $logicalChatId = substr((string) $callbackQuery['data'], strlen(ChatSummaryPresenter::HISTORY_CALLBACK_PREFIX));
        if ($logicalChatId === '') {
            return;
        }

        $telegramChatId = (int) ($message['chat']['id'] ?? 0);
        if ($telegramChatId === 0) {
            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload($from);
            $this->documentManager->flush();

            $logicalChat = $this->chats->findOneByIdForUser($logicalChatId, $user);
            if ($logicalChat === null) {
                if ($callbackId !== '') {
                    $this->telegram->answerCallbackQuery($callbackId, 'Бесіду не знайдено');
                }

                return;
            }

            if ($callbackId !== '') {
                $this->telegram->answerCallbackQuery($callbackId);
            }

            $isGroup = TelegramMessageHelper::isGroup($message);
            $chunks = $this->historyFormatter->formatChunks($logicalChat);

            foreach ($chunks as $chunk) {
                $sent = $isGroup
                    ? $this->messageSender->send($telegramChatId, $chunk, true)
                    : $this->messageSender->sendToUser($user, $chunk);

                $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, null, $logicalChat);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Помилка callback історії бесіди: {error}', ['error' => $e->getMessage()]);
            if ($callbackId !== '') {
                try {
                    $this->telegram->answerCallbackQuery($callbackId, 'Помилка надсилання');
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
