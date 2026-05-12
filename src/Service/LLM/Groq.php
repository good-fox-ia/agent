<?php

namespace App\Service\LLM;

class Groq extends AbstractLLM
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(private readonly string $apiKey)
    {
        if ($this->apiKey === '') throw new \InvalidArgumentException('GROQ_API_KEY is empty');
    }

    public function complete(string $prompt, array $options = []): string
    {
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

        $decoded = $this->post(self::URL, ['Authorization' => 'Bearer ' . $this->apiKey], $body);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) throw new \RuntimeException('Groq response has no message content.');

        return $content;
    }
}
