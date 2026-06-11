<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Content;

use App\Document\Message;
use App\Message\Telegram\Content\ProcessTelegramMediaMessage;
use App\Repository\MessageRepository;
use App\Service\LLM\Client\Interface\ImageDescriptionLLMInterface;
use App\Service\LLM\Client\Interface\ImageGenerationLLMInterface;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Chat\GroupBotAddressChecker;
use App\Service\Telegram\Media\TelegramMediaStorage;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'telegram_media')]
final class ProcessTelegramMediaMessageHandler
{
    private const PHOTO_DESCRIBE_PROMPT = 'Опиши, що зображено на цьому фото. Відповідай українською мовою, звичайним текстом без розмітки, коротко і по суті.';

    public function __construct(
        private readonly TelegramMediaStorage $mediaStorage,
        private readonly MessageRepository $messages,
        private readonly ImageDescriptionLLMInterface $imageLlm,
        private readonly ImageGenerationLLMInterface $imageGenLlm,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly GroupBotAddressChecker $botAddressChecker,
        private readonly TelegramService $telegram,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTelegramMediaMessage $message): void
    {
        $payload = $message->message;
        $chatId = $payload['chat']['id'] ?? null;
        $messageId = $payload['message_id'] ?? null;
        if ($chatId === null || $messageId === null) {
            return;
        }

        $attachment = TelegramMessageHelper::resolveMediaAttachment($payload);
        if ($attachment === null) {
            return;
        }

        $telegramChatId = (int) $chatId;
        $telegramMessageId = (int) $messageId;

        try {
            $localPath = $this->mediaStorage->downloadAndStore(
                $telegramChatId,
                $telegramMessageId,
                $attachment['file_id'],
                $attachment['file_size'],
                $attachment['file_name'],
            );

            if ($localPath === null) {
                $this->logger->info('Telegram media: файл >= 20МБ, пропускаємо chat={chat} message={message}', [
                    'chat' => $telegramChatId,
                    'message' => $telegramMessageId,
                ]);

                return;
            }

            $this->messages->saveInboundFilePath($telegramChatId, $telegramMessageId, $localPath);

            $this->logger->info('Telegram media: збережено chat={chat} message={message} path={path}', [
                'chat' => $telegramChatId,
                'message' => $telegramMessageId,
                'path' => $localPath,
            ]);

            if ($attachment['type'] === 'photo') {
                $editPrompt = $this->resolveEditPrompt($payload);
                if ($editPrompt !== null) {
                    $this->editPhotoAndReply($payload, $telegramChatId, $telegramMessageId, $localPath, $editPrompt);
                } else {
                    $this->describePhotoAndReply($payload, $telegramChatId, $telegramMessageId, $localPath);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Telegram media chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ], $e);
        }
    }

    /**
     * Підпис до фото — це промпт для редагування зображення (якщо не порожній після зняття згадки бота).
     *
     * @param array<string, mixed> $payload
     */
    private function resolveEditPrompt(array $payload): ?string
    {
        $caption = isset($payload['caption']) ? trim((string) $payload['caption']) : '';
        if ($caption === '') {
            return null;
        }

        $prompt = trim((string) str_ireplace(GroupBotAddressChecker::BOT_MENTION, '', $caption));

        return $prompt !== '' ? $prompt : null;
    }

    /**
     * Фото з підписом: якщо доступна генерація зображень — редагує фото за промптом і надсилає картинку;
     * якщо генерація недоступна чи не вдалась — віддає картинку + підпис у vision LLM і відповідає текстом.
     *
     * @param array<string, mixed> $payload
     */
    private function editPhotoAndReply(array $payload, int $telegramChatId, int $telegramMessageId, string $localPath, string $prompt): void
    {
        $isGroup = TelegramMessageHelper::isGroup($payload);
        if ($isGroup && !$this->botAddressChecker->isAddressedToBot($telegramChatId, $payload)) {
            return;
        }

        $binary = file_get_contents($localPath);
        if ($binary === false) {
            throw new \RuntimeException(sprintf('Не вдалося прочитати файл "%s".', $localPath));
        }

        $mimeType = mime_content_type($localPath) ?: 'image/jpeg';
        $storedInbound = $this->messages->findOneByTelegramMessageIds($telegramChatId, $telegramMessageId);

        if ($this->imageGenLlm->isConfigured()) {
            try {
                $this->telegram->sendChatAction($telegramChatId, 'upload_photo');

                $generated = $this->imageGenLlm->editImage($binary, $mimeType, $prompt);
                $generatedPath = $this->saveGeneratedImage($localPath, $generated->binary, $generated->mimeType);

                $sent = $this->telegram->sendPhoto($telegramChatId, $generatedPath, [
                    'reply_to_message_id' => $telegramMessageId,
                ]);
                $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);

                $this->logger->info('Telegram media: відредаговане фото надіслано chat={chat} message={message} path={path}', [
                    'chat' => $telegramChatId,
                    'message' => $telegramMessageId,
                    'path' => $generatedPath,
                ]);

                return;
            } catch (\Throwable $e) {
                $this->logger->warning('Telegram media: генерація фото не вдалась, відповідаємо текстом chat={chat}: {error}', [
                    'chat' => $telegramChatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->answerPhotoWithVision($payload, $telegramChatId, $telegramMessageId, $binary, $mimeType, $prompt, $isGroup, $storedInbound);
    }

    /**
     * Фолбек: картинка + текст користувача у vision LLM, відповідь текстом у чат.
     *
     * @param array<string, mixed> $payload
     */
    private function answerPhotoWithVision(
        array $payload,
        int $telegramChatId,
        int $telegramMessageId,
        string $binary,
        string $mimeType,
        string $prompt,
        bool $isGroup,
        ?Message $storedInbound,
    ): void {
        if (!$this->imageLlm->isConfigured()) {
            $this->logger->warning('Vision LLM не налаштований, обробку фото пропущено для chat {chat}', ['chat' => $telegramChatId]);

            return;
        }

        try {
            $this->telegram->sendChatAction($telegramChatId, 'typing');

            $answer = trim($this->imageLlm->describeImage($binary, $mimeType, $prompt));
            if ($answer === '') {
                return;
            }

            $sent = $this->messageSender->send($telegramChatId, htmlspecialchars($answer, ENT_NOQUOTES), $isGroup, [
                'reply_to_message_id' => $telegramMessageId,
            ]);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);

            $this->logger->info('Telegram media: текстова відповідь по фото надіслана chat={chat} message={message}', [
                'chat' => $telegramChatId,
                'message' => $telegramMessageId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Telegram media: обробка фото chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ], $e);

            $sent = $this->messageSender->send($telegramChatId, 'Не вдалося обробити фото.', $isGroup, [
                'reply_to_message_id' => $telegramMessageId,
            ]);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);
        }
    }

    /**
     * Зберігає згенероване зображення поруч з оригіналом і повертає шлях до файлу.
     */
    private function saveGeneratedImage(string $originalPath, string $binary, string $mimeType): string
    {
        $extension = match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $path = sprintf(
            '%s/%s_edited_%d.%s',
            dirname($originalPath),
            pathinfo($originalPath, PATHINFO_FILENAME),
            time(),
            $extension,
        );

        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException(sprintf('Не вдалося записати файл "%s".', $path));
        }

        return $path;
    }

    /**
     * Описує фото через vision LLM (без тулзів і системного промпту) і надсилає опис у чат.
     *
     * @param array<string, mixed> $payload
     */
    private function describePhotoAndReply(array $payload, int $telegramChatId, int $telegramMessageId, string $localPath): void
    {
        $isGroup = TelegramMessageHelper::isGroup($payload);
        if ($isGroup && !$this->botAddressChecker->isAddressedToBot($telegramChatId, $payload)) {
            return;
        }

        if (!$this->imageLlm->isConfigured()) {
            $this->logger->warning('Vision LLM не налаштований, опис фото пропущено для chat {chat}', ['chat' => $telegramChatId]);

            return;
        }

        $binary = file_get_contents($localPath);
        if ($binary === false) {
            throw new \RuntimeException(sprintf('Не вдалося прочитати файл "%s".', $localPath));
        }

        $mimeType = mime_content_type($localPath) ?: 'image/jpeg';

        $this->telegram->sendChatAction($telegramChatId, 'typing');

        $description = trim($this->imageLlm->describeImage($binary, $mimeType, self::PHOTO_DESCRIBE_PROMPT));
        if ($description === '') {
            return;
        }

        $storedInbound = $this->messages->findOneByTelegramMessageIds($telegramChatId, $telegramMessageId);
        // Опис — звичайний текст; екрануємо, бо повідомлення надсилається з parse_mode=HTML
        $sent = $this->messageSender->send($telegramChatId, htmlspecialchars($description, ENT_NOQUOTES), $isGroup);
        $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);

        $this->logger->info('Telegram media: опис фото надіслано chat={chat} message={message}', [
            'chat' => $telegramChatId,
            'message' => $telegramMessageId,
        ]);
    }
}
