<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Callback;

use App\Service\Telegram\Callback\CallbackDTO;
use App\Service\Telegram\Callback\Dispatcher;
use App\Service\Telegram\Callback\Handler\AskQuestionAnswerProcessor;
use App\Service\Telegram\Callback\Handler\HistoryProcessor;
use App\Service\Telegram\Callback\Handler\SelectChatProcessor;
use App\Service\Telegram\Callback\Handler\SelectVoiceProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DispatcherTest extends TestCase
{
    private SelectChatProcessor&MockObject $selectChat;
    private HistoryProcessor&MockObject $history;
    private SelectVoiceProcessor&MockObject $selectVoice;
    private AskQuestionAnswerProcessor&MockObject $askQuestionAnswer;

    protected function setUp(): void
    {
        $this->selectChat = $this->createMock(SelectChatProcessor::class);
        $this->history = $this->createMock(HistoryProcessor::class);
        $this->selectVoice = $this->createMock(SelectVoiceProcessor::class);
        $this->askQuestionAnswer = $this->createMock(AskQuestionAnswerProcessor::class);

        // handles() мокаються згідно з реальними префіксами callback_data
        $this->selectChat->method('handles')
            ->willReturnCallback(static fn (string $data): bool => str_starts_with($data, 'sc:'));
        $this->history->method('handles')
            ->willReturnCallback(static fn (string $data): bool => str_starts_with($data, 'sh:'));
        $this->selectVoice->method('handles')
            ->willReturnCallback(static fn (string $data): bool => str_starts_with($data, 'tv:'));
        $this->askQuestionAnswer->method('handles')
            ->willReturnCallback(static fn (string $data): bool => str_starts_with($data, 'aq:'));
    }

    private function buildDispatcher(LoggerInterface $logger): Dispatcher
    {
        return new Dispatcher(
            $this->selectChat,
            $this->history,
            $this->selectVoice,
            $this->askQuestionAnswer,
            $logger,
        );
    }

    #[DataProvider('provideRouting')]
    public function testRoutesCallbackToCorrectProcessor(string $data, string $expectedProcessor): void
    {
        $processors = [
            'selectChat' => $this->selectChat,
            'history' => $this->history,
            'selectVoice' => $this->selectVoice,
            'askQuestionAnswer' => $this->askQuestionAnswer,
        ];

        foreach ($processors as $name => $processor) {
            $processor->expects($name === $expectedProcessor ? self::once() : self::never())
                ->method('process');
        }

        $this->buildDispatcher(new NullLogger())->dispatch($this->makeCallback($data));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRouting(): iterable
    {
        yield 'вибір бесіди' => ['sc:abc123', 'selectChat'];
        yield 'історія бесіди' => ['sh:abc123', 'history'];
        yield 'вибір голосу' => ['tv:alloy', 'selectVoice'];
        yield 'відповідь на питання' => ['aq:0', 'askQuestionAnswer'];
    }

    public function testUnknownCallbackIsLoggedAndNotProcessed(): void
    {
        $this->selectChat->expects(self::never())->method('process');
        $this->history->expects(self::never())->method('process');
        $this->selectVoice->expects(self::never())->method('process');
        $this->askQuestionAnswer->expects(self::never())->method('process');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $this->buildDispatcher($logger)->dispatch($this->makeCallback('unknown:data'));
    }

    private function makeCallback(string $data): CallbackDTO
    {
        $dto = CallbackDTO::buildFromArray([
            'id' => 'cb1',
            'data' => $data,
            'from' => ['id' => 42],
            'message' => ['chat' => ['id' => 100], 'message_id' => 7],
        ]);

        self::assertNotNull($dto);

        return $dto;
    }
}
