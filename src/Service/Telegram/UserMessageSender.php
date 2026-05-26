<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Document\User;
use App\Repository\UserRepository;
use App\Service\Telegram\Keyboard\UserReplyMarkupResolver;

/**
 * Надсилання повідомлень у приватний чат з урахуванням налаштування reply-клавіатури користувача.
 */
final class UserMessageSender
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserRepository $users,
        private readonly UserReplyMarkupResolver $replyMarkup,
    ) {}



    
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function sendToUser(User $user, string $text, array $options = []): array
    {
        return $this->telegram->sendMessage(
            $user->getTelegramUserId(),
            $text,
            $this->replyMarkup->applyForUser($user, $options),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function send(int $chatId, string $text, bool $isGroup, array $options = [], ?User $user = null): array
    {
        if (!$isGroup) {
            $user ??= $this->users->findOneByTelegramUserId($chatId);
            if ($user !== null) {
                $options = $this->replyMarkup->applyForUser($user, $options);
            }
        }

        return $this->telegram->sendMessage($chatId, $text, $options);
    }
}
