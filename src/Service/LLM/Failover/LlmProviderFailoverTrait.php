<?php

declare(strict_types=1);

namespace App\Service\LLM\Failover;

use App\Service\LLM\Client\Interface\AudioTranscriptionLLMInterface;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use Psr\Log\LoggerInterface;

trait LlmProviderFailoverTrait
{
    /**
     * @param list<object&TextLLMInterface|AudioTranscriptionLLMInterface> $providers
     */
    private function tryProviders(array $providers, callable $attempt, string $operation): mixed
    {
        $lastException = null;

        foreach ($providers as $provider) {
            if (!$provider->isConfigured()) {
                continue;
            }

            try {
                return $attempt($provider);
            } catch (\Throwable $e) {
                if (!$this->shouldFailoverToNextProvider($e)) {
                    throw $e;
                }

                $lastException = $e;
                $this->logProviderFailure($provider, $operation, $e);
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        throw new \RuntimeException(sprintf('No LLM providers are configured for %s.', $operation));
    }

    private function shouldFailoverToNextProvider(\Throwable $e): bool
    {
        if ($e instanceof \InvalidArgumentException) {
            $message = $e->getMessage();
            if (str_contains($message, 'yields no messages')) {
                return false;
            }
        }

        return true;
    }

    private function logProviderFailure(object $provider, string $operation, \Throwable $e): void
    {
        $logger = $this->logger ?? null;
        if ($logger === null) {
            return;
        }

        $logger->warning('LLM provider failed, trying next', [
            'provider' => $provider::class,
            'operation' => $operation,
            'exception' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }
}
