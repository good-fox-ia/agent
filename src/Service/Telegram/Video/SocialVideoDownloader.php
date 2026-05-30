<?php

declare(strict_types=1);

namespace App\Service\Telegram\Video;

use Symfony\Component\Process\Process;

/**
 * Завантажує відео за URL через yt-dlp у тимчасову директорію.
 */
final class SocialVideoDownloader
{
    private const HEARTBEAT_INTERVAL_SECONDS = 4;

    /** TikTok / Instagram — найкращий mp4 без зайвого merge. */
    private const DEFAULT_FORMAT = 'best[ext=mp4]/bestvideo+bestaudio/best[ext=mp4]/best';

    /** YouTube: h264 до 480p (web_safari), fallback — прогресивний mp4. */
    private const YOUTUBE_FORMAT = '135+140/134+140/bestvideo[height<=480]+bestaudio/best[height<=480]';

    private const YOUTUBE_FALLBACK_FORMAT = 'best[ext=mp4]/best';

    private const YOUTUBE_PLAYER_CLIENT = 'default,web_safari';

    private const YOUTUBE_FALLBACK_PLAYER_CLIENT = 'web,mweb,android';

    private const TELEGRAM_MAX_FILESIZE = '50M';

    private const DENO_BINARY = '/usr/local/bin/deno';

    private const INSTAGRAM_COOKIES_FILE = 'var/cookies/instagram.txt';

    /** CRF для перекодування vp9→h264 під час merge (Telegram погано їсть vp9 у mp4). */
    private const INSTAGRAM_CRF = 28;

    public function __construct(
        private readonly string $ytDlpBinary,
        private readonly string $downloadDir,
        private readonly string $cookiesFile,
        private readonly string $projectDir,
    ) {}

    /**
     * @param (callable(): void)|null $heartbeat Викликається під час завантаження (напр. typing у Telegram).
     */
    public function download(string $url, ?callable $heartbeat = null): string
    {
        $url = $this->normalizePageUrl($url);

        if (!is_dir($this->downloadDir) && !mkdir($this->downloadDir, 0755, true) && !is_dir($this->downloadDir)) {
            throw new \RuntimeException('Не вдалося створити директорію для завантаження відео.');
        }

        $workDir = $this->downloadDir.'/'.uniqid('dl_', true);
        if (!mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new \RuntimeException('Не вдалося створити тимчасову директорію.');
        }

        $outputTemplate = $workDir.'/video.%(ext)s';

        try {
            $this->runDownloadAttempts($url, $outputTemplate, $heartbeat);
        } catch (\RuntimeException $e) {
            $this->removeWorkDir($workDir);
            throw $e;
        }

        $files = glob($workDir.'/*') ?: [];
        $videoFiles = array_values(array_filter(
            $files,
            static fn (string $path): bool => is_file($path) && !str_ends_with($path, '.part'),
        ));

        if ($videoFiles === []) {
            $this->removeWorkDir($workDir);
            throw new \RuntimeException('Файл відео не знайдено після завантаження.');
        }

        return $videoFiles[0];
    }

    public function removeDownloadedFile(string $path): void
    {
        $workDir = dirname($path);
        if (is_file($path)) {
            @unlink($path);
        }
        $this->removeWorkDir($workDir);
    }

    private function normalizePageUrl(string $url): string
    {
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

    private function isYouTubeUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be');
    }

    private function isInstagramUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'instagram.com');
    }

    private function resolveCookiesFile(string $url): string
    {
        if ($this->isInstagramUrl($url)) {
            return $this->projectDir.'/'.self::INSTAGRAM_COOKIES_FILE;
        }

        return $this->cookiesFile;
    }

    /**
     * @return list<array{format: string, player_client: string|null, extra_args: list<string>}>
     */
    private function downloadAttempts(string $url): array
    {
        if ($this->isYouTubeUrl($url)) {
            return [
                ['format' => self::YOUTUBE_FORMAT, 'player_client' => self::YOUTUBE_PLAYER_CLIENT, 'extra_args' => []],
                ['format' => self::YOUTUBE_FALLBACK_FORMAT, 'player_client' => self::YOUTUBE_FALLBACK_PLAYER_CLIENT, 'extra_args' => []],
            ];
        }

        if ($this->isInstagramUrl($url)) {
            return [
                ['format' => $this->instagramFormat(), 'player_client' => null, 'extra_args' => $this->instagramExtraArgs()],
            ];
        }

        return [
            ['format' => self::DEFAULT_FORMAT, 'player_client' => null, 'extra_args' => []],
        ];
    }

    private function instagramFormat(): string
    {
        // Instagram не має форматів ≤480p у метаданих (усі 720×1280). Беремо найменший dash-потік.
        return 'bv+ba/b';
    }

    /**
     * @return list<string>
     */
    private function instagramExtraArgs(): array
    {
        return [
            '-S',
            '+size',
            '--postprocessor-args',
            sprintf(
                'Merger+ffmpeg:-c:v libx264 -crf %d -preset veryfast -c:a aac -b:a 64k -movflags +faststart',
                self::INSTAGRAM_CRF,
            ),
        ];
    }

    /**
     * @param (callable(): void)|null $heartbeat
     */
    private function runDownloadAttempts(string $url, string $outputTemplate, ?callable $heartbeat): void
    {
        $combinedOutput = '';

        foreach ($this->downloadAttempts($url) as $attempt) {
            $process = new Process($this->buildDownloadCommand(
                $url,
                $outputTemplate,
                $attempt['format'],
                $attempt['player_client'],
                $this->resolveCookiesFile($url),
                $attempt['extra_args'],
            ));
            $process->setTimeout(300);
            $this->runProcess($process, $heartbeat);

            $attemptOutput = trim($process->getErrorOutput()."\n".$process->getOutput());
            $combinedOutput = $combinedOutput === ''
                ? $attemptOutput
                : $combinedOutput."\n".$attemptOutput;

            if ($process->isSuccessful()) {
                return;
            }
        }

        throw new \RuntimeException($this->humanizeError($combinedOutput));
    }

    /**
     * @param (callable(): void)|null $heartbeat
     */
    private function runProcess(Process $process, ?callable $heartbeat): void
    {
        $process->start();
        $lastHeartbeat = time();

        while ($process->isRunning()) {
            if ($heartbeat !== null && time() - $lastHeartbeat >= self::HEARTBEAT_INTERVAL_SECONDS) {
                $heartbeat();
                $lastHeartbeat = time();
            }
            $process->checkTimeout();
            usleep(200_000);
        }

        $process->wait();
    }

    /**
     * @return list<string>
     */
    private function buildDownloadCommand(
        string $url,
        string $outputTemplate,
        string $format,
        ?string $youtubePlayerClient = null,
        string $cookiesFile = '',
        array $extraArgs = [],
    ): array {
        $command = [
            $this->ytDlpBinary,
            '--no-playlist',
            '--no-progress',
            '--merge-output-format',
            'mp4',
            '--max-filesize',
            self::TELEGRAM_MAX_FILESIZE,
        ];

        if ($youtubePlayerClient !== null) {
            if (is_executable(self::DENO_BINARY)) {
                $command[] = '--js-runtimes';
                $command[] = 'deno:'.self::DENO_BINARY;
            }

            $command[] = '--extractor-args';
            $command[] = 'youtube:player_client='.$youtubePlayerClient;
        }

        if ($cookiesFile !== '' && is_readable($cookiesFile)) {
            $command[] = '--cookies';
            $command[] = $cookiesFile;
        }

        foreach ($extraArgs as $arg) {
            $command[] = $arg;
        }

        $command[] = '-f';
        $command[] = $format;
        $command[] = '-o';
        $command[] = $outputTemplate;
        $command[] = $url;

        return $command;
    }

    private function humanizeError(string $output): string
    {
        if ($output === '') {
            return 'yt-dlp завершився з помилкою.';
        }

        if (str_contains($output, 'Precondition check failed') || str_contains($output, 'Only images are available')) {
            return 'YouTube тимчасово блокує автозавантаження (rate limit). Спробуйте пізніше або додайте cookies у var/cookies/youtube.txt';
        }

        if (str_contains($output, 'nsig extraction failed') || str_contains($output, 'HTTP Error 403')) {
            return 'YouTube заблокував завантаження (nsig/403). Спробуйте ще раз або додайте cookies: SOCIAL_VIDEO_COOKIES_FILE=/var/www/html/var/cookies/youtube.txt';
        }

        if (str_contains($output, 'JSON object must be str') || str_contains($output, 'not NoneType')) {
            return 'Сайт (часто TikTok) не повернув дані для завантаження — тимчасовий блок або зміна API. Спробуйте ще раз через хвилину або інше посилання.';
        }

        if (str_contains($output, '[Instagram]')) {
            if (str_contains($output, 'login required') || str_contains($output, 'rate-limit')) {
                return 'Instagram вимагає cookies. Експортуйте cookies з браузера (Netscape) у var/cookies/instagram.txt.';
            }

            return 'Не вдалося завантажити Instagram Reel.';
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $line): bool => str_starts_with($line, 'ERROR:'),
        ));

        if ($lines !== []) {
            return substr($lines[array_key_last($lines)], 7);
        }

        return mb_substr($output, 0, 500);
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
