<?php

namespace App\Service\LLM;

interface LLMInterface
{
    public function isConfigured(): bool;

    public function complete(string $prompt, array $options = []): string;
}
