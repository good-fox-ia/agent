<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Chat\ChatListPresenter;
use App\Service\Telegram\Chat\UserChatSwitcher;
use App\Service\Telegram\TelegramMessageHelper;
use App\Service\Telegram\TelegramService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Обробляє натискання inline-кнопки вибору бесіди (callback_data: sc:{chatId}).
 */
final class SelectChatCallbackProcessor
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ChatRepository $chats,
        private readonly UserChatSwitcher $chatSwitcher,
        private readonly TelegramService $telegram,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(array $callbackQuery): bool
    {
        $data = (string) ($callbackQuery['data'] ?? '');

        return str_starts_with($data, ChatListPresenter::CALLBACK_PREFIX);
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
            $this->logger->warning('SelectChat callback: missing from or message');

            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, callback вибору бесіди пропущено');

            return;
        }

        $logicalChatId = substr((string) $callbackQuery['data'], strlen(ChatListPresenter::CALLBACK_PREFIX));
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
                $this->logger->warning('SelectChat callback: chat not found id={id} user={user}', [
                    'id' => $logicalChatId,
                    'user' => $user->getTelegramUserId(),
                ]);
                if ($callbackId !== '') {
                    $this->telegram->answerCallbackQuery($callbackId, 'Бесіду не знайдено');
                }

                return;
            }

            if ($callbackId !== '') {
                $this->telegram->answerCallbackQuery($callbackId);
            }

            $isGroup = TelegramMessageHelper::isGroup($message);
            $this->chatSwitcher->switchTo($user, $logicalChat, $telegramChatId, null, $isGroup);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка callback вибору бесіди: {error}', ['error' => $e->getMessage()]);
            if ($callbackId !== '') {
                try {
                    $this->telegram->answerCallbackQuery($callbackId, 'Помилка перемикання');
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
