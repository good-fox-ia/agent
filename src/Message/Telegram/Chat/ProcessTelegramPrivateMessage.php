<?php

declare(strict_types=1);

namespace App\Message\Telegram\Chat;

/** Кінцева обробка відповіді агента для приватного чату. */
final readonly class ProcessTelegramPrivateMessage
{
    /**
     * @param array<string, mixed>|null $telegramMessage повний payload Telegram для контексту LLM-тулзів
     */
    public function __construct(
        public int $telegramChatId,
        public int $triggerTelegramMessageId,
        public ?array $telegramMessage = null,
    ) {}
}
