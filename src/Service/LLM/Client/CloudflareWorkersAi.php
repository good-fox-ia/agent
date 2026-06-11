<?php

declare(strict_types=1);

namespace App\Service\LLM\Client;

use App\Service\Http\Client;
use App\Service\LLM\Client\Interface\ImageGenerationLLMInterface;
use App\Service\LLM\DTO\GeneratedImageDTO;
use Symfony\Component\Process\Process;

/**
 * Cloudflare Workers AI: редагування зображень через FLUX.2 klein (img2img).
 * Вхідні зображення моделі мають бути < 512x512 — більші зменшуються через ffmpeg.
 */
final class CloudflareWorkersAi implements ImageGenerationLLMInterface
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4/accounts';

    private const DEFAULT_IMAGE_MODEL = '@cf/black-forest-labs/flux-2-klein-4b';

    /** Ліміт Workers AI на вхідні зображення FLUX.2 klein. */
    private const MAX_INPUT_DIMENSION = 511;

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
        private readonly Client $httpClient,
        private readonly string $defaultImageModel = self::DEFAULT_IMAGE_MODEL,
    ) {}

    public function isConfigured(): bool
    {
        return $this->accountId !== '' && $this->apiToken !== '';
    }

    public function editImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): GeneratedImageDTO
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_API_TOKEN is empty');
        }

        $model = $options['model'] ?? $this->defaultImageModel;

        [$inputBinary, $outputSize] = $this->prepareInput($imageBinary);

        $inputPath = tempnam(sys_get_temp_dir(), 'cf_img_in_');
        if ($inputPath === false || file_put_contents($inputPath, $inputBinary) === false) {
            throw new \RuntimeException('Не вдалося записати тимчасовий файл зображення.');
        }

        $handle = fopen($inputPath, 'rb');
        if ($handle === false) {
            unlink($inputPath);
            throw new \RuntimeException('Не вдалося відкрити тимчасовий файл зображення.');
        }

        try {
            $url = sprintf('%s/%s/ai/run/%s', self::API_BASE, rawurlencode($this->accountId), $model);

            $body = [
                'prompt' => $prompt,
                'input_image_0' => $handle,
            ];
            if ($outputSize !== null) {
                $body['width'] = (string) $outputSize[0];
                $body['height'] = (string) $outputSize[1];
            }

            $decoded = $this->httpClient->postMultipart($url, $body, [
                'Authorization' => 'Bearer ' . $this->apiToken,
            ]);
        } finally {
            fclose($handle);
            unlink($inputPath);
        }

        if (($decoded['success'] ?? false) !== true) {
            throw new \RuntimeException('Cloudflare Workers AI error: ' . json_encode($decoded['errors'] ?? $decoded, JSON_UNESCAPED_UNICODE));
        }

        $base64 = $decoded['result']['image'] ?? null;
        if (!is_string($base64) || $base64 === '') {
            throw new \RuntimeException('Cloudflare Workers AI response has no image.');
        }

        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Cloudflare Workers AI returned invalid image data.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $resultMime = $finfo->buffer($binary);

        return new GeneratedImageDTO(
            $binary,
            is_string($resultMime) && str_starts_with($resultMime, 'image/') ? $resultMime : 'image/png',
        );
    }

    /**
     * Зменшує зображення до < 512x512 (вимога моделі) і рахує розмір результату
     * з пропорціями оригіналу (довша сторона 1024).
     *
     * @return array{0: string, 1: array{0: int, 1: int}|null}
     */
    private function prepareInput(string $imageBinary): array
    {
        $size = getimagesizefromstring($imageBinary);
        if ($size === false) {
            return [$imageBinary, null];
        }

        [$width, $height] = $size;
        $outputSize = $this->scaleToFit($width, $height, 1024);

        if ($width <= self::MAX_INPUT_DIMENSION && $height <= self::MAX_INPUT_DIMENSION) {
            return [$imageBinary, $outputSize];
        }

        return [$this->downscaleWithFfmpeg($imageBinary), $outputSize];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaleToFit(int $width, int $height, int $maxSide): array
    {
        $scale = min(1.0, $maxSide / max($width, $height, 1));
        // FLUX приймає 256–1920; вирівнюємо до кратних 16
        $clamp = static fn (float $value): int => max(256, min(1920, ((int) round($value / 16)) * 16));

        return [$clamp($width * $scale), $clamp($height * $scale)];
    }

    private function downscaleWithFfmpeg(string $imageBinary): string
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'cf_scale_in_');
        $outputPath = tempnam(sys_get_temp_dir(), 'cf_scale_out_') . '.png';

        if ($inputPath === false || file_put_contents($inputPath, $imageBinary) === false) {
            throw new \RuntimeException('Не вдалося записати файл для масштабування.');
        }

        try {
            $maxSide = self::MAX_INPUT_DIMENSION;
            $process = new Process([
                'ffmpeg', '-y', '-i', $inputPath,
                '-vf', sprintf("scale='min(%d,iw)':'min(%d,ih)':force_original_aspect_ratio=decrease", $maxSide, $maxSide),
                $outputPath,
            ]);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('ffmpeg не зміг зменшити зображення: ' . trim($process->getErrorOutput()));
            }

            $scaled = file_get_contents($outputPath);
            if ($scaled === false || $scaled === '') {
                throw new \RuntimeException('ffmpeg не створив зменшене зображення.');
            }

            return $scaled;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }
}
