<?php

declare(strict_types=1);

namespace App\Service\Telegram\Context;

use App\Document\Message;

/**
 * Контекст поточного виклику LLM з Telegram (повідомлення та вхідний запис у БД).
 */
final class TelegramLlmInvocationContext
{
    /** @var array<string, mixed>|null */
    private ?array $telegramMessage = null;

    private ?Message $inbound = null;

    /** Тулз попросив не надсилати фінальну текстову відповідь LLM у чат. */
    private bool $replySuppressed = false;

    /**
     * @param array<string, mixed> $telegramMessage
     */
    public function begin(array $telegramMessage, ?Message $inbound): void
    {
        $this->telegramMessage = $telegramMessage;
        $this->inbound = $inbound;
        $this->replySuppressed = false;
    }

    public function clear(): void
    {
        $this->telegramMessage = null;
        $this->inbound = null;
        $this->replySuppressed = false;
    }

    public function suppressReply(): void
    {
        $this->replySuppressed = true;
    }

    public function isReplySuppressed(): bool
    {
        return $this->replySuppressed;
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

