<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat\UI;

use App\Document\Chat;
use App\Document\Message;
use App\Document\User;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Надсилає список бесід користувача з inline-кнопками для вибору.
 */
final class ChatListResponder
{
    public const CALLBACK_PREFIX = 'sc:';

    private const BUTTON_LABEL_MAX = 40;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(User $user, int $telegramChatId, ?Message $inbound, bool $isGroup): void
    {
        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, список бесід пропущено chat={chat}', [
                'chat' => $telegramChatId,
            ]);

            return;
        }

        $chats = $this->sortedChats($user);
        if ($chats === []) {
            $this->sendPlain($user, $telegramChatId, $inbound, $isGroup, 'У вас ще немає збережених бесід. Створіть нову командою /newchat.');

            return;
        }

        $currentId = $user->getCurrentChat()?->getId();
        $keyboard = [];

        foreach ($chats as $chat) {
            $isActive = $chat->getId() === $currentId;
            $keyboard[] = [[
                'text' => $this->buttonLabel($chat, $isActive),
                'callback_data' => self::CALLBACK_PREFIX . $chat->getId(),
            ]];
        }

        try {
            $options = [
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ];
            $sent = $isGroup
                ? $this->messageSender->send($telegramChatId, 'Ваш список бесід', true, $options)
                : $this->messageSender->sendToUser($user, 'Ваш список бесід', $options);

            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                $isGroup,
                $inbound,
                $user->getCurrentChat(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка надсилання списку бесід chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<Chat>
     */
    private function sortedChats(User $user): array
    {
        $chats = $user->getChats()->toArray();
        usort(
            $chats,
            static fn (Chat $a, Chat $b): int => $b->getUpdatedAt() <=> $a->getUpdatedAt(),
        );

        return $chats;
    }

    private function buttonLabel(Chat $chat, bool $isActive): string
    {
        $prefix = $isActive ? '✓ ' : '';
        $title = $chat->getTitle() ?? 'Бесіда';
        $label = $prefix . $title;
        if (mb_strlen($label) > self::BUTTON_LABEL_MAX) {
            $label = mb_substr($label, 0, self::BUTTON_LABEL_MAX - 1) . '…';
        }

        return $label;
    }

    private function sendPlain(
        User $user,
        int $telegramChatId,
        ?Message $inbound,
        bool $isGroup,
        string $text,
    ): void {
        try {
            $sent = $isGroup
                ? $this->messageSender->send($telegramChatId, $text, true)
                : $this->messageSender->sendToUser($user, $text);

            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                $isGroup,
                $inbound,
                $user->getCurrentChat(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка повідомлення списку бесід chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

