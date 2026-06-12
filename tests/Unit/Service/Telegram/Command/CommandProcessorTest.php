<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Service\Telegram\Command\CommandProcessInterface;
use App\Service\Telegram\Command\CommandProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CommandProcessorTest extends TestCase
{
    public function testReturnsFalseForNonCommandText(): void
    {
        $process = $this->recordingProcess(TelegramBotCommand::HELP);
        $processor = new CommandProcessor([$process], new NullLogger());

        $handled = $processor->tryProcess($this->privateMessage('просто текст'), null);

        self::assertFalse($handled);
        self::assertSame([], $process->processed);
    }

    public function testDispatchesCommandToMatchingProcess(): void
    {
        $help = $this->recordingProcess(TelegramBotCommand::HELP);
        $start = $this->recordingProcess(TelegramBotCommand::START);
        $processor = new CommandProcessor([$start, $help], new NullLogger());

        $handled = $processor->tryProcess($this->privateMessage('/help'), null);

        self::assertTrue($handled);
        self::assertCount(1, $help->processed);
        self::assertSame([], $start->processed);
    }

    public function testCommandWithBotMentionAndArgumentsIsParsed(): void
    {
        $voice = $this->recordingProcess(TelegramBotCommand::VOICE);
        $processor = new CommandProcessor([$voice], new NullLogger());

        $handled = $processor->tryProcess($this->privateMessage('/voice@vatra_group_bot alloy'), null);

        self::assertTrue($handled);
        self::assertCount(1, $voice->processed);
    }

    public function testPrivateOnlyCommandInGroupIsSwallowedWithoutProcessing(): void
    {
        $start = $this->recordingProcess(TelegramBotCommand::START);
        $processor = new CommandProcessor([$start], new NullLogger());

        // /start доступна лише в приватному чаті — у групі ігнорується, але вважається обробленою
        $handled = $processor->tryProcess($this->groupMessage('/start'), null);

        self::assertTrue($handled);
        self::assertSame([], $start->processed);
    }

    public function testGroupAvailableCommandIsProcessedInGroup(): void
    {
        $voice = $this->recordingProcess(TelegramBotCommand::VOICE);
        $processor = new CommandProcessor([$voice], new NullLogger());

        $handled = $processor->tryProcess($this->groupMessage('/voice'), null);

        self::assertTrue($handled);
        self::assertCount(1, $voice->processed);
    }

    public function testReturnsFalseWhenNoProcessHandlesCommand(): void
    {
        $help = $this->recordingProcess(TelegramBotCommand::HELP);
        $processor = new CommandProcessor([$help], new NullLogger());

        $handled = $processor->tryProcess($this->privateMessage('/newchat'), null);

        self::assertFalse($handled);
        self::assertSame([], $help->processed);
    }

    public function testInboundMessageIsPassedToProcess(): void
    {
        $help = $this->recordingProcess(TelegramBotCommand::HELP);
        $processor = new CommandProcessor([$help], new NullLogger());
        $inbound = new Message(1, 2, \App\Enum\MessageType::UserPrivate);

        $processor->tryProcess($this->privateMessage('/help'), $inbound);

        self::assertSame($inbound, $help->processed[0]['inbound']);
    }

    /**
     * @return CommandProcessInterface&object{processed: list<array{message: array, inbound: ?Message}>}
     */
    private function recordingProcess(TelegramBotCommand $command): CommandProcessInterface
    {
        return new class($command) implements CommandProcessInterface {
            /** @var list<array{message: array, inbound: ?Message}> */
            public array $processed = [];

            public function __construct(private readonly TelegramBotCommand $command) {}

            public function handles(TelegramBotCommand $command): bool
            {
                return $command === $this->command;
            }

            public function onProcess(array $telegramMessage, ?Message $inbound): void
            {
                $this->processed[] = ['message' => $telegramMessage, 'inbound' => $inbound];
            }
        };
    }

    private function privateMessage(string $text): array
    {
        return [
            'text' => $text,
            'chat' => ['id' => 100, 'type' => 'private'],
        ];
    }

    private function groupMessage(string $text): array
    {
        return [
            'text' => $text,
            'chat' => ['id' => -200, 'type' => 'group'],
        ];
    }
}
