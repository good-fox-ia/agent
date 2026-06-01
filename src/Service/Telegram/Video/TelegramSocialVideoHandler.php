<?php

declare(strict_types=1);

namespace App\Service\Telegram\Video;

use App\Repository\MessageRepository;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Завантажує соцмережеве відео або фото за посиланням і надсилає в Telegram-чат.
 */
final class TelegramSocialVideoHandler
{
    private const RETRY_DELAY_SECONDS = 3;

    private const TELEGRAM_CAPTION_MAX_LENGTH = 1024;

    private const TELEGRAM_MEDIA_GROUP_MAX = 10;

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
        $skipCleanup = false;

        try {
            $this->telegram->sendChatAction($telegramChatId, 'typing');
            $result = $this->downloadWithRetry($url, $skipCleanup, $telegramChatId);
            $localPath = $result->primaryPath();

            $sendOptions = ['reply_to_message_id' => $triggerTelegramMessageId];
            if ($result->caption !== null && $result->caption !== '') {
                $sendOptions['caption'] = $this->formatCaptionAsBlockquote($result->caption);
                $sendOptions['parse_mode'] = 'HTML';
            }

            if ($result->kind === SocialMediaKind::Photo) {
                $this->telegram->sendChatAction($telegramChatId, 'upload_photo');
                $photoPaths = array_slice($result->paths, 0, self::TELEGRAM_MEDIA_GROUP_MAX);
                $sent = $this->telegram->sendMediaGroup($telegramChatId, $photoPaths, $sendOptions);
            } else {
                $this->telegram->sendChatAction($telegramChatId, 'upload_video');
                $sent = $this->telegram->sendVideo($telegramChatId, $localPath, $sendOptions);
            }

            $this->persistence->recordAgentOutboundFromTelegramSend(
                is_array($sent) && isset($sent[0]) ? $sent[0] : $sent,
                $isGroup,
                $storedInbound,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Social media failed chat={chat} url={url}: {error}', [
                'chat' => $telegramChatId,
                'url' => $url,
                'error' => $e->getMessage(),
            ], $e);

            try {
                $sent = $this->messageSender->send(
                    $telegramChatId,
                    'Не вдалося завантажити медіа.',
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

    private function downloadWithRetry(string $url, bool &$skipCleanup, int $chatId): SocialVideoDownloadDTO
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

                    return new SocialVideoDownloadDTO(SocialMediaKind::Video, [$path]);
                }

                return $this->downloader->download($url, $heartbeat);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('Не вдалося завантажити медіа.');
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
