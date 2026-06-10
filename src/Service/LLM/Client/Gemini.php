<?php

declare(strict_types=1);

namespace App\Service\LLM\Client;

use App\Service\Http\Client;
use App\Service\LLM\AbstractLLM;
use App\Service\LLM\Adapter\PromptAdapterInterface;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Client\Interface\AudioTranscriptionLLMInterface;
use App\Service\LLM\Client\Interface\ImageDescriptionLLMInterface;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\Parser\InlineToolCallParser;
use App\Service\LLM\Tool\ToolRegistry;

class Gemini extends AbstractLLM implements TextLLMInterface, AudioTranscriptionLLMInterface, ImageDescriptionLLMInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const DEFAULT_CHAT_MODEL = 'gemini-2.0-flash';

    private const MAX_TOOL_ITERATIONS = 5;

    public function __construct(
        string $apiKey,
        Client $httpClient,
        PromptAdapterInterface $promptAdapter,
        private readonly ToolRegistry $toolRegistry,
        private readonly InlineToolCallParser $inlineToolCallParser,
        private readonly string $defaultChatModel = self::DEFAULT_CHAT_MODEL,
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
                'parts' => $parts,
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
     * @param array<string, mixed> $functionCall
     *
     * @return array{functionResponse: array{name: string, response: array<string, mixed>}}
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
                'response' => $response,
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
