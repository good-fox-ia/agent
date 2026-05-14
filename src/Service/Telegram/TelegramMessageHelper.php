<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * Допоміжний розбір полів JSON повідомлення Telegram (без доступу до БД).
 */
final class TelegramMessageHelper
{
    private function __construct() {}

    public static function isGroup(array $telegramMessage): bool
    {
        $chatType = (string) ($telegramMessage['chat']['type'] ?? 'private');

        return in_array($chatType, ['group', 'supergroup'], true);
    }

    public static function visibleTextBody(array $telegramMessage): string
    {
        if (isset($telegramMessage['text'])) return trim((string) $telegramMessage['text']);
        if (isset($telegramMessage['caption'])) return trim((string) $telegramMessage['caption']);

        return '';
    }
}
