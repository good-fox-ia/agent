<?php

declare(strict_types=1);

namespace App\Service\LLM\Client\Interface;

interface ImageDescriptionLLMInterface
{
    public function isConfigured(): bool;

    /**
     * Описує зображення (vision). Без тулзів і системного промпту — лише картинка + текст запиту.
     */
    public function describeImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): string;
}
