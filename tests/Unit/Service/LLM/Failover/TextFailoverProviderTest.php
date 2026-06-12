<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LLM\Failover;

use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Failover\TextFailoverProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TextFailoverProviderTest extends TestCase
{
    public function testReturnsResultFromFirstConfiguredProvider(): void
    {
        $provider = new TextFailoverProvider([
            $this->workingProvider('перша відповідь'),
            $this->workingProvider('друга відповідь'),
        ]);

        self::assertSame('перша відповідь', $provider->complete($this->prompt()));
    }

    public function testSkipsUnconfiguredProviders(): void
    {
        $provider = new TextFailoverProvider([
            $this->unconfiguredProvider(),
            $this->workingProvider('відповідь'),
        ]);

        self::assertSame('відповідь', $provider->complete($this->prompt()));
    }

    public function testFailsOverToNextProviderOnError(): void
    {
        $failing = $this->failingProvider(new \RuntimeException('API down'));

        $provider = new TextFailoverProvider([
            $failing,
            $this->workingProvider('запасна відповідь'),
        ]);

        self::assertSame('запасна відповідь', $provider->complete($this->prompt()));
        self::assertSame(1, $failing->calls);
    }

    public function testLogsWarningOnProviderFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $provider = new TextFailoverProvider(
            [
                $this->failingProvider(new \RuntimeException('boom')),
                $this->workingProvider('ok'),
            ],
            $logger,
        );

        $provider->complete($this->prompt());
    }

    public function testRethrowsLastExceptionWhenAllProvidersFail(): void
    {
        $provider = new TextFailoverProvider([
            $this->failingProvider(new \RuntimeException('перша помилка')),
            $this->failingProvider(new \RuntimeException('остання помилка')),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('остання помилка');

        $provider->complete($this->prompt());
    }

    public function testThrowsWhenNoProvidersConfigured(): void
    {
        $provider = new TextFailoverProvider([$this->unconfiguredProvider()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No LLM providers are configured');

        $provider->complete($this->prompt());
    }

    public function testEmptyPromptErrorIsNotFailedOver(): void
    {
        $second = $this->workingProvider('не повинно викликатись');

        $provider = new TextFailoverProvider([
            $this->failingProvider(new \InvalidArgumentException('PromptDTO yields no messages for the model.')),
            $second,
        ]);

        try {
            $provider->complete($this->prompt());
            self::fail('Очікувався InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // помилка промпту однакова для всіх провайдерів — failover безглуздий
        }

        self::assertSame(0, $second->calls);
    }

    public function testIsConfiguredWhenAtLeastOneProviderConfigured(): void
    {
        $configured = new TextFailoverProvider([
            $this->unconfiguredProvider(),
            $this->workingProvider('x'),
        ]);
        $unconfigured = new TextFailoverProvider([$this->unconfiguredProvider()]);
        $empty = new TextFailoverProvider([]);

        self::assertTrue($configured->isConfigured());
        self::assertFalse($unconfigured->isConfigured());
        self::assertFalse($empty->isConfigured());
    }

    private function prompt(): PromptDTO
    {
        return new PromptDTO(messages: [['role' => 'user', 'content' => 'Привіт']], tools: []);
    }

    /**
     * @return TextLLMInterface&object{calls: int}
     */
    private function workingProvider(string $response): TextLLMInterface
    {
        return new class($response) implements TextLLMInterface {
            public int $calls = 0;

            public function __construct(private readonly string $response) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(PromptDTO $prompt, array $options = []): string
            {
                ++$this->calls;

                return $this->response;
            }
        };
    }

    /**
     * @return TextLLMInterface&object{calls: int}
     */
    private function failingProvider(\Throwable $exception): TextLLMInterface
    {
        return new class($exception) implements TextLLMInterface {
            public int $calls = 0;

            public function __construct(private readonly \Throwable $exception) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(PromptDTO $prompt, array $options = []): string
            {
                ++$this->calls;

                throw $this->exception;
            }
        };
    }

    private function unconfiguredProvider(): TextLLMInterface
    {
        return new class implements TextLLMInterface {
            public function isConfigured(): bool
            {
                return false;
            }

            public function complete(PromptDTO $prompt, array $options = []): string
            {
                throw new \LogicException('Не мав викликатись');
            }
        };
    }
}
