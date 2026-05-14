<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram;

use App\Message\Telegram\Content\ProcessTelegramAudioMessage;
use App\Message\Telegram\Content\ProcessTelegramTextMessage;
use App\Message\Telegram\ProcessTelegramMessage;
use App\Service\Telegram\TelegramMessageHelper;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\TelegramService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'telegram_inbound')]
final class ProcessTelegramMessageHandler
{
    public function __construct(
        private readonly TelegramPersistenceService $persistence,
        private readonly MessageBusInterface $bus,
        private readonly TelegramService $telegram,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTelegramMessage $job): void
    {
        $message = $job->message;
        $chatId = $message['chat']['id'] ?? null;
        if ($chatId === null) return;

        $this->persistence->syncParticipantsFromTelegramMessage($message);

        $storedInbound = $this->persistence->recordInboundUserMessage($message);

        if (isset($message['voice']['file_id']) || isset($message['audio']['file_id'])) {
            $this->bus->dispatch(new ProcessTelegramAudioMessage($message));
            $this->logger->info('Telegram inbound: queued audio chat={chat}', ['chat' => (string) $chatId]);

            return;
        }

        $textBody = TelegramMessageHelper::visibleTextBody($message);
        if ($textBody !== '') {
            $this->bus->dispatch(new ProcessTelegramTextMessage($message));
            $this->logger->info('Telegram inbound: queued text chat={chat}', ['chat' => (string) $chatId]);

            return;
        }

        try {
            $sent = $this->telegram->sendMessage($chatId, 'Надішліть текстове або голосове повідомлення.');
            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                TelegramMessageHelper::isGroup($message),
                $storedInbound
            );
        } catch (\Throwable $e) {
            $this->logger->error('sendMessage chat={chat}: {error}', ['chat' => (string) $chatId, 'error' => $e->getMessage()]);
        }
    }
}
