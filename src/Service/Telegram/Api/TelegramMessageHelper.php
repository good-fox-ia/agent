<?php

declare(strict_types=1);

namespace App\Service\Telegram\Api;

use App\Enum\TelegramBotCommand;
use App\Enum\TelegramBotCommandScope;

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

    public static function commandScope(array $telegramMessage): TelegramBotCommandScope
    {
        return self::isGroup($telegramMessage)
            ? TelegramBotCommandScope::GROUP
            : TelegramBotCommandScope::PRIVATE;
    }

    public static function visibleTextBody(array $telegramMessage): string
    {
        if (isset($telegramMessage['text'])) return trim((string) $telegramMessage['text']);
        if (isset($telegramMessage['caption'])) return trim((string) $telegramMessage['caption']);

        return '';
    }

    /**
     * @param array<string, mixed> $telegramMessage
     *
     * @return list<string>
     */
    public static function extractUrls(array $telegramMessage): array
    {
        $urls = [];
        $text = self::visibleTextBody($telegramMessage);
        if ($text !== '' && preg_match_all('#https?://[^\s<>"\'\)\]]+#i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $urls[] = rtrim($url, '.,;:!?');
            }
        }

        $entitiesKey = isset($telegramMessage['caption']) ? 'caption_entities' : 'entities';
        $entities = $telegramMessage[$entitiesKey] ?? [];
        if (!is_array($entities) || $text === '') {
            return array_values(array_unique($urls));
        }

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $type = (string) ($entity['type'] ?? '');
            if ($type === 'text_link' && isset($entity['url'])) {
                $urls[] = rtrim((string) $entity['url'], '.,;:!?');
                continue;
            }

            if ($type === 'url') {
                $offset = (int) ($entity['offset'] ?? 0);
                $length = (int) ($entity['length'] ?? 0);
                if ($length > 0) {
                    $urls[] = rtrim(mb_substr($text, $offset, $length), '.,;:!?');
                }
            }
        }

        return array_values(array_unique($urls));
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

        if (!preg_match('#^/\\S+\\s+(.*)$#s', $text, $matches)) {
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

