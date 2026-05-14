<?php

declare(strict_types=1);

namespace App\Message\Telegram\Chat;

/** Кінцева обробка відповіді агента для групи / супергрупи. */
final readonly class ProcessTelegramGroupMessage
{
    public function __construct(
        public int $telegramChatId,
        public int $triggerTelegramMessageId,
    ) {}
}
