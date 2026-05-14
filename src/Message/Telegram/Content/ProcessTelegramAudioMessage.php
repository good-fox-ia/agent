<?php

declare(strict_types=1);

namespace App\Message\Telegram\Content;

/** Голос / audio — транскрипція, далі маршрут на private/group. */
final readonly class ProcessTelegramAudioMessage
{
    public function __construct(public array $message) {}
}
