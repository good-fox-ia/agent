<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Content;

use App\Message\Telegram\Chat\ProcessTelegramGroupMessage;
use App\Message\Telegram\Chat\ProcessTelegramPrivateMessage;
use App\Message\Telegram\Content\ProcessTelegramAudioMessage;
use App\Repository\MessageRepository;
use App\Service\LLM\LLMInterface;
use App\Service\Telegram\TelegramMessageHelper;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\TelegramService;
use App\Service\Telegram\TelegramUserMessageSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'telegram_audio')]
final class ProcessTelegramAudioMessageHandler
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramUserMessageSender $messageSender,
        private readonly LLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly TelegramPersistenceService $persistence,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTelegramAudioMessage $message): void
    {
        $payload = $message->message;
        $chatId = $payload['chat']['id'] ?? null;
        if ($chatId === null) {
            return;
        }

        $resolved = $this->resolveVoiceOrAudioFile($payload);
        if ($resolved === null) {
            return;
        }
        [$fileId, $audioFilename] = $resolved;

        $isGroup = TelegramMessageHelper::isGroup($payload);
        $telegramChatId = (int) $chatId;
        $telegramMessageId = (int) ($payload['message_id'] ?? 0);

        $storedInbound = $this->messages->findOneByTelegramMessageIds($telegramChatId, $telegramMessageId);

        try {
            $meta = $this->telegram->getFile($fileId);
            $filePath = $meta['file_path'] ?? null;
            if (!is_string($filePath) || $filePath === '') {
                throw new \RuntimeException('Telegram getFile: missing file_path.');
            }

            $binary = $this->telegram->downloadFile($filePath);
            $transcript = $this->llm->transcribeAudio($binary, $audioFilename, ['language' => 'uk']);

            if ($transcript === '') {
                $sent = $this->messageSender->send($telegramChatId, 'Не вдалося розпізнати мову. Спробуйте ще раз голосом.', $isGroup);
                $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);

                return;
            }

            try {
                $this->messages->saveInboundTextAfterTranscription($telegramChatId, $telegramMessageId, $transcript);
            } catch (\Throwable $e) {
                $this->logger->warning('Оновлення Message (транскрипт): {error}', ['error' => $e->getMessage()]);
            }

            $this->logger->info('Транскрипт голосу chat={chat} preview={preview}', [
                'chat' => $telegramChatId,
                'preview' => mb_substr($transcript, 0, 80),
            ]);

            if ($isGroup) {
                $this->bus->dispatch(new ProcessTelegramGroupMessage($telegramChatId, $telegramMessageId, $payload));
            } else {
                $this->bus->dispatch(new ProcessTelegramPrivateMessage($telegramChatId, $telegramMessageId, $payload));
            }
        } catch (\Throwable $e) {
            $this->logger->error('voice/audio chat={chat}: {error}', ['chat' => (string) $chatId, 'error' => $e->getMessage()]);
            try {
                $sent = $this->messageSender->send(
                    $telegramChatId,
                    mb_substr('Помилка обробки аудіо: '.$e->getMessage(), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH),
                    $isGroup,
                );
                $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: string}|null [file_id, filename for LLM]
     */
    private function resolveVoiceOrAudioFile(array $payload): ?array
    {
        if (isset($payload['voice']['file_id'])) {
            return [(string) $payload['voice']['file_id'], 'voice.ogg'];
        }
        if (isset($payload['audio']['file_id'])) {
            $fn = $payload['audio']['file_name'] ?? null;
            $name = is_string($fn) && $fn !== '' ? basename($fn) : 'audio.ogg';

            return [(string) $payload['audio']['file_id'], $name];
        }

        return null;
    }
}
