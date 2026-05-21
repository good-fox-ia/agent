<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Document\Message;

/**
 * Контекст поточного виклику LLM з Telegram (повідомлення та вхідний запис у БД).
 */
final class TelegramLlmInvocationContext
{
    /** @var array<string, mixed>|null */
    private ?array $telegramMessage = null;

    private ?Message $inbound = null;

    /**
     * @param array<string, mixed> $telegramMessage
     */
    public function begin(array $telegramMessage, ?Message $inbound): void
    {
        $this->telegramMessage = $telegramMessage;
        $this->inbound = $inbound;
    }

    public function clear(): void
    {
        $this->telegramMessage = null;
        $this->inbound = null;
    }

    public function isActive(): bool
    {
        return $this->telegramMessage !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTelegramMessage(): array
    {
        if ($this->telegramMessage === null) {
            throw new \RuntimeException('Telegram LLM context is not set.');
        }

        return $this->telegramMessage;
    }

    public function getInbound(): ?Message
    {
        return $this->inbound;
    }
}
