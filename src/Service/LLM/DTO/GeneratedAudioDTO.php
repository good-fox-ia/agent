<?php

declare(strict_types=1);

namespace App\Service\LLM\DTO;

/** Згенероване аудіо (TTS) від LLM. */
final readonly class GeneratedAudioDTO
{
    public function __construct(
        public string $binary,
        public string $mimeType,
    ) {}
}
