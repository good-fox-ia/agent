<?php

namespace App\Service\LLM;

class Groq extends AbstractLLM
{
    private const COMPLETE_URL = 'https://api.groq.com/openai/v1/chat/completions';

    private const TRANSCRIBE_URL = 'https://api.groq.com/openai/v1/audio/transcriptions';

    public function complete(string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') throw new \InvalidArgumentException('GROQ_API_KEY is empty');

        $model = $options['model'] ?? 'llama-3.3-70b-versatile';

        $extra = array_intersect_key($options, array_flip([
            'temperature',
            'max_tokens',
            'top_p',
            'stop',
            'frequency_penalty',
            'presence_penalty',
        ]));

        $body = array_merge([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ],
            ],
        ], $extra);

        $decoded = $this->post(self::COMPLETE_URL, $body, self::getHeaders());
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) throw new \RuntimeException('Groq response has no message content.');

        return $content;
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

    private function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey
        ];
    }
}
