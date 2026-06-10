<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Content;

use App\Message\Telegram\Content\ProcessTelegramMediaMessage;
use App\Repository\MessageRepository;
use App\Service\LLM\Client\Interface\ImageDescriptionLLMInterface;
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
                $this->describePhotoAndReply($payload, $telegramChatId, $telegramMessageId, $localPath);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Telegram media chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ], $e);
        }
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
