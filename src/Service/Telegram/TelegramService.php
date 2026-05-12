<?php

namespace App\Service\Telegram;

use App\Service\Http\Client;

class TelegramService
{
    public function __construct(
        private readonly string $token,
        private readonly Client $httpClient
    ) {}

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function sendMessage(string|int $chatId, string $text, array $options = []): array
    {
        $this->assertToken();

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token);

        $body = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options);

        $decoded = $this->httpClient->post($url, $body);
        $this->isValidDecoded($decoded);

        return $decoded['result'] ?? $decoded;
    }

    public function getUpdates(int $offset = 0, int $timeout = 30, int $limit = 100): array
    {
        $this->assertToken();

        $url = sprintf('https://api.telegram.org/bot%s/getUpdates', $this->token);

        $decoded = $this->httpClient->post($url, ['offset' => $offset, 'timeout' => $timeout, 'limit' => $limit]);
        $this->isValidDecoded($decoded);

        return $decoded['result'] ?? [];
    }

    private function assertToken(): void
    {
        if ($this->token === '') throw new \InvalidArgumentException('TELEGRAM_BOT_TOKEN is empty');
    }

    private function isValidDecoded(array $decoded): void
    {
        if (!($decoded['ok'] ?? false)) {
            $desc = $decoded['description'] ?? 'Unknown Telegram API error';
            throw new \RuntimeException('Telegram API: ' . $desc);
        }
    }
}
