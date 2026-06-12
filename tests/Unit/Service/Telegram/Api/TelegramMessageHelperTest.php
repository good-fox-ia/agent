<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Api;

use App\Enum\TelegramBotCommand;
use App\Enum\TelegramBotCommandScope;
use App\Service\Telegram\Api\TelegramMessageHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramMessageHelperTest extends TestCase
{
    #[DataProvider('provideChatTypes')]
    public function testIsGroup(?string $chatType, bool $expected): void
    {
        $message = $chatType === null ? [] : ['chat' => ['type' => $chatType]];

        self::assertSame($expected, TelegramMessageHelper::isGroup($message));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function provideChatTypes(): iterable
    {
        yield 'group' => ['group', true];
        yield 'supergroup' => ['supergroup', true];
        yield 'private' => ['private', false];
        yield 'channel' => ['channel', false];
        yield 'без chat.type — приватний' => [null, false];
    }

    public function testCommandScope(): void
    {
        self::assertSame(
            TelegramBotCommandScope::GROUP,
            TelegramMessageHelper::commandScope(['chat' => ['type' => 'group']]),
        );
        self::assertSame(
            TelegramBotCommandScope::PRIVATE,
            TelegramMessageHelper::commandScope(['chat' => ['type' => 'private']]),
        );
    }

    public function testVisibleTextBodyPrefersTextOverCaption(): void
    {
        self::assertSame('текст', TelegramMessageHelper::visibleTextBody([
            'text' => '  текст  ',
            'caption' => 'підпис',
        ]));
    }

    public function testVisibleTextBodyFallsBackToCaption(): void
    {
        self::assertSame('підпис', TelegramMessageHelper::visibleTextBody(['caption' => ' підпис ']));
    }

    public function testVisibleTextBodyEmptyWhenNothingPresent(): void
    {
        self::assertSame('', TelegramMessageHelper::visibleTextBody(['photo' => []]));
    }

    public function testResolveMediaAttachmentPicksLargestPhoto(): void
    {
        $attachment = TelegramMessageHelper::resolveMediaAttachment([
            'photo' => [
                ['file_id' => 'small', 'file_size' => 100],
                ['file_id' => 'large', 'file_size' => 5000],
            ],
        ]);

        self::assertSame(
            ['type' => 'photo', 'file_id' => 'large', 'file_size' => 5000, 'file_name' => null],
            $attachment,
        );
    }

    public function testResolveMediaAttachmentForDocumentWithFileName(): void
    {
        $attachment = TelegramMessageHelper::resolveMediaAttachment([
            'document' => [
                'file_id' => 'doc1',
                'file_size' => 1234,
                'file_name' => 'dir/report.pdf',
            ],
        ]);

        self::assertSame(
            ['type' => 'document', 'file_id' => 'doc1', 'file_size' => 1234, 'file_name' => 'report.pdf'],
            $attachment,
        );
    }

    public function testResolveMediaAttachmentForVideo(): void
    {
        $attachment = TelegramMessageHelper::resolveMediaAttachment([
            'video' => ['file_id' => 'vid1'],
        ]);

        self::assertNotNull($attachment);
        self::assertSame('video', $attachment['type']);
        self::assertSame('vid1', $attachment['file_id']);
        self::assertNull($attachment['file_size']);
    }

    public function testResolveMediaAttachmentReturnsNullWithoutMedia(): void
    {
        self::assertNull(TelegramMessageHelper::resolveMediaAttachment(['text' => 'просто текст']));
        self::assertFalse(TelegramMessageHelper::hasMediaAttachment(['text' => 'просто текст']));
    }

    public function testExtractUrlsFromPlainText(): void
    {
        $urls = TelegramMessageHelper::extractUrls([
            'text' => 'Дивись https://example.com/page. І ще http://test.org/x?a=1!',
        ]);

        self::assertSame(['https://example.com/page', 'http://test.org/x?a=1'], $urls);
    }

    public function testExtractUrlsFromTextLinkEntity(): void
    {
        $urls = TelegramMessageHelper::extractUrls([
            'text' => 'клікни тут',
            'entities' => [
                ['type' => 'text_link', 'url' => 'https://hidden.example.com/'],
            ],
        ]);

        self::assertSame(['https://hidden.example.com/'], $urls);
    }

    public function testExtractUrlsFromUrlEntityOffsets(): void
    {
        $text = 'іди на example.com зараз';
        $urls = TelegramMessageHelper::extractUrls([
            'text' => $text,
            'entities' => [
                ['type' => 'url', 'offset' => 7, 'length' => 11],
            ],
        ]);

        self::assertSame(['example.com'], $urls);
    }

    public function testExtractUrlsDeduplicates(): void
    {
        $urls = TelegramMessageHelper::extractUrls([
            'text' => 'https://example.com і ще раз https://example.com',
        ]);

        self::assertSame(['https://example.com'], $urls);
    }

    public function testExtractUrlsUsesCaptionEntitiesForCaption(): void
    {
        $urls = TelegramMessageHelper::extractUrls([
            'caption' => 'фото з посиланням',
            'caption_entities' => [
                ['type' => 'text_link', 'url' => 'https://photo.example.com'],
            ],
        ]);

        self::assertSame(['https://photo.example.com'], $urls);
    }

    #[DataProvider('provideBotCommands')]
    public function testParseBotCommand(string $text, ?TelegramBotCommand $expected): void
    {
        self::assertSame($expected, TelegramMessageHelper::parseBotCommand(['text' => $text]));
    }

    /**
     * @return iterable<string, array{string, ?TelegramBotCommand}>
     */
    public static function provideBotCommands(): iterable
    {
        yield 'проста команда' => ['/start', TelegramBotCommand::START];
        yield 'команда з аргументами' => ['/voice alloy', TelegramBotCommand::VOICE];
        yield 'команда з @botname' => ['/help@vatra_group_bot', TelegramBotCommand::HELP];
        yield 'верхній регістр' => ['/START', TelegramBotCommand::START];
        yield 'невідома команда' => ['/unknown', null];
        yield 'не команда' => ['привіт', null];
        yield 'слеш без команди' => ['/', null];
        yield 'порожній текст' => ['', null];
    }

    public function testCommandArguments(): void
    {
        self::assertSame('alloy', TelegramMessageHelper::commandArguments(['text' => '/voice alloy']));
        self::assertSame('a b c', TelegramMessageHelper::commandArguments(['text' => '/cmd   a b c  ']));
        self::assertSame('', TelegramMessageHelper::commandArguments(['text' => '/voice']));
        self::assertSame('', TelegramMessageHelper::commandArguments(['text' => 'не команда']));
    }

    public function testWithVisibleTextBodyOverwritesTextOrCaption(): void
    {
        $withText = TelegramMessageHelper::withVisibleTextBody(['text' => 'старий'], 'новий');
        self::assertSame('новий', $withText['text']);

        $withCaption = TelegramMessageHelper::withVisibleTextBody(['caption' => 'старий'], 'новий');
        self::assertSame('новий', $withCaption['caption']);
        self::assertArrayNotHasKey('text', $withCaption);
    }

    public function testWithCommandTextAndArgs(): void
    {
        $message = TelegramMessageHelper::withCommandTextAndArgs(
            ['text' => 'щось'],
            TelegramBotCommand::VOICE,
            '  alloy  ',
        );

        self::assertSame('/voice alloy', $message['text']);

        $noArgs = TelegramMessageHelper::withCommandTextAndArgs(
            ['text' => 'щось'],
            TelegramBotCommand::VOICE,
            '   ',
        );

        self::assertSame('/voice', $noArgs['text']);
    }
}
