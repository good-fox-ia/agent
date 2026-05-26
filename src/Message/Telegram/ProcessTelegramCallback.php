<?php

declare(strict_types=1);

namespace App\Message\Telegram;

final readonly class ProcessTelegramCallback
{
    public function __construct(public array $callbackQuery) {}
}
