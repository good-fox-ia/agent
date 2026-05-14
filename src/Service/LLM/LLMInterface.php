<?php

declare(strict_types=1);

namespace App\Service\LLM;

use App\Service\LLM\DTO\PromptDTO;

interface LLMInterface
{
    public function isConfigured(): bool;

    public function complete(PromptDTO $prompt, array $options = []): string;

    public function transcribeAudio(string $audioBinary, string $filename = 'audio.ogg', array $options = []): string;
}
