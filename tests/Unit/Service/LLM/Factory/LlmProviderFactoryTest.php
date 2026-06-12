<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LLM\Factory;

use App\Service\LLM\Client\CloudflareWorkersAi;
use App\Service\LLM\Client\Gemini;
use App\Service\LLM\Client\Groq;
use App\Service\LLM\Client\OpenRouter;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Factory\LlmProviderFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// у setUp мокаються всі 4 провайдери, але кожен тест використовує лише частину
#[AllowMockObjectsWithoutExpectations]
final class LlmProviderFactoryTest extends TestCase
{
    private Groq&MockObject $groq;
    private Gemini&MockObject $gemini;
    private OpenRouter&MockObject $openRouter;
    private CloudflareWorkersAi&MockObject $cloudflare;

    protected function setUp(): void
    {
        $this->groq = $this->createMock(Groq::class);
        $this->gemini = $this->createMock(Gemini::class);
        $this->openRouter = $this->createMock(OpenRouter::class);
        $this->cloudflare = $this->createMock(CloudflareWorkersAi::class);
    }

    public function testTextLlmRespectsCsvOrder(): void
    {
        $this->gemini->method('isConfigured')->willReturn(true);
        $this->gemini->expects(self::once())->method('complete')->willReturn('from-gemini');
        $this->groq->expects(self::never())->method('complete');

        $factory = $this->factory(textProvidersCsv: 'gemini, groq');

        self::assertSame('from-gemini', $factory->createTextLlm()->complete($this->prompt()));
    }

    public function testTextLlmFailsOverAccordingToCsvOrder(): void
    {
        $this->gemini->method('isConfigured')->willReturn(true);
        $this->gemini->method('complete')->willThrowException(new \RuntimeException('gemini down'));
        $this->groq->method('isConfigured')->willReturn(true);
        $this->groq->expects(self::once())->method('complete')->willReturn('from-groq');

        $factory = $this->factory(textProvidersCsv: 'gemini,groq');

        self::assertSame('from-groq', $factory->createTextLlm()->complete($this->prompt()));
    }

    public function testEmptyCsvDefaultsToGroq(): void
    {
        $this->groq->method('isConfigured')->willReturn(true);
        $this->groq->expects(self::once())->method('complete')->willReturn('from-groq');

        $factory = $this->factory(textProvidersCsv: '');

        self::assertSame('from-groq', $factory->createTextLlm()->complete($this->prompt()));
    }

    public function testDuplicatedProvidersInCsvAreTriedOnlyOnce(): void
    {
        $this->groq->method('isConfigured')->willReturn(true);
        $this->groq->expects(self::once())
            ->method('complete')
            ->willThrowException(new \RuntimeException('boom'));

        $factory = $this->factory(textProvidersCsv: 'groq, GROQ ,groq');

        $this->expectException(\RuntimeException::class);

        $factory->createTextLlm()->complete($this->prompt());
    }

    public function testUnknownProviderNameThrows(): void
    {
        $factory = $this->factory(textProvidersCsv: 'chatgpt');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown LLM provider: chatgpt');

        $factory->createTextLlm();
    }

    public function testAudioTranscriptionUsesAudioCsv(): void
    {
        $this->groq->method('isConfigured')->willReturn(true);
        $this->groq->expects(self::once())
            ->method('transcribeAudio')
            ->willReturn('текст з аудіо');

        $factory = $this->factory(audioProvidersCsv: 'groq');

        self::assertSame('текст з аудіо', $factory->createAudioTranscriptionLlm()->transcribeAudio('binary'));
    }

    private function factory(
        string $textProvidersCsv = 'groq',
        string $audioProvidersCsv = 'groq',
    ): LlmProviderFactory {
        return new LlmProviderFactory(
            $this->groq,
            $this->gemini,
            $this->openRouter,
            $this->cloudflare,
            $textProvidersCsv,
            $audioProvidersCsv,
        );
    }

    private function prompt(): PromptDTO
    {
        return new PromptDTO(messages: [['role' => 'user', 'content' => 'x']], tools: []);
    }
}
