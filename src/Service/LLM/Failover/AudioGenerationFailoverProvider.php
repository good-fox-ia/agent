<?php

declare(strict_types=1);

namespace App\Service\LLM\Failover;

use App\Service\LLM\Client\Interface\AudioGenerationLLMInterface;
use App\Service\LLM\DTO\GeneratedAudioDTO;
use Psr\Log\LoggerInterface;

final class AudioGenerationFailoverProvider implements AudioGenerationLLMInterface
{
    use LlmProviderFailoverTrait;

    /**
     * @param list<AudioGenerationLLMInterface> $providers
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

    public function generateAudio(string $text, array $options = []): GeneratedAudioDTO
    {
        return $this->tryProviders(
            $this->providers,
            static fn (AudioGenerationLLMInterface $provider): GeneratedAudioDTO => $provider->generateAudio($text, $options),
            'audio generation',
        );
    }
}
