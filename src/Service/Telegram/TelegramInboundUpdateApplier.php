<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Document\Message;
use App\Repository\GroupRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Запис у MongoDB учасників чату та повідомлень з вхідного Telegram update (з логуванням помилок).
 */
final class TelegramInboundUpdateApplier
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly UserRepository $users,
        private readonly MessageRepository $messages,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $message об'єкт message / edited_message з Telegram API
     */
    public function syncParticipantsFromTelegramMessage(array $message): void
    {
        $chat = $message['chat'] ?? null;
        if (!is_array($chat) || !isset($chat['id'])) {
            return;
        }

        try {
            $group = $this->groups->upsertFromTelegramChatPayload($chat);
            $from = $message['from'] ?? null;
            if (is_array($from) && isset($from['id'])) {
                $user = $this->users->upsertFromTelegramFromPayload($from);
                $group->addUser($user);
            }
            $this->documentManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Збереження User/Group у MongoDB: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    public function recordInboundUserMessage(array $message, bool $isGroup): ?Message
    {
        if (!isset($message['chat']['id'], $message['message_id'])) {
            return null;
        }

        try {
            return $this->messages->saveInboundUserFromTelegramPayload($message, $isGroup);
        } catch (\Throwable $e) {
            $this->logger->warning('Збереження Message (вхідне): {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $sent об'єкт повідомлення з відповіді Telegram sendMessage
     */
    public function recordAgentOutboundFromTelegramSend(array $sent, bool $isGroup, ?Message $replyToInbound): void
    {
        try {
            $this->messages->saveAgentOutboundFromTelegramSendResponse($sent, $isGroup, $replyToInbound);
        } catch (\Throwable $e) {
            $this->logger->warning('Збереження Message (агент): {error}', ['error' => $e->getMessage()]);
        }
    }
}
