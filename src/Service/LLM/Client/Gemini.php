<?php

declare(strict_types=1);

namespace App\Service\LLM\Client;

use App\Service\Http\Client;
use App\Service\LLM\AbstractLLM;
use App\Service\LLM\Adapter\PromptAdapterInterface;
use App\Service\LLM\DTO\GeneratedAudioDTO;
use App\Service\LLM\DTO\GeneratedImageDTO;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Client\Interface\AudioGenerationLLMInterface;
use App\Service\LLM\Client\Interface\AudioTranscriptionLLMInterface;
use App\Service\LLM\Client\Interface\ImageDescriptionLLMInterface;
use App\Service\LLM\Client\Interface\ImageGenerationLLMInterface;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\Parser\InlineToolCallParser;
use App\Service\LLM\Tool\ToolRegistry;

class Gemini extends AbstractLLM implements TextLLMInterface, AudioTranscriptionLLMInterface, ImageDescriptionLLMInterface, ImageGenerationLLMInterface, AudioGenerationLLMInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const DEFAULT_CHAT_MODEL = 'gemini-2.0-flash';

    private const TEXT_TO_IMG_MODELS = [
        'gemini-2.0-flash-exp-image-generation',
        'imagen-4.0-generate-001',
    ];

    private const IMG_TO_IMG_MODELS = [
        'gemini-2.0-flash-exp-image-generation',
        'gemini-2.5-flash-image',
    ];

    /** Моделі TTS у порядку спроб: помилка (квота/перевантаження) — пробуємо наступну. */
    private const DEFAULT_TTS_MODELS = 'gemini-2.5-flash-preview-tts,gemini-3.1-flash-tts-preview';

    private const DEFAULT_TTS_VOICE = 'Kore';

    private const MAX_TOOL_ITERATIONS = 5;

    public function __construct(
        string $apiKey,
        Client $httpClient,
        PromptAdapterInterface $promptAdapter,
        private readonly ToolRegistry $toolRegistry,
        private readonly InlineToolCallParser $inlineToolCallParser,
        private readonly string $defaultChatModel = self::DEFAULT_CHAT_MODEL,
        private readonly string $ttsModelsCsv = self::DEFAULT_TTS_MODELS,
    ) {
        parent::__construct($apiKey, $httpClient, $promptAdapter);
    }

    public function complete(PromptDTO $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('GEMINI_API_KEY is empty');
        }

        $model = $options['model'] ?? $this->defaultChatModel;

        $fromPrompt = $this->promptAdapter->adapt($prompt);
        $contents = $fromPrompt['contents'] ?? [];
        if (!is_array($contents) || $contents === []) {
            throw new \InvalidArgumentException('PromptDTO yields no messages for the model.');
        }

        $body = $fromPrompt;
        $generationConfig = $this->buildGenerationConfig($options);
        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
        }

        return $this->sendPrompt($model, $body);
    }

    public function transcribeAudio(string $audioBinary, string $filename = 'audio.ogg', array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('GEMINI_API_KEY is empty');
        }

        $model = $options['model'] ?? $this->defaultChatModel;
        $language = $options['language'] ?? 'uk';
        $mimeType = $this->guessMimeType($filename);

        $prompt = is_string($language) && $language !== ''
            ? sprintf('Transcribe this audio. Return only the transcript text, no commentary. Prefer language: %s.', $language)
            : 'Transcribe this audio. Return only the transcript text, no commentary.';

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => base64_encode($audioBinary),
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
        ];

        $decoded = $this->post($this->buildUrl($model), $body, $this->getHeaders());
        $text = $this->extractTextFromResponse($decoded);
        if ($text === '') {
            throw new \RuntimeException('Gemini transcription response has no text.');
        }

        return trim($text);
    }

    public function describeImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('GEMINI_API_KEY is empty');
        }

        $model = $options['model'] ?? $this->defaultChatModel;

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => base64_encode($imageBinary),
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
        ];

        $decoded = $this->post($this->buildUrl($model), $body, $this->getHeaders());
        $text = $this->extractTextFromResponse($decoded);
        if ($text === '') {
            throw new \RuntimeException('Gemini image description response has no text.');
        }

        return trim($text);
    }

    public function generateImage(string $prompt, array $options = []): GeneratedImageDTO
    {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('GEMINI_API_KEY is empty');
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new \InvalidArgumentException('Prompt for image generation is empty.');
        }

        $models = isset($options['model']) && is_string($options['model']) && $options['model'] !== ''
            ? [$options['model']]
            : self::TEXT_TO_IMG_MODELS;

        $lastException = null;
        foreach ($models as $model) {
            try {
                if ($this->isImagenModel($model)) {
                    return $this->generateImageWithImagen($prompt, $model);
                }

                return $this->generateImageWithNanoBanana($prompt, $model);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('No Gemini image generation models configured.');
    }

    private function generateImageWithImagen(string $prompt, string $model): GeneratedImageDTO
    {
        $body = [
            'instances' => [['prompt' => $prompt]],
            'parameters' => [
                'sampleCount' => 1,
            ],
        ];

        $decoded = $this->post($this->buildPredictUrl($model), $body, $this->getHeaders());

        return $this->extractGeneratedImageFromPredictions($decoded);
    }

    private function generateImageWithNanoBanana(string $prompt, string $model): GeneratedImageDTO
    {
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ];

        $lastException = null;
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $decoded = $this->post($this->buildUrl($model), $body, $this->getHeaders());

                return $this->extractGeneratedImageFromGenerateContent($decoded);
            } catch (\RuntimeException $e) {
                $lastException = $e;
                if ($e->getCode() !== 429 || $attempt >= 2) {
                    throw $e;
                }

                $delaySeconds = $this->extractRetryDelaySeconds($e->getMessage()) ?? 45;
                sleep(min($delaySeconds, 60));
            }
        }

        throw $lastException ?? new \RuntimeException('Gemini image generation failed.');
    }

    private function isImagenModel(string $model): bool
    {
        return str_starts_with($model, 'imagen-');
    }

    private function extractRetryDelaySeconds(string $message): ?int
    {
        if (preg_match('/retry in (\d+(?:\.\d+)?)s/i', $message, $matches) === 1) {
            return (int) ceil((float) $matches[1]);
        }

        if (preg_match('/"retryDelay":\s*"(\d+)s"/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public function editImage(string $imageBinary, string $mimeType, string $prompt, array $options = []): GeneratedImageDTO
    {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('GEMINI_API_KEY is empty');
        }

        $models = isset($options['model']) && is_string($options['model']) && $options['model'] !== ''
            ? [$options['model']]
            : self::IMG_TO_IMG_MODELS;

        $lastException = null;
        foreach ($models as $model) {
            if ($this->isImagenModel($model)) {
                continue;
            }

            try {
                return $this->editImageWithNanoBanana($imageBinary, $mimeType, $prompt, $model);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('No Gemini image edit models configured.');
    }

    private function editImageWithNanoBanana(string $imageBinary, string $mimeType, string $prompt, string $model): GeneratedImageDTO
    {
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => base64_encode($imageBinary),
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ];

        $decoded = $this->post($this->buildUrl($model), $body, $this->getHeaders());

        return $this->extractGeneratedImageFromGenerateContent($decoded);
    }

    public function generateAudio(string $text, array $options = []): GeneratedAudioDTO
    {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('GEMINI_API_KEY is empty');
        }

        if (trim($text) === '') {
            throw new \InvalidArgumentException('Text for audio generation is empty.');
        }

        $voice = is_string($options['voice'] ?? null) && $options['voice'] !== ''
            ? $options['voice']
            : self::DEFAULT_TTS_VOICE;

        $models = isset($options['model']) && is_string($options['model']) && $options['model'] !== ''
            ? [$options['model']]
            : $this->ttsModels();

        $lastException = null;
        foreach ($models as $model) {
            try {
                return $this->requestTtsAudio($model, $text, $voice);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('No Gemini TTS models configured.');
    }

    private function requestTtsAudio(string $model, string $text, string $voice): GeneratedAudioDTO
    {
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $text],
                ],
            ]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $voice],
                    ],
                ],
            ],
        ];

        $decoded = $this->post($this->buildUrl($model), $body, $this->getHeaders());

        foreach ($this->extractCandidateParts($decoded) as $part) {
            $inlineData = $part['inlineData'] ?? null;
            if (is_array($inlineData) && isset($inlineData['data'])) {
                $binary = base64_decode((string) $inlineData['data'], true);
                if ($binary === false || $binary === '') {
                    throw new \RuntimeException('Gemini audio generation returned invalid audio data.');
                }

                $mimeType = is_string($inlineData['mimeType'] ?? null) ? $inlineData['mimeType'] : 'audio/L16;codec=pcm;rate=24000';

                return $this->wrapPcmInWavIfNeeded($binary, $mimeType);
            }
        }

        throw new \RuntimeException('Gemini audio generation response has no audio.');
    }

    /**
     * @return list<string>
     */
    private function ttsModels(): array
    {
        $models = [];
        foreach (explode(',', $this->ttsModelsCsv) as $part) {
            $model = trim($part);
            if ($model !== '' && !in_array($model, $models, true)) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * Gemini TTS повертає сирий PCM (audio/L16) — загортаємо у WAV-контейнер, щоб файл був придатний для відтворення.
     */
    private function wrapPcmInWavIfNeeded(string $binary, string $mimeType): GeneratedAudioDTO
    {
        if (stripos($mimeType, 'audio/l16') !== 0 && stripos($mimeType, 'pcm') === false) {
            return new GeneratedAudioDTO($binary, $mimeType);
        }

        $sampleRate = 24000;
        if (preg_match('/rate=(\d+)/', $mimeType, $matches) === 1) {
            $sampleRate = (int) $matches[1];
        }

        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * intdiv($bitsPerSample, 8);
        $blockAlign = $channels * intdiv($bitsPerSample, 8);
        $dataSize = strlen($binary);

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVEfmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataSize);

        return new GeneratedAudioDTO($header.$binary, 'audio/wav');
    }

    private function sendPrompt(string $model, array $body): string
    {
        $contents = $body['contents'] ?? [];
        if (!is_array($contents)) {
            $contents = [];
        }

        $staticBody = array_diff_key($body, ['contents' => true]);

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; ++$iteration) {
            $requestBody = array_merge($staticBody, ['contents' => $contents]);
            if (isset($requestBody['tools'])) {
                $requestBody['toolConfig'] = [
                    'functionCallingConfig' => ['mode' => 'AUTO'],
                ];
            }

            $decoded = $this->post($this->buildUrl($model), $requestBody, $this->getHeaders());
            $parts = $this->extractCandidateParts($decoded);

            $text = $this->collectTextFromParts($parts);
            $functionCalls = $this->collectFunctionCallsFromParts($parts);

            if ($functionCalls === []) {
                $inline = $this->inlineToolCallParser->parse($text);
                if ($inline !== null) {
                    $functionCalls = [[
                        'name' => $inline['name'],
                        'args' => $inline['arguments'],
                    ]];
                    $parts = [['functionCall' => $functionCalls[0]]];
                }
            }

            if ($functionCalls === []) {
                if ($text === '') {
                    if ($iteration > 0) {
                        return '';
                    }

                    throw new \RuntimeException('Gemini response has no text content.');
                }

                return $text;
            }

            $contents[] = [
                'role' => 'model',
                'parts' => $this->normalizeFunctionCallParts($parts),
            ];

            $responseParts = [];
            foreach ($functionCalls as $functionCall) {
                $responseParts[] = $this->buildFunctionResponsePart($functionCall);
            }

            $contents[] = [
                'role' => 'user',
                'parts' => $responseParts,
            ];
        }

        throw new \RuntimeException(sprintf('Gemini tool calling exceeded %d iterations.', self::MAX_TOOL_ITERATIONS));
    }

    /**
     * Порожні args ({} у відповіді Gemini) PHP декодує в порожній масив, який json_encode
     * серіалізує назад як список []. Proto-схема Gemini вимагає об'єкт — підміняємо на stdClass.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeFunctionCallParts(array $parts): array
    {
        foreach ($parts as $i => $part) {
            if (!isset($part['functionCall']) || !is_array($part['functionCall'])) {
                continue;
            }
            $args = $part['functionCall']['args'] ?? null;
            if ($args === null || $args === []) {
                $parts[$i]['functionCall']['args'] = new \stdClass();
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $functionCall
     *
     * @return array{functionResponse: array{name: string, response: array<string, mixed>|\stdClass}}
     */
    private function buildFunctionResponsePart(array $functionCall): array
    {
        $name = $functionCall['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \RuntimeException('Gemini function call has no name.');
        }

        $args = $functionCall['args'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }

        $result = $this->toolRegistry->executeTool($name, $args);

        try {
            $response = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($response)) {
                $response = ['result' => $result];
            }
        } catch (\JsonException) {
            $response = ['result' => $result];
        }

        return [
            'functionResponse' => [
                'name' => $name,
                'response' => $response === [] ? new \stdClass() : $response,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $parts
     *
     * @return list<array<string, mixed>>
     */
    private function collectFunctionCallsFromParts(array $parts): array
    {
        $calls = [];
        foreach ($parts as $part) {
            $functionCall = $part['functionCall'] ?? null;
            if (is_array($functionCall)) {
                $calls[] = $functionCall;
            }
        }

        return $calls;
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    private function collectTextFromParts(array $parts): string
    {
        $chunks = [];
        foreach ($parts as $part) {
            $text = $part['text'] ?? null;
            if (is_string($text) && $text !== '') {
                $chunks[] = $text;
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractCandidateParts(array $decoded): array
    {
        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            throw new \RuntimeException('Gemini response has no candidates.');
        }

        $content = $candidates[0]['content'] ?? null;
        if (!is_array($content)) {
            throw new \RuntimeException('Gemini response has no content.');
        }

        $parts = $content['parts'] ?? null;
        if (!is_array($parts) || $parts === []) {
            throw new \RuntimeException('Gemini response has no parts.');
        }

        return $parts;
    }

    private function extractTextFromResponse(array $decoded): string
    {
        return $this->collectTextFromParts($this->extractCandidateParts($decoded));
    }

    /**
     * @return array<string, int|float>
     */
    private function buildGenerationConfig(array $options): array
    {
        $config = [];

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $config['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens']) && is_numeric($options['max_tokens'])) {
            $config['maxOutputTokens'] = (int) $options['max_tokens'];
        }

        if (isset($options['top_p']) && is_numeric($options['top_p'])) {
            $config['topP'] = (float) $options['top_p'];
        }

        if (isset($options['stop'])) {
            $stop = $options['stop'];
            if (is_string($stop)) {
                $config['stopSequences'] = [$stop];
            } elseif (is_array($stop)) {
                $config['stopSequences'] = array_values(array_filter($stop, is_string(...)));
            }
        }

        return $config;
    }

    private function buildUrl(string $model): string
    {
        return self::API_BASE.'/'.rawurlencode($model).':generateContent';
    }

    private function buildPredictUrl(string $model): string
    {
        return self::API_BASE.'/'.rawurlencode($model).':predict';
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractGeneratedImageFromPredictions(array $decoded): GeneratedImageDTO
    {
        $predictions = $decoded['predictions'] ?? null;
        if (!is_array($predictions)) {
            throw new \RuntimeException('Gemini Imagen response has no predictions.');
        }

        foreach ($predictions as $prediction) {
            if (!is_array($prediction)) {
                continue;
            }

            $base64 = $prediction['bytesBase64Encoded'] ?? null;
            if (!is_string($base64) || $base64 === '') {
                continue;
            }

            $binary = base64_decode($base64, true);
            if ($binary === false || $binary === '') {
                throw new \RuntimeException('Gemini Imagen returned invalid image data.');
            }

            return new GeneratedImageDTO(
                $binary,
                is_string($prediction['mimeType'] ?? null) ? $prediction['mimeType'] : 'image/png',
            );
        }

        throw new \RuntimeException('Gemini Imagen response has no image.');
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractGeneratedImageFromGenerateContent(array $decoded): GeneratedImageDTO
    {
        foreach ($this->extractCandidateParts($decoded) as $part) {
            $inlineData = $part['inlineData'] ?? null;
            if (is_array($inlineData) && isset($inlineData['data'])) {
                $binary = base64_decode((string) $inlineData['data'], true);
                if ($binary === false || $binary === '') {
                    throw new \RuntimeException('Gemini image generation returned invalid image data.');
                }

                return new GeneratedImageDTO(
                    $binary,
                    is_string($inlineData['mimeType'] ?? null) ? $inlineData['mimeType'] : 'image/png',
                );
            }
        }

        $text = $this->extractTextFromResponse($decoded);

        throw new \RuntimeException(
            $text !== ''
                ? sprintf('Gemini image generation returned no image: %s', mb_substr($text, 0, 300))
                : 'Gemini image generation response has no image.',
        );
    }

    private function getHeaders(): array
    {
        return [
            'x-goog-api-key' => $this->apiKey,
        ];
    }

    private function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'webm' => 'audio/webm',
            default => 'audio/ogg',
        };
    }
}
