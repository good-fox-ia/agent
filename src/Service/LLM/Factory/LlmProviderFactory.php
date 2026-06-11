<?php

declare(strict_types=1);

namespace App\Service\LLM\Factory;

use App\Service\LLM\Client\CloudflareWorkersAi;
use App\Service\LLM\Client\Gemini;
use App\Service\LLM\Client\Groq;
use App\Service\LLM\Client\OpenRouter;
use App\Service\LLM\Failover\AudioGenerationFailoverProvider;
use App\Service\LLM\Failover\AudioTranscriptionFailoverProvider;
use App\Service\LLM\Failover\ImageDescriptionFailoverProvider;
use App\Service\LLM\Failover\ImageGenerationFailoverProvider;
use App\Service\LLM\Failover\TextFailoverProvider;
use App\Service\LLM\Client\Interface\AudioGenerationLLMInterface;
use App\Service\LLM\Client\Interface\AudioTranscriptionLLMInterface;
use App\Service\LLM\Client\Interface\ImageDescriptionLLMInterface;
use App\Service\LLM\Client\Interface\ImageGenerationLLMInterface;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use Psr\Log\LoggerInterface;

final class LlmProviderFactory
{
    /** @var list<string> */
    private readonly array $textProviderNames;

    /** @var list<string> */
    private readonly array $audioProviderNames;

    /** @var list<string> */
    private readonly array $imageProviderNames;

    /** @var list<string> */
    private readonly array $imageGenerationProviderNames;

    /** @var list<string> */
    private readonly array $audioGenerationProviderNames;

    public function __construct(
        private readonly Groq $groq,
        private readonly Gemini $gemini,
        private readonly OpenRouter $openRouter,
        private readonly CloudflareWorkersAi $cloudflare,
        private readonly string $textProvidersCsv,
        private readonly string $audioProvidersCsv,
        private readonly string $imageProvidersCsv = '',
        private readonly string $imageGenerationProvidersCsv = 'gemini',
        private readonly string $audioGenerationProvidersCsv = 'gemini',
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->textProviderNames = self::parseProviderNames($textProvidersCsv);
        $this->audioProviderNames = self::parseProviderNames($audioProvidersCsv);
        $this->imageProviderNames = self::parseProviderNames($imageProvidersCsv);
        $this->imageGenerationProviderNames = self::parseProviderNames($imageGenerationProvidersCsv);
        $this->audioGenerationProviderNames = self::parseProviderNames($audioGenerationProvidersCsv);
    }

    public function createTextLlm(): TextLLMInterface
    {
        return new TextFailoverProvider(
            $this->buildProviderList($this->textProviderNames),
            $this->logger,
        );
    }

    public function createAudioTranscriptionLlm(): AudioTranscriptionLLMInterface
    {
        return new AudioTranscriptionFailoverProvider(
            $this->buildProviderList($this->audioProviderNames),
            $this->logger,
        );
    }

    public function createImageDescriptionLlm(): ImageDescriptionLLMInterface
    {
        return new ImageDescriptionFailoverProvider(
            $this->buildProviderList($this->imageProviderNames),
            $this->logger,
        );
    }

    public function createImageGenerationLlm(): ImageGenerationLLMInterface
    {
        $providers = array_values(array_filter(
            $this->buildProviderList($this->imageGenerationProviderNames),
            static fn (object $provider): bool => $provider instanceof ImageGenerationLLMInterface,
        ));

        return new ImageGenerationFailoverProvider($providers, $this->logger);
    }

    public function createAudioGenerationLlm(): AudioGenerationLLMInterface
    {
        $providers = array_values(array_filter(
            $this->buildProviderList($this->audioGenerationProviderNames),
            static fn (object $provider): bool => $provider instanceof AudioGenerationLLMInterface,
        ));

        return new AudioGenerationFailoverProvider($providers, $this->logger);
    }

    /**
     * @param list<string> $names
     *
     * @return list<Groq|Gemini|OpenRouter|CloudflareWorkersAi>
     */
    private function buildProviderList(array $names): array
    {
        $providers = [];
        foreach ($names as $name) {
            $provider = $this->resolve($name);
            if (!in_array($provider, $providers, true)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    private function resolve(string $provider): Groq|Gemini|OpenRouter|CloudflareWorkersAi
    {
        return match (strtolower(trim($provider))) {
            'gemini' => $this->gemini,
            'groq' => $this->groq,
            'openrouter' => $this->openRouter,
            'cloudflare' => $this->cloudflare,
            default => throw new \InvalidArgumentException(sprintf('Unknown LLM provider: %s', $provider)),
        };
    }

    /**
     * @return list<string>
     */
    private static function parseProviderNames(string $providersCsv): array
    {
        $raw = trim($providersCsv);
        if ($raw === '') {
            return ['groq'];
        }

        $names = [];
        foreach (explode(',', $raw) as $part) {
            $name = strtolower(trim($part));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names !== [] ? $names : ['groq'];
    }
}
