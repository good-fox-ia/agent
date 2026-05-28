<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat;

use App\Document\Chat;
use App\Document\Message;
use App\Document\User;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Дзеркалить повідомлення учасників спільної приватної бесіди в Telegram-чати інших учасників.
 */
final class SharedChatMessenger
{
    public function __construct(
        private readonly SharedChatHelper $sharedChat,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function relayInboundUserMessage(Message $inbound): void
    {
        $chat = $inbound->getChat();
        $author = $inbound->getAuthor();
        $text = $inbound->getText();

        if ($chat === null || !$this->sharedChat->isSharedPrivateChat($chat)) {
            return;
        }
        if (!$author instanceof User) {
            return;
        }
        if ($text === null || trim($text) === '') {
            return;
        }

        $body = sprintf(
            '<b>%s</b>: %s',
            htmlspecialchars($this->sharedChat->formatUserDisplayName($author)),
            htmlspecialchars(trim($text)),
        );

        foreach ($this->sharedChat->participantsExcept($chat, $author) as $peer) {
            $this->sendToPeer($peer, $body, $chat, null);
        }
    }

    public function relayAgentReply(
        Chat $chat,
        User $triggerAuthor,
        string $answer,
        ?Message $replyToInbound,
    ): void {
        if (!$this->sharedChat->isSharedPrivateChat($chat)) {
            return;
        }

        foreach ($this->sharedChat->participantsExcept($chat, $triggerAuthor) as $peer) {
            $this->sendToPeer($peer, $answer, $chat, $replyToInbound);
        }
    }

    private function sendToPeer(User $peer, string $text, Chat $logicalChat, ?Message $replyToInbound): void
    {
        try {
            $sent = $this->messageSender->sendToUser($peer, $text);
            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                false,
                $replyToInbound,
                $logicalChat,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка relay у спільний чат peer={peer}: {error}', [
                'peer' => $peer->getTelegramUserId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
