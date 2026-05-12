<?php

namespace App\Service\LLM;

interface LLMInterface
{
    public function complete(string $prompt, array $options = []): string;
}
