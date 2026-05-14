<?php

declare(strict_types=1);

namespace App\Message\Telegram;

/** Будь-яке вхідне повідомлення Telegram (message / edited_message) */
final readonly class ProcessTelegramMessage
{
    public function __construct(public array $message) {}
}
