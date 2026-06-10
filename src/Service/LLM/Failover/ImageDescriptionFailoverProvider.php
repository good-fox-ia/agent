<?php

declare(strict_types=1);

namespace App\Service\LLM\Failover;

use App\Service\LLM\Client\Interface\ImageDescriptionLLMInterface;
use Psr\Log\LoggerInterface;

final class ImageDescriptionFailoverProvider implements ImageDescriptionLLMInterface
{
    use LlmProviderFailoverTrait;

    /**
     * @param list<ImageDescriptionLLMInterface> $providers
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

    public function describeImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): string
    {
        return $this->tryProviders(
            $this->providers,
            static fn (ImageDescriptionLLMInterface $provider): string => $provider->describeImage($imageBinary, $mimeType, $prompt, $options),
            'image description',
        );
    }
}
