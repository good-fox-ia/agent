<?php

declare(strict_types=1);

namespace App\Service\Telegram\Video;

/**
 * Результат завантаження: локальний файл відео та опційний підпис з соцмережі.
 */
final readonly class SocialVideoDownloadDTO
{
    public function __construct(
        public string $path,
        public ?string $caption = null,
    ) {}
}
