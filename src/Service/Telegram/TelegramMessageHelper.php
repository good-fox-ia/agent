<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Enum\TelegramBotCommand;

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

    public static function parseBotCommand(array $telegramMessage): ?TelegramBotCommand
    {
        $text = self::visibleTextBody($telegramMessage);
        if ($text === '' || !str_starts_with($text, '/')) {
            return null;
        }

        if (!preg_match('#^/([a-z0-9_]+)#i', $text, $matches)) {
            return null;
        }

        return TelegramBotCommand::tryFrom(strtolower($matches[1]));
    }
}
