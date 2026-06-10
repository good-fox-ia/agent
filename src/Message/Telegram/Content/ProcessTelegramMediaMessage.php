<?php

declare(strict_types=1);

namespace App\Message\Telegram\Content;

/** Фото / відео / документ — завантаження файлу на сервер і збереження шляху в Message. */
final readonly class ProcessTelegramMediaMessage
{
    public function __construct(public array $message) {}
}
