<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Agent;

use App\Document\Chat;
use App\Repository\UserRepository;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\Telegram\Agent\TelegramAgentOutboundSender;
use App\Service\Telegram\Chat\Content\ChatTitleGenerator;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use App\Service\Telegram\Voice\VoiceReplySender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TelegramAgentOutboundSenderTest extends TestCase
{
    public function testDeliverFormatsViaLlmAndSendsHtml(): void
    {
        $llm = $this->createMock(TextLLMInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->expects(self::once())
            ->method('complete')
            ->with(
                self::callback(static function (PromptDTO $prompt): bool {
                    self::assertSame([], $prompt->getTools());
                    self::assertCount(1, $prompt->getMessages());
                    self::assertSame('Привіт, світ!', $prompt->getMessages()[0]['content']);

                    return true;
                }),
                self::equalTo(['temperature' => 0.2]),
            )
            ->willReturn('<b>Привіт</b>, світ!');

        $messageSender = $this->createMock(UserMessageSender::class);
        $messageSender->expects(self::once())
            ->method('send')
            ->with(42, '<b>Привіт</b>, світ!', false)
            ->willReturn(['chat' => ['id' => 42], 'message_id' => 1, 'text' => '<b>Привіт</b>, світ!']);

        $persistence = $this->createMock(TelegramPersistenceService::class);
        $persistence->expects(self::once())->method('recordAgentOutboundFromTelegramSend');

        $chatTitleGenerator = $this->createMock(ChatTitleGenerator::class);
        $chatTitleGenerator->expects(self::once())->method('updateTitleIfNeeded');

        $sender = new TelegramAgentOutboundSender(
            $messageSender,
            $llm,
            $this->createMock(VoiceReplySender::class),
            $this->createMock(UserRepository::class),
            $persistence,
            new TelegramLlmInvocationContext(),
            $chatTitleGenerator,
            new NullLogger(),
        );

        $sender->deliver(42, false, new Chat(), 'Привіт, світ!', null);
    }

    public function testDeliverStripsMessageIdMarkersBeforeFormatting(): void
    {
        $llm = $this->createMock(TextLLMInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->expects(self::once())
            ->method('complete')
            ->with(
                self::callback(static function (PromptDTO $prompt): bool {
                    return $prompt->getMessages()[0]['content'] === 'Відповідь';
                }),
                self::anything(),
            )
            ->willReturn('Відповідь');

        $messageSender = $this->createMock(UserMessageSender::class);
        $messageSender->method('send')->willReturn(['chat' => ['id' => 1], 'message_id' => 2]);

        $sender = new TelegramAgentOutboundSender(
            $messageSender,
            $llm,
            $this->createMock(VoiceReplySender::class),
            $this->createMock(UserRepository::class),
            $this->createMock(TelegramPersistenceService::class),
            new TelegramLlmInvocationContext(),
            $this->createMock(ChatTitleGenerator::class),
            new NullLogger(),
        );

        $sender->deliver(1, false, new Chat(), '[#99 → #10] Відповідь', null);
    }
}
