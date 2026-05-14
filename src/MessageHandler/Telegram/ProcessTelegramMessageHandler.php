<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram;

use App\Message\Telegram\Content\ProcessTelegramAudioMessage;
use App\Message\Telegram\Content\ProcessTelegramTextMessage;
use App\Message\Telegram\ProcessTelegramMessage;
use App\Service\Telegram\TelegramInboundUpdateApplier;
use App\Service\Telegram\TelegramService;
use App\Telegram\TelegramUpdatePayload;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'telegram_inbound')]
final class ProcessTelegramMessageHandler
{
    public function __construct(
        private readonly TelegramInboundUpdateApplier $inboundUpdateApplier,
        private readonly MessageBusInterface $bus,
        private readonly TelegramService $telegram,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTelegramMessage $job): void
    {
        $message = $job->telegramMessage;
        $chatId = $message['chat']['id'] ?? null;
        if ($chatId === null) {
            return;
        }

        $this->inboundUpdateApplier->syncParticipantsFromTelegramMessage($message);

        $chatType = (string) ($message['chat']['type'] ?? 'private');
        $isGroup = in_array($chatType, ['group', 'supergroup'], true);

        $storedInbound = $this->inboundUpdateApplier->recordInboundUserMessage($message, $isGroup);

        $fileId = null;
        $audioFilename = 'voice.ogg';
        if (isset($message['voice']['file_id'])) {
            $fileId = (string) $message['voice']['file_id'];
        } elseif (isset($message['audio']['file_id'])) {
            $fileId = (string) $message['audio']['file_id'];
            $fn = $message['audio']['file_name'] ?? null;
            $audioFilename = is_string($fn) && $fn !== '' ? basename($fn) : 'audio.ogg';
        }

        if ($fileId !== null) {
            $this->bus->dispatch(new ProcessTelegramAudioMessage($message, $fileId, $audioFilename));
            $this->logger->info('Telegram inbound: queued audio chat={chat}', ['chat' => (string) $chatId]);

            return;
        }

        $textBody = TelegramUpdatePayload::visibleTextBody($message);
        if ($textBody !== '') {
            $this->bus->dispatch(new ProcessTelegramTextMessage($message));
            $this->logger->info('Telegram inbound: queued text chat={chat}', ['chat' => (string) $chatId]);

            return;
        }

        if ($isGroup) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            return;
        }

        try {
            $sent = $this->telegram->sendMessage($chatId, 'Надішліть текстове або голосове повідомлення.');
            $this->inboundUpdateApplier->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);
        } catch (\Throwable $e) {
            $this->logger->error('sendMessage chat={chat}: {error}', ['chat' => (string) $chatId, 'error' => $e->getMessage()]);
        }
    }
}
