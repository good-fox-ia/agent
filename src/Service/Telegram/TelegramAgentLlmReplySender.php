<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Enum\MessageType;
use App\Repository\MessageRepository;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\LLMInterface;
use Psr\Log\LoggerInterface;

/**
 * Генерує відповідь через LLM, надсилає її в Telegram і зберігає запис агента в MongoDB.
 */
final class TelegramAgentLlmReplySender
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    private const LLM_MAX_CONTEXT_MESSAGES = 80;

    private const LLM_SYSTEM_PROMPT = 'Ти корисний асистент. Відповідай українською, стисло та по суті.';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly LLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendLlmReplyForChat(int $telegramChatId, bool $isGroup, int $triggerTelegramMessageId): void
    {
        if (!$this->telegram->isConfigured() || !$this->llm->isConfigured()) {
            $this->logger->error('Telegram або LLM не налаштовані, відповідь пропущено для chat {chat}', ['chat' => $telegramChatId]);

            return;
        }

        $replyToInbound = $this->messages->findOneByTelegramMessageIds($telegramChatId, $triggerTelegramMessageId);

        try {
            $answer = $this->llm->complete($this->buildPromptDtoForChat($telegramChatId));
            $answer = mb_substr($answer, 0, self::TELEGRAM_MAX_MESSAGE_LENGTH);
            $sent = $this->telegram->sendMessage($telegramChatId, $answer);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $replyToInbound);
            $this->logger->info('Відповідь агента надіслано в chat {chat}', ['chat' => $telegramChatId]);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка LLM/sendMessage chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
            try {
                $sent = $this->telegram->sendMessage(
                    $telegramChatId,
                    mb_substr('Помилка: '.$e->getMessage(), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH),
                );
                $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $replyToInbound);
            } catch (\Throwable) {
                // ignore secondary failure
            }
        }
    }

    private function buildPromptDtoForChat(int $telegramChatId): PromptDTO
    {
        return new PromptDTO(
            messages: $this->buildChatMessagesForLlm($telegramChatId),
            tools: [],
            systemPrompt: self::LLM_SYSTEM_PROMPT,
        );
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function buildChatMessagesForLlm(int $telegramChatId): array
    {
        $stored = $this->messages->findByChatOrderedForContext($telegramChatId, self::LLM_MAX_CONTEXT_MESSAGES);

        $messages = [];
        foreach ($stored as $doc) {
            $t = $doc->getText();
            if ($t === null || trim($t) === '') {
                continue;
            }
            $messages[] = [
                'role' => match ($doc->getType()) {
                    MessageType::UserPrivate, MessageType::UserGroup => 'user',
                    MessageType::AgentPrivate, MessageType::AgentGroup => 'assistant',
                },
                'content' => $t,
            ];
        }

        return $messages;
    }
}
