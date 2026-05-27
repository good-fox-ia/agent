<?php

declare(strict_types=1);

namespace App\Service\Telegram\Persistence;

use App\Document\Chat;
use App\Document\Message;
use App\Repository\GroupRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Запис у MongoDB учасників чату та повідомлень з вхідного Telegram update (з логуванням помилок).
 */
final class TelegramPersistenceService
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly UserRepository $users,
        private readonly MessageRepository $messages,
        private readonly ActiveChatService $activeChat,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function syncParticipantsFromTelegramMessage(array $message): void
    {
        $chat = $message['chat'] ?? null;
        if (!is_array($chat) || !isset($chat['id'])) return;

        try {
            $from = $message['from'] ?? null;
            if (is_array($from) && isset($from['id'])) {
                $user = $this->users->upsertFromTelegramFromPayload($from);
                if (TelegramMessageHelper::isGroup($message)) {
                    $this->groups->upsertFromTelegramChatPayload($chat)->addUser($user);
                }
            }
            $this->documentManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Збереження User/Group у MongoDB: {error}', ['error' => $e->getMessage()]);
        }
    }

    public function recordInboundUserMessage(array $message): ?Message
    {
        if (!isset($message['chat']['id'], $message['message_id'])) return null;

        try {
            $isGroup = TelegramMessageHelper::isGroup($message);
            $logicalChat = $this->resolveLogicalChatBeforeSave($message, $isGroup);

            return $this->messages->saveInboundUserFromTelegramPayload($message, $logicalChat);
        } catch (\Throwable $e) {
            $this->logger->warning('Збереження Message (вхідне): {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function recordAgentOutboundFromTelegramSend(
        array $sent,
        bool $isGroup,
        ?Message $replyToInbound,
        ?Chat $logicalChat = null,
    ): void {
        try {
            $logicalChat ??= $replyToInbound?->getChat()
                ?? $this->resolveLogicalChatFromTelegramIds($isGroup, (int) ($sent['chat']['id'] ?? 0), $replyToInbound);

            $this->messages->saveAgentOutboundFromTelegramSendResponse($sent, $isGroup, $replyToInbound, $logicalChat);
        } catch (\Throwable $e) {
            $this->logger->warning('Збереження Message (агент): {error}', ['error' => $e->getMessage()]);
        }
    }

    private function resolveLogicalChatFromTelegramIds(
        bool $isGroup,
        int $telegramChatId,
        ?Message $hint,
    ): ?Chat {
        if ($isGroup) {
            $group = $hint?->getGroup()
                ?? $this->groups->findOneBy(['telegramChatId' => $telegramChatId]);
            if ($group === null) {
                return null;
            }

            $chat = $this->activeChat->ensureForGroup($group);
            $this->documentManager->flush();

            return $chat;
        }

        $author = $hint?->getAuthor();
        if ($author === null) {
            return null;
        }

        $chat = $this->activeChat->ensureForUser($author);
        $this->documentManager->flush();

        return $chat;
    }

    private function resolveLogicalChatBeforeSave(array $message, bool $isGroup): ?Chat
    {
        if ($isGroup) {
            $chatPayload = $message['chat'] ?? null;
            if (!is_array($chatPayload) || !isset($chatPayload['id'])) {
                return null;
            }

            $group = $this->groups->upsertFromTelegramChatPayload($chatPayload);
            $from = $message['from'] ?? null;
            if (is_array($from) && isset($from['id'])) {
                $group->addUser($this->users->upsertFromTelegramFromPayload($from));
            }
            $this->documentManager->flush();

            return $this->activeChat->ensureForGroup($group);
        }

        $from = $message['from'] ?? null;
        if (!is_array($from) || !isset($from['id'])) {
            return null;
        }

        $user = $this->users->upsertFromTelegramFromPayload($from);
        $logicalChat = $this->activeChat->ensureForUser($user);
        $this->documentManager->flush();

        return $logicalChat;
    }
}

