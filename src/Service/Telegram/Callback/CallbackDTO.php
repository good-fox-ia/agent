<?php

declare(strict_types=1);

namespace App\Service\Telegram\Callback;

final readonly class CallbackDTO
{
    /**
     * @param array<string, mixed> $message сире повідомлення Telegram, на якому натиснули кнопку
     */
    private function __construct(
        public int $fromId,
        public int $chatId,
        public string $callbackId,
        public string $data,
        public int $messageId,
        public array $message = [],
    ) {}

    public static function buildFromArray(array $callbackQuery): ?self
    {
        $fromId = ((int) $callbackQuery['from']['id']) ?? null;
        $chatId = ((int) $callbackQuery['message']['chat']['id']) ?? null;
        $callbackId = ((string) $callbackQuery['id']) ?? null;
        $data = ((string) $callbackQuery['data']) ?? null;
        $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];

        if (!isset($fromId) || !isset($chatId) || !isset($callbackId) || !isset($data)) return null;

        return new self($fromId, $chatId, $callbackId, $data, $messageId, $message);
    }
}
