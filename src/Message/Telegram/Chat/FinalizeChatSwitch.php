<?php

declare(strict_types=1);

namespace App\Message\Telegram\Chat;

final readonly class FinalizeChatSwitch
{
    public function __construct(
        public string $logicalChatId,
        public int $telegramUserId,
        public int $telegramChatId,
        public int $placeholderTelegramMessageId,
        public bool $isGroup,
    ) {}
}
