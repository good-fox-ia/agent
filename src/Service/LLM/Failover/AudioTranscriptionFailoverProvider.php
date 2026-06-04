<?php

declare(strict_types=1);

namespace App\Service\LLM\Failover;

use App\Service\LLM\Client\Interface\AudioTranscriptionLLMInterface;
use Psr\Log\LoggerInterface;

final class AudioTranscriptionFailoverProvider implements AudioTranscriptionLLMInterface
{
    use LlmProviderFailoverTrait;

    /**
     * @param list<AudioTranscriptionLLMInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function isConfigured(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    public function transcribeAudio(string $audioBinary, string $filename = 'audio.ogg', array $options = []): string
    {
        return $this->tryProviders(
            $this->providers,
            static fn (AudioTranscriptionLLMInterface $provider): string => $provider->transcribeAudio($audioBinary, $filename, $options),
            'audio transcription',
        );
    }
}
