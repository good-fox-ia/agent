<?php

declare(strict_types=1);

namespace App\Service\LLM\Client\Interface;

use App\Service\LLM\DTO\GeneratedImageDTO;

interface ImageGenerationLLMInterface
{
    public function isConfigured(): bool;

    /**
     * Генерує зображення за текстовим промптом (text-to-image).
     */
    public function generateImage(string $prompt, array $options = []): GeneratedImageDTO;

    /**
     * Редагує зображення за текстовим промптом: картинка + інструкція -> нова картинка.
     */
    public function editImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): GeneratedImageDTO;
}
