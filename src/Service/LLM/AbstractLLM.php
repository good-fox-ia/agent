<?php

namespace App\Service\LLM;

use App\Service\Http\Client;
use App\Service\LLM\Adapter\PromptAdapterInterface;

abstract class AbstractLLM implements LLMInterface
{
    public function __construct(
        protected readonly string $apiKey,
        private readonly Client $httpClient,
        protected readonly PromptAdapterInterface $promptAdapter
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    protected function post(string $url, array $body, array $headers = []): array
    {
        return $this->httpClient->post($url, $body, $headers);
    }

    protected function postMultipart(string $url, array $body, array $headers = []): array
    {
        return $this->httpClient->postMultipart($url, $body, $headers);
    }
}
