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

    /**
     * @param array<string, mixed> $telegramMessage
     *
     * @return array<string, mixed>
     */
    public static function withCommandText(array $telegramMessage, TelegramBotCommand $command): array
    {
        $message = $telegramMessage;
        $message['text'] = $command->asSlash();

        return $message;
    }

    public static function commandArguments(array $telegramMessage): string
    {
        $text = self::visibleTextBody($telegramMessage);
        if ($text === '' || !str_starts_with($text, '/')) {
            return '';
        }

        if (!preg_match('#^/\S+\s+(.*)$#s', $text, $matches)) {
            return '';
        }

        return trim($matches[1]);
    }

    public static function withCommandTextAndArgs(
        array $telegramMessage,
        TelegramBotCommand $command,
        string $arguments,
    ): array {
        $message = self::withCommandText($telegramMessage, $command);
        $args = trim($arguments);
        if ($args !== '') {
            $message['text'] = $command->asSlash() . ' ' . $args;
        }

        return $message;
    }
}
