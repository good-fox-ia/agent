<?php

declare(strict_types=1);

namespace App\Message\Telegram;

final readonly class ProcessTelegramCallbackQuery
{
    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function __construct(
        public array $callbackQuery,
    ) {}
}
