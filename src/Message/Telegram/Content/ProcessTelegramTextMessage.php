<?php

declare(strict_types=1);

namespace App\Message\Telegram\Content;

/**
 * Вхідне текстове (або caption) повідомлення Telegram — маршрутизується на черги private/group.
 *
 * @phpstan-type TelegramMessage array<string, mixed>
 */
final readonly class ProcessTelegramTextMessage
{
    /**
     * @param array<string, mixed> $telegramMessage об'єкт message / edited_message з Telegram API
     */
    public function __construct(public array $telegramMessage) {}
}
