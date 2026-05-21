<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat;

use App\Document\Chat;
use App\Document\User;
use Psr\Log\LoggerInterface;

/**
 * Генерує LLM-опис бесіди та оновлює placeholder-повідомлення (без видалення історії в Telegram).
 */
final class ChatSwitchFinalizer
{
    public function __construct(
        private readonly ChatConversationSummarizer $summarizer,
        private readonly ChatSummaryPresenter $summaryPresenter,
        private readonly LoggerInterface $logger,
    ) {}

    public function finalize(
        User $user,
        Chat $chat,
        int $telegramChatId,
        int $placeholderTelegramMessageId,
        bool $isGroup,
    ): void {
        try {
            $summary = $this->summarizer->summarize($chat);

            if ($placeholderTelegramMessageId > 0) {
                $this->summaryPresenter->edit(
                    $user,
                    $chat,
                    $summary,
                    $telegramChatId,
                    $placeholderTelegramMessageId,
                    $isGroup,
                );

                return;
            }

            $this->summaryPresenter->send($user, $chat, $summary, $telegramChatId, null, $isGroup);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка фіналізації перемикання бесіди chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
