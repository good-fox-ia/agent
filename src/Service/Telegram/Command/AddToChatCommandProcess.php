<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Document\User;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Chat\Action\AddParticipantToChatAction;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

final class AddToChatCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AddParticipantToChatAction $addParticipant,
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::ADD_TO_CHAT;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, addtochat пропущено chat={chat}', ['chat' => $chatId]);

            return;
        }

        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
        if ($isGroup) {
            $this->sendReply($chatId, null, $inbound, true, 'Команда доступна лише в приватному чаті з ботом.');

            return;
        }

        $user = $this->users->upsertFromTelegramFromPayload($from);

        try {
            $args = TelegramMessageHelper::commandArguments($telegramMessage);
            $replyText = $this->addParticipant->addByUsername($user, $args);
            $this->sendReply($chatId, $user, $inbound, false, $replyText);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка addtochat chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendReply(int $chatId, ?User $user, ?Message $inbound, bool $isGroup, string $text): void
    {
        try {
            $sent = $isGroup || $user === null
                ? $this->messageSender->send($chatId, $text, $isGroup)
                : $this->messageSender->sendToUser($user, $text);

            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                $isGroup,
                $inbound,
                $user?->getCurrentChat(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка відповіді addtochat chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
