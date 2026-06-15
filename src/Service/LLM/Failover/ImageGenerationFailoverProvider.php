<?php

declare(strict_types=1);

namespace App\Service\LLM\Failover;

use App\Service\LLM\Client\Interface\ImageGenerationLLMInterface;
use App\Service\LLM\DTO\GeneratedImageDTO;
use Psr\Log\LoggerInterface;

final class ImageGenerationFailoverProvider implements ImageGenerationLLMInterface
{
    use LlmProviderFailoverTrait;

    /**
     * @param list<ImageGenerationLLMInterface> $providers
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

    public function generateImage(string $prompt, array $options = []): GeneratedImageDTO
    {
        return $this->tryProviders(
            $this->providers,
            static fn (ImageGenerationLLMInterface $provider): GeneratedImageDTO => $provider->generateImage($prompt, $options),
            'image generation (text-to-image)',
        );
    }

    public function editImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): GeneratedImageDTO
    {
        return $this->tryProviders(
            $this->providers,
            static fn (ImageGenerationLLMInterface $provider): GeneratedImageDTO => $provider->editImage($imageBinary, $mimeType, $prompt, $options),
            'image generation',
        );
    }
}
