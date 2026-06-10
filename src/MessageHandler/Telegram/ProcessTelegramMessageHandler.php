<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram;

use App\Message\Telegram\Content\ProcessTelegramAudioMessage;
use App\Message\Telegram\Content\ProcessTelegramMediaMessage;
use App\Message\Telegram\Content\ProcessTelegramTextMessage;
use App\Message\Telegram\ProcessTelegramMessage;
use App\Service\Telegram\Command\CommandProcessor;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'telegram_inbound')]
final class ProcessTelegramMessageHandler
{
    public function __construct(
        private readonly TelegramPersistenceService $persistence,
        private readonly MessageBusInterface $bus,
        private readonly CommandProcessor $commandProcessor,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTelegramMessage $job): void
    {
        $message = $job->message;
        $chatId = $message['chat']['id'] ?? null;
        if ($chatId === null) return;

        $this->persistence->syncParticipantsFromTelegramMessage($message);
        $inbound = $this->persistence->recordInboundUserMessage($message);

        if ($this->commandProcessor->tryProcess($message, $inbound)) {
            return;
        }

        if (TelegramMessageHelper::hasMediaAttachment($message)) {
            $this->bus->dispatch(new ProcessTelegramMediaMessage($message));
            $this->logger->info('Telegram inbound: queued media chat={chat}', ['chat' => (string) $chatId]);
        }

        if (isset($message['voice']['file_id']) || isset($message['audio']['file_id'])) {
            $this->bus->dispatch(new ProcessTelegramAudioMessage($message));
            $this->logger->info('Telegram inbound: queued audio chat={chat}', ['chat' => (string) $chatId]);
        }

        if (TelegramMessageHelper::visibleTextBody($message) !== '') {
            $this->bus->dispatch(new ProcessTelegramTextMessage($message));
            $this->logger->info('Telegram inbound: queued text chat={chat}', ['chat' => (string) $chatId]);
        }
    }
}
