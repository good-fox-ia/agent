<?php

declare(strict_types=1);

namespace App\Service\LLM\Client\Interface;

use App\Service\LLM\DTO\GeneratedAudioDTO;

interface AudioGenerationLLMInterface
{
    public function isConfigured(): bool;

    /**
     * Генерує аудіо (TTS) за текстом: текст -> озвучка.
     */
    public function generateAudio(string $text, array $options = []): GeneratedAudioDTO;
}
