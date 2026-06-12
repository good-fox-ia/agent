<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Chat;

use App\Document\Message;
use App\Enum\MessageType;
use App\Repository\MessageRepository;
use App\Service\Telegram\Chat\GroupBotAddressChecker;
use PHPUnit\Framework\TestCase;

final class GroupBotAddressCheckerTest extends TestCase
{
    private const CHAT_ID = -1000;

    public function testMentionInTextAddressesBot(): void
    {
        $checker = new GroupBotAddressChecker($this->repositoryReturning(null));

        self::assertTrue($checker->isAddressedToBot(self::CHAT_ID, [
            'text' => 'Привіт, ' . GroupBotAddressChecker::BOT_MENTION . ', як справи?',
        ]));
    }

    public function testMentionIsCaseInsensitive(): void
    {
        $checker = new GroupBotAddressChecker($this->repositoryReturning(null));

        self::assertTrue($checker->hasBotMention([
            'text' => strtoupper(GroupBotAddressChecker::BOT_MENTION) . ' допоможи',
        ]));
    }

    public function testMentionInCaptionAddressesBot(): void
    {
        $checker = new GroupBotAddressChecker($this->repositoryReturning(null));

        self::assertTrue($checker->hasBotMention([
            'caption' => 'Глянь фото ' . GroupBotAddressChecker::BOT_MENTION,
        ]));
    }

    public function testPlainMessageWithoutMentionOrReplyIsNotAddressed(): void
    {
        $checker = new GroupBotAddressChecker($this->repositoryReturning(null));

        self::assertFalse($checker->isAddressedToBot(self::CHAT_ID, ['text' => 'просто повідомлення']));
    }

    public function testReplyToKnownAgentGroupMessageAddressesBot(): void
    {
        $stored = new Message(self::CHAT_ID, 555, MessageType::AgentGroup);
        $checker = new GroupBotAddressChecker($this->repositoryReturning($stored));

        self::assertTrue($checker->isAddressedToBot(self::CHAT_ID, [
            'text' => 'відповідь без згадки',
            'reply_to_message' => ['message_id' => 555],
        ]));
    }

    public function testReplyToKnownUserMessageDoesNotAddressBot(): void
    {
        $stored = new Message(self::CHAT_ID, 555, MessageType::UserGroup);
        $checker = new GroupBotAddressChecker($this->repositoryReturning($stored));

        self::assertFalse($checker->isAddressedToBot(self::CHAT_ID, [
            'text' => 'відповідь людині',
            'reply_to_message' => ['message_id' => 555],
        ]));
    }

    public function testReplyToUnknownMessageFallsBackToIsBotFlag(): void
    {
        $checker = new GroupBotAddressChecker($this->repositoryReturning(null));

        self::assertTrue($checker->isReplyToBotMessage(self::CHAT_ID, [
            'reply_to_message' => [
                'message_id' => 999,
                'from' => ['id' => 1, 'is_bot' => true],
            ],
        ]));

        self::assertFalse($checker->isReplyToBotMessage(self::CHAT_ID, [
            'reply_to_message' => [
                'message_id' => 999,
                'from' => ['id' => 1, 'is_bot' => false],
            ],
        ]));
    }

    public function testReplyWithoutMessageIdIsNotAddressed(): void
    {
        $checker = new GroupBotAddressChecker($this->repositoryReturning(null));

        self::assertFalse($checker->isReplyToBotMessage(self::CHAT_ID, [
            'reply_to_message' => ['from' => ['is_bot' => true]],
        ]));
    }

    private function repositoryReturning(?Message $message): MessageRepository
    {
        $repository = $this->createStub(MessageRepository::class);
        $repository->method('findOneByTelegramMessageIds')->willReturn($message);

        return $repository;
    }
}
