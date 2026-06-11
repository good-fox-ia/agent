<?php

declare(strict_types=1);

namespace App\Service\LLM\Client;

use App\Service\Http\Client;
use App\Service\LLM\Client\Interface\ImageGenerationLLMInterface;
use App\Service\LLM\DTO\GeneratedImageDTO;

/**
 * OpenRouter: безплатний доступ до image-моделей (Nano Banana free) через chat completions API.
 */
final class OpenRouter implements ImageGenerationLLMInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const DEFAULT_IMAGE_MODEL = 'google/gemini-2.5-flash-image';

    /** Nano Banana іноді відповідає текстом без картинки — повторюємо запит. */
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly string $apiKey,
        private readonly Client $httpClient,
        private readonly string $defaultImageModel = self::DEFAULT_IMAGE_MODEL,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function editImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): GeneratedImageDTO
    {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('OPENROUTER_API_KEY is empty');
        }

        $model = $options['model'] ?? $this->defaultImageModel;

        $body = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => sprintf('data:%s;base64,%s', $mimeType, base64_encode($imageBinary)),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
            'modalities' => ['image', 'text'],
            // Одна картинка ~1300 токенів; без ліміту OpenRouter резервує максимум моделі і вимагає більший баланс
            'max_tokens' => 4096,
        ];

        $lastText = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $decoded = $this->httpClient->post(self::API_URL, $body, [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ]);

            if (isset($decoded['error'])) {
                throw new \RuntimeException('OpenRouter error: ' . json_encode($decoded['error'], JSON_UNESCAPED_UNICODE));
            }

            $message = $decoded['choices'][0]['message'] ?? null;
            if (!is_array($message)) {
                throw new \RuntimeException('OpenRouter response has no message.');
            }

            $images = $message['images'] ?? null;
            if (is_array($images)) {
                foreach ($images as $image) {
                    $dataUrl = $image['image_url']['url'] ?? null;
                    if (is_string($dataUrl)) {
                        return $this->decodeDataUrl($dataUrl);
                    }
                }
            }

            $text = is_string($message['content'] ?? null) ? trim($message['content']) : '';
            if ($text !== '') {
                $lastText = $text;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(500_000);
            }
        }

        throw new \RuntimeException(
            $lastText !== ''
                ? sprintf('OpenRouter image generation returned no image after %d attempts: %s', self::MAX_ATTEMPTS, mb_substr($lastText, 0, 300))
                : sprintf('OpenRouter image generation returned no image after %d attempts.', self::MAX_ATTEMPTS),
        );
    }

    private function decodeDataUrl(string $dataUrl): GeneratedImageDTO
    {
        if (!preg_match('#^data:(image/[\w.+-]+);base64,(.+)$#s', $dataUrl, $matches)) {
            throw new \RuntimeException('OpenRouter returned an unexpected image URL format.');
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('OpenRouter returned invalid image data.');
        }

        return new GeneratedImageDTO($binary, $matches[1]);
    }
}
