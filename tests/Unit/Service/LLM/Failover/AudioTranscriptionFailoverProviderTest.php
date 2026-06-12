<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LLM\Failover;

use App\Service\LLM\Client\Interface\AudioTranscriptionLLMInterface;
use App\Service\LLM\Failover\AudioTranscriptionFailoverProvider;
use PHPUnit\Framework\TestCase;

final class AudioTranscriptionFailoverProviderTest extends TestCase
{
    public function testFailsOverAndPassesArgumentsThrough(): void
    {
        $failing = $this->createStub(AudioTranscriptionLLMInterface::class);
        $failing->method('isConfigured')->willReturn(true);
        $failing->method('transcribeAudio')->willThrowException(new \RuntimeException('rate limit'));

        $working = $this->createMock(AudioTranscriptionLLMInterface::class);
        $working->method('isConfigured')->willReturn(true);
        $working->expects(self::once())
            ->method('transcribeAudio')
            ->with('binary-data', 'voice.ogg', ['language' => 'uk'])
            ->willReturn('розшифрований текст');

        $provider = new AudioTranscriptionFailoverProvider([$failing, $working]);

        self::assertSame(
            'розшифрований текст',
            $provider->transcribeAudio('binary-data', 'voice.ogg', ['language' => 'uk']),
        );
    }

    public function testThrowsWhenNoProvidersConfigured(): void
    {
        $provider = new AudioTranscriptionFailoverProvider([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No LLM providers are configured for audio transcription.');

        $provider->transcribeAudio('binary-data');
    }
}
