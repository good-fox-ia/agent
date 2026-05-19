<?php

declare(strict_types=1);

namespace App\Service\LLM;

use App\Service\Http\Client;
use App\Service\LLM\Adapter\PromptAdapterInterface;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Tool\ToolRegistry;

class Groq extends AbstractLLM
{
    private const COMPLETE_URL = 'https://api.groq.com/openai/v1/chat/completions';

    private const TRANSCRIBE_URL = 'https://api.groq.com/openai/v1/audio/transcriptions';

    /** @see https://console.groq.com/docs/tool-use/local-tool-calling */
    private const DEFAULT_CHAT_MODEL = 'openai/gpt-oss-120b';

    private const MAX_TOOL_ITERATIONS = 5;

    public function __construct(
        string $apiKey,
        Client $httpClient,
        PromptAdapterInterface $promptAdapter,
        private readonly ToolRegistry $toolRegistry,
        private readonly string $defaultChatModel = self::DEFAULT_CHAT_MODEL,
    ) {
        parent::__construct($apiKey, $httpClient, $promptAdapter);
    }

    public function complete(PromptDTO $prompt, array $options = []): string
    {
        if ($this->apiKey === '') throw new \InvalidArgumentException('GROQ_API_KEY is empty');

        $model = $options['model'] ?? $this->defaultChatModel;

        $extra = array_intersect_key($options, array_flip([
            'temperature',
            'max_tokens',
            'top_p',
            'stop',
            'frequency_penalty',
            'presence_penalty',
        ]));

        $fromPrompt = $this->promptAdapter->adapt($prompt);
        $messages = $fromPrompt['messages'] ?? [];
        if (!is_array($messages) || $messages === []) throw new \InvalidArgumentException('PromptDTO yields no messages for the model.');

        $body = array_merge($fromPrompt, [
            'model' => $model,
        ], $extra);

        return $this->sendPrompt($body);
    }

    public function transcribeAudio(string $audioBinary, string $filename = 'audio.ogg', array $options = []): string
    {
        if ($this->apiKey === '') throw new \InvalidArgumentException('GROQ_API_KEY is empty');

        $model = $options['model'] ?? 'whisper-large-v3-turbo';
        $language = $options['language'] ?? null;

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?: 'audio.ogg';
        $path = sys_get_temp_dir().'/'.uniqid('tg_audio_', true).'_'.$safeName;
        if (file_put_contents($path, $audioBinary) === false) throw new \RuntimeException('Cannot write temp audio file.');

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            @unlink($path);
            throw new \RuntimeException('Cannot open temp audio file.');
        }

        try {
            $multipart = [
                'model' => $model,
                'file' => $handle,
            ];
            if (is_string($language) && $language !== '') {
                $multipart['language'] = $language;
            }

            $decoded = $this->postMultipart(self::TRANSCRIBE_URL, $multipart, self::getHeaders());
        } finally {
            fclose($handle);
            @unlink($path);
        }

        $text = $decoded['text'] ?? null;
        if (!is_string($text)) throw new \RuntimeException('Groq transcription response has no text field.');

        return trim($text);
    }

    private function sendPrompt(array $body): string
    {
        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; ++$iteration) {
            $decoded = $this->post(self::COMPLETE_URL, $body, self::getHeaders());
            $message = $this->extractAssistantMessage($decoded);

            $toolCalls = $message['tool_calls'] ?? null;
            if (!is_array($toolCalls) || $toolCalls === []) {
                $content = $message['content'] ?? null;
                if (!is_string($content) || $content === '') throw new \RuntimeException('Groq response has no message content.');

                return $content;
            }

            $messages[] = $message;
            foreach ($toolCalls as $toolCall) {
                $messages[] = $this->executeToolCall($toolCall);
            }

            $body['messages'] = $messages;
        }

        throw new \RuntimeException(sprintf('Groq tool calling exceeded %d iterations.', self::MAX_TOOL_ITERATIONS));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAssistantMessage(array $decoded): array
    {
        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            throw new \RuntimeException('Groq response has no choices.');
        }

        $message = $choices[0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('Groq response has no assistant message.');
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $toolCall
     *
     * @return array{role: string, tool_call_id: string, name: string, content: string}
     */
    private function executeToolCall(array $toolCall): array
    {
        $toolCallId = $toolCall['id'] ?? null;
        if (!is_string($toolCallId) || $toolCallId === '') {
            throw new \RuntimeException('Groq tool call has no id.');
        }

        $function = $toolCall['function'] ?? null;
        if (!is_array($function)) {
            throw new \RuntimeException('Groq tool call has no function payload.');
        }

        $name = $function['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \RuntimeException('Groq tool call has no function name.');
        }

        $argumentsRaw = $function['arguments'] ?? '{}';
        if (!is_string($argumentsRaw)) {
            $argumentsRaw = '{}';
        }

        $arguments = json_decode($argumentsRaw, true);
        if (!is_array($arguments)) {
            throw new \RuntimeException(sprintf('Groq tool call "%s" has invalid JSON arguments.', $name));
        }

        return [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'name' => $name,
            'content' => $this->toolRegistry->executeTool($name, $arguments),
        ];
    }

    private function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey
        ];
    }
}
