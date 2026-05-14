<?php

declare(strict_types=1);

namespace App\Message\Telegram\Content;

/**
 * Голос / audio — транскрипція, далі маршрут на private/group.
 *
 * @phpstan-type TelegramMessage array<string, mixed>
 */
final readonly class ProcessTelegramAudioMessage
{
    /**
     * @param array<string, mixed> $telegramMessage
     */
    public function __construct(
        public array $telegramMessage,
        public string $fileId,
        public string $audioFilename,
    ) {}
}
