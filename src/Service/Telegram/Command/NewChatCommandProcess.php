<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\ChatRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

final class NewChatCommandProcess implements CommandProcessInterface
{
    private const CONFIRMATION_TEXT = 'Створено нову бесіду';

    public function __construct(
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly ChatRepository $chats,
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::NEW_CHAT;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, новий чат пропущено для chat {chat}', ['chat' => $chatId]);

            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload($from);
            $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
            $logicalChat = $isGroup
                ? $this->chats->createForGroup(
                    $this->groups->upsertFromTelegramChatPayload($telegramMessage['chat'])->addUser($user),
                )
                : $this->chats->createForUser($user);

            $sent = $isGroup
                ? $this->messageSender->send($chatId, self::CONFIRMATION_TEXT, true)
                : $this->messageSender->sendToUser($user, self::CONFIRMATION_TEXT);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $inbound, $logicalChat);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка створення нового чату chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
