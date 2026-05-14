<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Content;

use App\Message\Telegram\Chat\ProcessTelegramGroupMessage;
use App\Message\Telegram\Chat\ProcessTelegramPrivateMessage;
use App\Message\Telegram\Content\ProcessTelegramAudioMessage;
use App\Repository\MessageRepository;
use App\Service\LLM\LLMInterface;
use App\Service\Telegram\TelegramInboundUpdateApplier;
use App\Service\Telegram\TelegramService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'telegram_audio')]
final class ProcessTelegramAudioMessageHandler
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly LLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly TelegramInboundUpdateApplier $inboundUpdateApplier,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTelegramAudioMessage $message): void
    {
        $payload = $message->telegramMessage;
        $chatId = $payload['chat']['id'] ?? null;
        if ($chatId === null) {
            return;
        }

        $chatType = (string) ($payload['chat']['type'] ?? 'private');
        $isGroup = in_array($chatType, ['group', 'supergroup'], true);
        $telegramChatId = (int) $chatId;
        $telegramMessageId = (int) ($payload['message_id'] ?? 0);

        $storedInbound = $this->messages->findOneByTelegramMessageIds($telegramChatId, $telegramMessageId);

        try {
            $meta = $this->telegram->getFile($message->fileId);
            $filePath = $meta['file_path'] ?? null;
            if (!is_string($filePath) || $filePath === '') {
                throw new \RuntimeException('Telegram getFile: missing file_path.');
            }

            $binary = $this->telegram->downloadFile($filePath);
            $transcript = $this->llm->transcribeAudio($binary, $message->audioFilename, ['language' => 'uk']);

            if ($transcript === '') {
                $sent = $this->telegram->sendMessage($chatId, 'Не вдалося розпізнати мову. Спробуйте ще раз голосом.');
                $this->inboundUpdateApplier->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);

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
                $this->bus->dispatch(new ProcessTelegramGroupMessage($telegramChatId, $telegramMessageId));
            } else {
                $this->bus->dispatch(new ProcessTelegramPrivateMessage($telegramChatId, $telegramMessageId));
            }
        } catch (\Throwable $e) {
            $this->logger->error('voice/audio chat={chat}: {error}', ['chat' => (string) $chatId, 'error' => $e->getMessage()]);
            try {
                $sent = $this->telegram->sendMessage(
                    $chatId,
                    mb_substr('Помилка обробки аудіо: '.$e->getMessage(), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH),
                );
                $this->inboundUpdateApplier->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);
            } catch (\Throwable) {
            }
        }
    }
}
