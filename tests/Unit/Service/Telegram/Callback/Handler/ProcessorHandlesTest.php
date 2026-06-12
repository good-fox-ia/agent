<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Callback\Handler;

use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Agent\TelegramAgentLlmReplySender;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Callback\Handler\AskQuestionAnswerProcessor;
use App\Service\Telegram\Callback\Handler\HistoryProcessor;
use App\Service\Telegram\Callback\Handler\SelectChatProcessor;
use App\Service\Telegram\Callback\Handler\SelectVoiceProcessor;
use App\Service\Telegram\Chat\Action\SwitchChatAction;
use App\Service\Telegram\Chat\Content\ChatHistoryFormatter;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Контракт callback-префіксів: хто генерує кнопки і хто їх обробляє,
 * мають збігатися. Зміна префікса без міграції зламає старі кнопки.
 */
final class ProcessorHandlesTest extends TestCase
{
    public function testSelectChatHandlesOnlyItsPrefix(): void
    {
        $processor = new SelectChatProcessor(
            $this->createStub(UserRepository::class),
            $this->createStub(ChatRepository::class),
            $this->createStub(SwitchChatAction::class),
            $this->createStub(TelegramService::class),
            $this->createStub(DocumentManager::class),
            $this->createStub(LoggerInterface::class),
        );

        self::assertTrue($processor->handles('sc:abc'));
        self::assertFalse($processor->handles('sh:abc'));
        self::assertFalse($processor->handles('tv:alloy'));
        self::assertFalse($processor->handles('aq:0'));
        self::assertFalse($processor->handles(''));
    }

    public function testSelectVoiceHandlesOnlyItsPrefix(): void
    {
        $processor = new SelectVoiceProcessor(
            $this->createStub(UserRepository::class),
            $this->createStub(TelegramService::class),
            $this->createStub(DocumentManager::class),
            $this->createStub(LoggerInterface::class),
        );

        self::assertTrue($processor->handles('tv:alloy'));
        self::assertFalse($processor->handles('sc:abc'));
        self::assertFalse($processor->handles(''));
    }

    public function testHistoryHandlesOnlyItsPrefix(): void
    {
        $processor = new HistoryProcessor(
            $this->createStub(UserRepository::class),
            $this->createStub(ChatRepository::class),
            $this->createStub(ChatHistoryFormatter::class),
            $this->createStub(TelegramService::class),
            $this->createStub(UserMessageSender::class),
            $this->createStub(TelegramPersistenceService::class),
            $this->createStub(DocumentManager::class),
            $this->createStub(LoggerInterface::class),
        );

        self::assertTrue($processor->handles('sh:abc'));
        self::assertFalse($processor->handles('sc:abc'));
        self::assertFalse($processor->handles(''));
    }

    public function testAskQuestionAnswerHandlesOnlyItsPrefix(): void
    {
        $processor = new AskQuestionAnswerProcessor(
            $this->createStub(TelegramService::class),
            $this->createStub(TelegramPersistenceService::class),
            $this->createStub(TelegramAgentLlmReplySender::class),
            $this->createStub(LoggerInterface::class),
        );

        self::assertTrue($processor->handles('aq:1'));
        self::assertFalse($processor->handles('tv:alloy'));
        self::assertFalse($processor->handles(''));
    }
}
