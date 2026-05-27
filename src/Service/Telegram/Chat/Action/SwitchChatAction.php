<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat\Action;

use App\Document\Chat;
use App\Document\Message;
use App\Document\User;
use App\Service\Telegram\Chat\Content\ChatConversationSummarizer;
use App\Service\Telegram\Chat\UI\ChatSummaryResponder;
use App\Service\Telegram\Api\TelegramService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Перемикає активну бесіду та одразу формує LLM-опис (без брокера повідомлень).
 */
final class SwitchChatAction
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly TelegramService $telegram,
        private readonly ChatConversationSummarizer $summarizer,
        private readonly ChatSummaryResponder $summaryPresenter,
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

            $summary = $this->summarizer->summarize($chat);
            $this->summaryPresenter->send($user, $chat, $summary, $telegramChatId, $inbound, $isGroup);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка перемикання бесіди chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

