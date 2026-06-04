<?php

declare(strict_types=1);

namespace App\Service\LLM\Factory;

use App\Service\LLM\Client\Gemini;
use App\Service\LLM\Client\Groq;
use App\Service\LLM\Failover\AudioTranscriptionFailoverProvider;
use App\Service\LLM\Failover\TextFailoverProvider;
use App\Service\LLM\Client\Interface\AudioTranscriptionLLMInterface;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use Psr\Log\LoggerInterface;

final class LlmProviderFactory
{
    /** @var list<string> */
    private readonly array $textProviderNames;

    /** @var list<string> */
    private readonly array $audioProviderNames;

    public function __construct(
        private readonly Groq $groq,
        private readonly Gemini $gemini,
        private readonly string $textProvidersCsv,
        private readonly string $audioProvidersCsv,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->textProviderNames = self::parseProviderNames($textProvidersCsv);
        $this->audioProviderNames = self::parseProviderNames($audioProvidersCsv);
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

    /**
     * @param list<string> $names
     *
     * @return list<Groq|Gemini>
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

    private function resolve(string $provider): Groq|Gemini
    {
        return match (strtolower(trim($provider))) {
            'gemini' => $this->gemini,
            'groq' => $this->groq,
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
