<?php

declare(strict_types=1);

namespace App\Service\Telegram\Video;

use App\Repository\MessageRepository;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Завантажує соцмережеве відео за посиланням і надсилає його в Telegram-чат.
 */
final class TelegramSocialVideoHandler
{
    private const RETRY_DELAY_SECONDS = 3;

    private const TELEGRAM_CAPTION_MAX_LENGTH = 1024;

    /** TODO: тест sendVideo — постав true щоб слати test.mp4 замість yt-dlp */
    private const USE_HARDCODED_TEST_VIDEO = false;

    public function __construct(
        private readonly SocialVideoUrlMatcher $urlMatcher,
        private readonly SocialVideoDownloader $downloader,
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly MessageRepository $messages,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed>|null $telegramMessage
     */
    public function tryHandle(
        int $telegramChatId,
        int $triggerTelegramMessageId,
        ?array $telegramMessage,
        bool $isGroup = false,
    ): bool {
        if ($telegramMessage === null) {
            return false;
        }

        $url = $this->urlMatcher->extractSupportedUrlFromMessage($telegramMessage);
        if ($url === null) {
            return false;
        }

        $storedInbound = $this->messages->findOneByTelegramMessageIds($telegramChatId, $triggerTelegramMessageId);

        $localPath = null;
        $caption = null;
        $skipCleanup = false;

        try {
            $this->telegram->sendChatAction($telegramChatId, 'typing');
            [$localPath, $caption] = $this->downloadWithRetry($url, $skipCleanup, $telegramChatId);

            $this->telegram->sendChatAction($telegramChatId, 'upload_video');
            $videoOptions = ['reply_to_message_id' => $triggerTelegramMessageId];
            if ($caption !== null && $caption !== '') {
                $videoOptions['caption'] = $this->formatCaptionAsBlockquote($caption);
                $videoOptions['parse_mode'] = 'HTML';
            }
            $sent = $this->telegram->sendVideo($telegramChatId, $localPath, $videoOptions);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);
        } catch (\Throwable $e) {
            $this->logger->error('Social video failed chat={chat} url={url}: {error}', [
                'chat' => $telegramChatId,
                'url' => $url,
                'error' => $e->getMessage(),
            ], $e);

            try {
                $sent = $this->messageSender->send(
                    $telegramChatId,
                    'Не вдалося завантажити відео.',
                    $isGroup,
                    [
                        'reply_to_message_id' => $triggerTelegramMessageId,
                        'link_preview_options' => ['is_disabled' => true],
                    ],
                );
                $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);
            } catch (\Throwable) {
            }
        } finally {
            if ($localPath !== null && !$skipCleanup) {
                $this->downloader->removeDownloadedFile($localPath);
            }
        }

        return true;
    }

    /**
     * @return array{0: string, 1: ?string} [path, caption]
     */
    private function downloadWithRetry(string $url, bool &$skipCleanup, int $chatId): array
    {
        $lastError = null;
        $heartbeat = fn (): mixed => $this->telegram->sendChatAction($chatId, 'typing');

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            if ($attempt > 1) {
                sleep(self::RETRY_DELAY_SECONDS);
                $this->telegram->sendChatAction($chatId, 'typing');
            }

            try {
                if (self::USE_HARDCODED_TEST_VIDEO) {
                    $path = $this->testVideoPath();
                    if (!is_readable($path)) {
                        throw new \RuntimeException('Тестовий файл не знайдено: '.$path);
                    }
                    $skipCleanup = true;

                    return [$path, null];
                }

                $result = $this->downloader->download($url, $heartbeat);

                return [$result->path, $result->caption];
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('Не вдалося завантажити відео.');
    }

    private function testVideoPath(): string
    {
        return dirname(__DIR__, 4).'/test.mp4';
    }

    private function formatCaptionAsBlockquote(string $caption): string
    {
        $open = '<blockquote expandable>';
        $close = '</blockquote>';
        $maxPlain = self::TELEGRAM_CAPTION_MAX_LENGTH - strlen($open) - strlen($close);
        if (mb_strlen($caption) > $maxPlain) {
            $caption = mb_substr($caption, 0, $maxPlain - 1).'…';
        }

        return $open.htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8').$close;
    }
}
