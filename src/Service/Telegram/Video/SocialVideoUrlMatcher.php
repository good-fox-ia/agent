<?php

declare(strict_types=1);

namespace App\Service\Telegram\Video;

use App\Service\Telegram\Api\TelegramMessageHelper;

/**
 * Визначає посилання на відео TikTok, Instagram Reels, YouTube Shorts або пости Threads у тексті повідомлення.
 */
final class SocialVideoUrlMatcher
{
    /**
     * @param array<string, mixed> $telegramMessage
     */
    public function extractSupportedUrlFromMessage(array $telegramMessage): ?string
    {
        foreach (TelegramMessageHelper::extractUrls($telegramMessage) as $url) {
            $normalized = $this->normalizeUrl($url);
            if ($this->isSupported($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    public function extractSupportedUrl(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        if (!preg_match_all('#(?:https?://)?(?:www\.)?(?:[a-z0-9-]+\.)*(?:tiktok\.com|instagram\.com|youtube\.com|threads\.(?:com|net))[^\s<>"\'\)\]]*#i', $text, $matches)) {
            return null;
        }

        foreach ($matches[0] as $url) {
            $normalized = $this->normalizeUrl(rtrim($url, '.,;:!?'));
            if ($this->isSupported($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeUrl(string $url): string
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $url;
        }

        $host = strtolower((string) $parts['host']);
        if (!str_contains($host, 'youtube.com') && !str_contains($host, 'youtu.be')) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $path = $parts['path'] ?? '';
        $normalizedHost = str_contains($host, 'youtube.com') ? 'www.youtube.com' : $host;

        return $scheme.'://'.$normalizedHost.$path;
    }

    public function isSupported(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        $path = strtolower($path);

        if (str_contains($host, 'tiktok.com')) {
            return true;
        }

        if (str_contains($host, 'instagram.com')
            && (str_contains($path, '/reel') || str_contains($path, '/p/') || str_contains($path, '/tv/'))) {
            return true;
        }

        if (str_contains($host, 'youtube.com') && str_contains($path, '/shorts/')) {
            return true;
        }

        if ((str_contains($host, 'threads.com') || str_contains($host, 'threads.net'))
            && (str_contains($path, '/post/') || str_starts_with($path, '/t/'))) {
            return true;
        }

        return false;
    }
}
