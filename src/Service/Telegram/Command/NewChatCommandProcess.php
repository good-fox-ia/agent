<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Keyboard\ReplyKeyboard;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\TelegramService;
use Psr\Log\LoggerInterface;

final class NewChatCommandProcess implements CommandProcessInterface
{
    private const CONFIRMATION_TEXT = 'Створено нову бесіду';

    public function __construct(
        private readonly UserRepository $users,
        private readonly ChatRepository $chats,
        private readonly MessageRepository $messages,
        private readonly TelegramService $telegram,
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
        $messageId = (int) ($telegramMessage['message_id'] ?? 0);
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
            $this->chats->createForUser($user);

            if ($messageId > 0) {
                $this->telegram->deleteMessage($chatId, $messageId);
            }

            //$this->messages->deleteAllForTelegramChat($chatId);
            $this->telegram->clearChat($chatId, $messageId);

            $sent = $this->telegram->sendMessage($chatId, self::CONFIRMATION_TEXT, [
                'reply_markup' => ReplyKeyboard::markup(),
            ]);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, false, null);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка створення нового чату chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
