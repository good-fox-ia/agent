<?php

declare(strict_types=1);

namespace App\Service\Telegram\Video;

/**
 * Результат завантаження: локальні файли медіа та опційний підпис з соцмережі.
 * Для kind=Text медіа немає — лише текст поста у caption.
 */
final readonly class SocialVideoDownloadDTO
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public SocialMediaKind $kind,
        public array $paths,
        public ?string $caption = null,
    ) {
        if ($paths === [] && $kind !== SocialMediaKind::Text) {
            throw new \InvalidArgumentException('SocialVideoDownloadDTO requires at least one media path.');
        }
    }

    public function primaryPath(): ?string
    {
        return $this->paths[0] ?? null;
    }
}
