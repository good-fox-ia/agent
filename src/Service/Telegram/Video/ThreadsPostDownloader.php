<?php

declare(strict_types=1);

namespace App\Service\Telegram\Video;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Завантажує пости Threads (threads.com / threads.net): відео, фото або лише текст.
 *
 * yt-dlp і gallery-dl не підтримують Threads, але сторінка поста віддає повні дані
 * (вбудований JSON у <script type="application/json">) для crawler User-Agent без логіна.
 */
final class ThreadsPostDownloader
{
    /** Threads рендерить серверні дані поста лише для відомих краулерів. */
    private const CRAWLER_USER_AGENT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /** UA для завантаження медіа з CDN (звичайний браузер). */
    private const BROWSER_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const HEARTBEAT_INTERVAL_SECONDS = 4;

    /** Ліміт Telegram для ботів — 50 МБ. */
    private const MAX_MEDIA_BYTES = 50 * 1024 * 1024;

    private const REQUEST_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $downloadDir,
    ) {}

    public function supports(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'threads.com') || str_contains($host, 'threads.net');
    }

    /**
     * @param (callable(): void)|null $heartbeat Викликається під час завантаження (напр. typing у Telegram).
     */
    public function download(string $url, ?callable $heartbeat = null): SocialVideoDownloadDTO
    {
        $postCode = $this->extractPostCode($url);
        if ($postCode === null) {
            throw new \RuntimeException('Не вдалося визначити код поста Threads з посилання.');
        }

        $html = $this->fetchPage($url);
        $post = $this->findPostNode($html, $postCode);
        if ($post === null) {
            throw new \RuntimeException('Не вдалося знайти дані поста Threads (можливо, пост приватний або видалений).');
        }

        $caption = $this->extractCaption($post);
        [$videoUrls, $imageUrls] = $this->extractMediaUrls($post);

        if ($videoUrls === [] && $imageUrls === []) {
            if ($caption === null) {
                throw new \RuntimeException('Пост Threads не містить ні тексту, ні медіа.');
            }

            return new SocialVideoDownloadDTO(SocialMediaKind::Text, [], $caption);
        }

        $workDir = $this->createWorkDir();

        try {
            if ($videoUrls !== []) {
                $path = $this->downloadMedia($videoUrls[0], $workDir.'/video_1', 'mp4', $heartbeat);

                return new SocialVideoDownloadDTO(SocialMediaKind::Video, [$path], $caption);
            }

            $paths = [];
            foreach ($imageUrls as $index => $imageUrl) {
                $paths[] = $this->downloadMedia($imageUrl, sprintf('%s/photo_%02d', $workDir, $index + 1), 'jpg', $heartbeat);
            }

            return new SocialVideoDownloadDTO(SocialMediaKind::Photo, $paths, $caption);
        } catch (\Throwable $e) {
            $this->removeWorkDir($workDir);

            throw $e;
        }
    }

    public function removeDownloadedFile(string $path): void
    {
        $this->removeWorkDir(dirname($path));
    }

    private function extractPostCode(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/(?:@[^/]+/post|t)/([A-Za-z0-9_-]+)#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function fetchPage(string $url): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => self::CRAWLER_USER_AGENT,
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $html = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Не вдалося завантажити сторінку Threads: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400 || $html === '') {
            throw new \RuntimeException(sprintf('Threads повернув помилку (HTTP %d).', $status));
        }

        return $html;
    }

    /**
     * Шукає у вбудованих JSON-блоках сторінки вузол поста з відповідним кодом.
     *
     * @return array<string, mixed>|null
     */
    private function findPostNode(string $html, string $postCode): ?array
    {
        if (!preg_match_all('#<script type="application/json"[^>]*>(.*?)</script>#s', $html, $matches)) {
            return null;
        }

        $candidates = [];
        foreach ($matches[1] as $block) {
            if (!str_contains($block, $postCode)) {
                continue;
            }

            try {
                $data = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($data)) {
                $this->collectPostNodes($data, $postCode, $candidates);
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Перевага вузлам з медіа-даними: той самий пост може зустрічатися у кількох payload.
        foreach ($candidates as $candidate) {
            if (array_key_exists('image_versions2', $candidate) || array_key_exists('video_versions', $candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<array<string, mixed>> $candidates
     */
    private function collectPostNodes(array $node, string $postCode, array &$candidates): void
    {
        if (($node['code'] ?? null) === $postCode && array_key_exists('caption', $node)) {
            /** @var array<string, mixed> $node */
            $candidates[] = $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectPostNodes($value, $postCode, $candidates);
            }
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    private function extractCaption(array $post): ?string
    {
        $caption = $post['caption'] ?? null;
        if (!is_array($caption)) {
            return null;
        }

        $text = $caption['text'] ?? null;
        if (!is_string($text)) {
            return null;
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{0: list<string>, 1: list<string>} [відео-URL, фото-URL]
     */
    private function extractMediaUrls(array $post): array
    {
        $items = is_array($post['carousel_media'] ?? null) && $post['carousel_media'] !== []
            ? $post['carousel_media']
            : [$post];

        $videoUrls = [];
        $imageUrls = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $videoUrl = $this->firstUrl($item['video_versions'] ?? null);
            if ($videoUrl !== null) {
                $videoUrls[] = $videoUrl;
                continue;
            }

            $candidates = is_array($item['image_versions2'] ?? null) ? ($item['image_versions2']['candidates'] ?? null) : null;
            $imageUrl = $this->firstUrl($candidates);
            if ($imageUrl !== null) {
                $imageUrls[] = $imageUrl;
            }
        }

        return [$videoUrls, $imageUrls];
    }

    private function firstUrl(mixed $versions): ?string
    {
        if (!is_array($versions)) {
            return null;
        }

        foreach ($versions as $version) {
            if (is_array($version) && is_string($version['url'] ?? null) && $version['url'] !== '') {
                return $version['url'];
            }
        }

        return null;
    }

    /**
     * @param (callable(): void)|null $heartbeat
     */
    private function downloadMedia(string $url, string $targetWithoutExtension, string $fallbackExtension, ?callable $heartbeat): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!preg_match('#^[a-z0-9]{2,4}$#', $extension)) {
            $extension = $fallbackExtension;
        }

        $targetPath = $targetWithoutExtension.'.'.$extension;

        $handle = fopen($targetPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Не вдалося створити файл для медіа Threads.');
        }

        $bytesWritten = 0;
        $lastHeartbeat = time();

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => self::BROWSER_USER_AGENT],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);

            foreach ($this->httpClient->stream($response, self::REQUEST_TIMEOUT_SECONDS) as $chunk) {
                if ($heartbeat !== null && time() - $lastHeartbeat >= self::HEARTBEAT_INTERVAL_SECONDS) {
                    $heartbeat();
                    $lastHeartbeat = time();
                }

                $content = $chunk->getContent();
                if ($content === '') {
                    continue;
                }

                $bytesWritten += strlen($content);
                if ($bytesWritten > self::MAX_MEDIA_BYTES) {
                    throw new \RuntimeException('Медіа Threads перевищує ліміт Telegram у 50 МБ.');
                }

                fwrite($handle, $content);
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Не вдалося завантажити медіа Threads: '.$e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }

        if ($bytesWritten === 0) {
            @unlink($targetPath);

            throw new \RuntimeException('Медіа Threads виявилося порожнім.');
        }

        return $targetPath;
    }

    private function createWorkDir(): string
    {
        if (!is_dir($this->downloadDir) && !mkdir($this->downloadDir, 0755, true) && !is_dir($this->downloadDir)) {
            throw new \RuntimeException('Не вдалося створити директорію для завантаження медіа.');
        }

        $workDir = $this->downloadDir.'/'.uniqid('threads_', true);
        if (!mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new \RuntimeException('Не вдалося створити тимчасову директорію.');
        }

        return $workDir;
    }

    private function removeWorkDir(string $workDir): void
    {
        if (!is_dir($workDir)) {
            return;
        }

        foreach (glob($workDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($workDir);
    }
}
