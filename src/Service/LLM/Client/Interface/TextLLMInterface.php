<?php

declare(strict_types=1);

namespace App\Service\LLM\Client\Interface;

use App\Service\LLM\DTO\PromptDTO;

interface TextLLMInterface
{
    public function isConfigured(): bool;

    public function complete(PromptDTO $prompt, array $options = []): string;
}
