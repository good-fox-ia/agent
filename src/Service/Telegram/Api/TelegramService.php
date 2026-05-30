<?php

declare(strict_types=1);

namespace App\Service\Telegram\Api;

use App\Enum\TelegramBotCommandScope;
use App\Service\Http\Client;

class TelegramService
{
    public function __construct(
        private readonly string $token,
        private readonly Client $httpClient,
    ) {}

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function sendMessage(string|int $chatId, string $text, array $options = []): array
    {
        $body = array_merge(['chat_id' => $chatId, 'text' => $text], $options);
        $decoded = $this->callApi('sendMessage', $body);

        return $decoded['result'] ?? $decoded;
    }

    public function sendChatAction(string|int $chatId, string $action = 'typing'): void
    {
        $this->callApi('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    public function removeReplyKeyboard(string|int $chatId, string $text = ''): array
    {
        return $this->sendMessage($chatId, $text, ['reply_markup' => ['remove_keyboard' => true]]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $body = ['callback_query_id' => $callbackQueryId];
        if (isset($text)) $body['text'] = $text;

        $this->callApi('answerCallbackQuery', $body);
    }

    public function editMessageText(string|int $chatId, int $messageId, string $text, array $options = []): array
    {
        $body = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $options);

        $decoded = $this->callApi('editMessageText', $body);

        return $decoded['result'] ?? $decoded;
    }

    public function deleteMessage(string|int $chatId, int $messageId): bool
    {
        try {
            $this->callApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Намагається видалити повідомлення в чаті (останні $sweepLimit id до $upToMessageId включно).
     */
    public function clearChat(string|int $chatId, ?int $upToMessageId = null, int $sweepLimit = 200): void
    {
        if ($upToMessageId === null || $upToMessageId <= 0) {
            return;
        }

        $from = max(1, $upToMessageId - $sweepLimit + 1);
        for ($messageId = $upToMessageId; $messageId >= $from; $messageId--) {
            $this->deleteMessage($chatId, $messageId);
        }
    }

    /**
     * @param list<array{command: string, description: string}> $commands
     */
    public function setMyCommands(
        array $commands,
        ?TelegramBotCommandScope $scope = null,
        ?string $languageCode = null,
    ): void {
        $body = ['commands' => $commands];
        if ($scope !== null) {
            $body['scope'] = $scope->toTelegramScope();
        }
        if ($languageCode !== null && $languageCode !== '') {
            $body['language_code'] = $languageCode;
        }

        $this->callApi('setMyCommands', $body);
    }

    public function getUpdates(int $offset = 0, int $timeout = 30, int $limit = 100): array
    {
        $decoded = $this->callApi('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'limit' => $limit,
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
        ]);

        return $decoded['result'] ?? [];
    }

    public function getFile(string $fileId): array
    {
        $decoded = $this->callApi('getFile', ['file_id' => $fileId]);

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) throw new \RuntimeException('Telegram getFile: empty result.');

        return $result;
    }

    public function downloadFile(string $filePath): string
    {
        $this->assertToken();

        $url = sprintf('https://api.telegram.org/file/bot%s/%s', $this->token, $filePath);

        return $this->httpClient->get($url);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendVideo(string|int $chatId, string $videoPath, array $options = []): array
    {
        $handle = fopen($videoPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Не вдалося відкрити файл відео.');
        }

        try {
            $body = array_merge(['chat_id' => $chatId, 'video' => $handle], $options);
            $decoded = $this->callApiMultipart('sendVideo', $body);

            return $decoded['result'] ?? $decoded;
        } finally {
            fclose($handle);
        }
    }

    private function callApi(string $method, array $body): array
    {
        $this->assertToken();

        $url = sprintf('https://api.telegram.org/bot%s/%s', $this->token, $method);
        $decoded = $this->httpClient->post($url, $body);
        $this->isValidDecoded($decoded);

        return $decoded;
    }

    private function callApiMultipart(string $method, array $body): array
    {
        $this->assertToken();

        $url = sprintf('https://api.telegram.org/bot%s/%s', $this->token, $method);
        $decoded = $this->httpClient->postMultipart($url, $body);
        $this->isValidDecoded($decoded);

        return $decoded;
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

