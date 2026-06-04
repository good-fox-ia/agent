<?php

declare(strict_types=1);

namespace App\Service\LLM\Client\Interface;

interface AudioTranscriptionLLMInterface
{
    public function isConfigured(): bool;

    public function transcribeAudio(string $audioBinary, string $filename = 'audio.ogg', array $options = []): string;
}
