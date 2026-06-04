<?php

declare(strict_types=1);

namespace App\Service\LLM\Failover;

use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use Psr\Log\LoggerInterface;

final class TextFailoverProvider implements TextLLMInterface
{
    use LlmProviderFailoverTrait;

    /**
     * @param list<TextLLMInterface> $providers
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

    public function complete(PromptDTO $prompt, array $options = []): string
    {
        return $this->tryProviders(
            $this->providers,
            static fn (TextLLMInterface $provider): string => $provider->complete($prompt, $options),
            'text completion',
        );
    }
}
