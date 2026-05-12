<?php

namespace App\Service\LLM;

interface LLMInterface
{
    public function isConfigured(): bool;
    public function complete(string $prompt, array $options = []): string;
    public function transcribeAudio(string $audioBinary, string $filename = 'audio.ogg', array $options = []): string;
}
