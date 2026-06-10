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

    public static function hasMediaAttachment(array $telegramMessage): bool
    {
        return self::resolveMediaAttachment($telegramMessage) !== null;
    }

    /**
     * Фото / відео / документ у повідомленні: тип, file_id, розмір і назва файлу (якщо відомі).
     *
     * @param array<string, mixed> $telegramMessage
     *
     * @return array{type: string, file_id: string, file_size: int|null, file_name: string|null}|null
     */
    public static function resolveMediaAttachment(array $telegramMessage): ?array
    {
        $photo = $telegramMessage['photo'] ?? null;
        if (is_array($photo) && $photo !== []) {
            // Telegram надсилає масив розмірів; останній — найбільший
            $largest = $photo[array_key_last($photo)];
            if (is_array($largest) && isset($largest['file_id'])) {
                return [
                    'type' => 'photo',
                    'file_id' => (string) $largest['file_id'],
                    'file_size' => isset($largest['file_size']) ? (int) $largest['file_size'] : null,
                    'file_name' => null,
                ];
            }
        }

        foreach (['video', 'document', 'animation', 'video_note'] as $key) {
            $media = $telegramMessage[$key] ?? null;
            if (is_array($media) && isset($media['file_id'])) {
                $fileName = $media['file_name'] ?? null;

                return [
                    'type' => $key,
                    'file_id' => (string) $media['file_id'],
                    'file_size' => isset($media['file_size']) ? (int) $media['file_size'] : null,
                    'file_name' => is_string($fileName) && $fileName !== '' ? basename($fileName) : null,
                ];
            }
        }

        return null;
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
    public static function withVisibleTextBody(array $telegramMessage, string $text): array
    {
        $message = $telegramMessage;
        if (isset($message['caption'])) {
            $message['caption'] = $text;
        } else {
            $message['text'] = $text;
        }

        return $message;
    }

    public static function withCommandText(array $telegramMessage, TelegramBotCommand $command): array
    {
        return self::withVisibleTextBody($telegramMessage, $command->asSlash());
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

