<?php

declare(strict_types=1);

namespace App\Service\Telegram\Api;

/**
 * HTML-форматування тексту для Telegram (parse_mode HTML).
 */
final class TelegramHtmlFormatter
{
    private const BLOCKQUOTE_OPEN = '<blockquote expandable>';

    private const BLOCKQUOTE_CLOSE = '</blockquote>';

    private function __construct() {}

    public static function wrapExpandableBlockquote(string $html, int $maxLength = 4096): string
    {
        $maxInner = $maxLength - strlen(self::BLOCKQUOTE_OPEN) - strlen(self::BLOCKQUOTE_CLOSE);
        if (mb_strlen($html) > $maxInner) {
            $html = mb_substr($html, 0, $maxInner - 1).'…';
        }

        return self::BLOCKQUOTE_OPEN.$html.self::BLOCKQUOTE_CLOSE;
    }

    public static function formatPlainTextAsExpandableBlockquote(string $text, int $maxLength = 4096): string
    {
        $maxPlain = $maxLength - strlen(self::BLOCKQUOTE_OPEN) - strlen(self::BLOCKQUOTE_CLOSE);
        if (mb_strlen($text) > $maxPlain) {
            $text = mb_substr($text, 0, $maxPlain - 1).'…';
        }

        return self::BLOCKQUOTE_OPEN
            .htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            .self::BLOCKQUOTE_CLOSE;
    }
}
