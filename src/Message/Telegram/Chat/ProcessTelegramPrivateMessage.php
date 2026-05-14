<?php

declare(strict_types=1);

namespace App\Message\Telegram\Chat;

/** Кінцева обробка відповіді агента для приватного чату. */
final readonly class ProcessTelegramPrivateMessage
{
    public function __construct(
        public int $telegramChatId,
        public int $triggerTelegramMessageId,
    ) {}
}
