<?php

namespace App\Service\LLM;

class Groq extends AbstractLLM
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

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

        $decoded = $this->post(self::URL, $body, ['Authorization' => 'Bearer ' . $this->apiKey]);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) throw new \RuntimeException('Groq response has no message content.');

        return $content;
    }
}
