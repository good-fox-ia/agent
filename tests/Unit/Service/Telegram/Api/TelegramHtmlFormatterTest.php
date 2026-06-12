<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Api;

use App\Service\Telegram\Api\TelegramHtmlFormatter;
use PHPUnit\Framework\TestCase;

final class TelegramHtmlFormatterTest extends TestCase
{
    public function testWrapExpandableBlockquote(): void
    {
        self::assertSame(
            '<blockquote expandable><b>Привіт</b></blockquote>',
            TelegramHtmlFormatter::wrapExpandableBlockquote('<b>Привіт</b>'),
        );
    }

    public function testWrapExpandableBlockquoteTruncatesLongHtml(): void
    {
        $html = str_repeat('а', 4096);
        $wrapped = TelegramHtmlFormatter::wrapExpandableBlockquote($html);

        self::assertLessThanOrEqual(4096, mb_strlen($wrapped));
        self::assertStringStartsWith('<blockquote expandable>', $wrapped);
        self::assertStringEndsWith('…</blockquote>', $wrapped);
    }

    public function testFormatPlainTextAsExpandableBlockquoteEscapesHtml(): void
    {
        self::assertSame(
            '<blockquote expandable>a &lt; b &amp; c</blockquote>',
            TelegramHtmlFormatter::formatPlainTextAsExpandableBlockquote('a < b & c'),
        );
    }

    public function testFormatPlainTextAsExpandableBlockquoteTruncates(): void
    {
        $text = str_repeat('б', 5000);
        $formatted = TelegramHtmlFormatter::formatPlainTextAsExpandableBlockquote($text, 100);

        self::assertLessThanOrEqual(100, mb_strlen($formatted));
        self::assertStringEndsWith('…</blockquote>', $formatted);
    }
}
