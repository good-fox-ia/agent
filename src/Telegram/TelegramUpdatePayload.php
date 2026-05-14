<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Допоміжний розбір полів JSON оновлення Telegram (без доступу до БД).
 */
final class TelegramUpdatePayload
{
    /**
     * Текст або підпис до медіа, видимий для діалогу з ботом.
     *
     * @param array<string, mixed> $telegramMessage
     */
    public static function visibleTextBody(array $telegramMessage): string
    {
        if (isset($telegramMessage['text'])) {
            return trim((string) $telegramMessage['text']);
        }
        if (isset($telegramMessage['caption'])) {
            return trim((string) $telegramMessage['caption']);
        }

        return '';
    }
}
