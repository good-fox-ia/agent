<?php

declare(strict_types=1);

namespace App\Service\LLM\DTO;

/** Згенероване (відредаговане) зображення від LLM. */
final readonly class GeneratedImageDTO
{
    public function __construct(
        public string $binary,
        public string $mimeType,
    ) {}
}
