<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat;

use App\Document\Chat;
use App\Document\Message;
use App\Document\User;
use App\Message\Telegram\Chat\FinalizeChatSwitch;
use App\Service\Telegram\TelegramService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Перемикає активну бесіду; LLM-опис генерується асинхронно без видалення повідомлень у Telegram.
 */
final class UserChatSwitcher
{
    private const PLACEHOLDER_TEXT = "⏳ Готую опис бесіди…";

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly TelegramService $telegram,
        private readonly ChatSummaryPresenter $summaryPresenter,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function switchTo(
        User $user,
        Chat $chat,
        int $telegramChatId,
        ?Message $inbound,
        bool $isGroup,
    ): void {
        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, перемикання бесіди пропущено chat={chat}', [
                'chat' => $telegramChatId,
            ]);

            return;
        }

        try {
            $user->setCurrentChat($chat);
            $this->documentManager->flush();

            $placeholderMessageId = $this->summaryPresenter->send(
                $user,
                $chat,
                self::PLACEHOLDER_TEXT,
                $telegramChatId,
                $inbound,
                $isGroup,
            );

            $this->bus->dispatch(new FinalizeChatSwitch(
                logicalChatId: (string) $chat->getId(),
                telegramUserId: $user->getTelegramUserId(),
                telegramChatId: $telegramChatId,
                placeholderTelegramMessageId: $placeholderMessageId,
                isGroup: $isGroup,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Помилка перемикання бесіди chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
