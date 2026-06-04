<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat\Content;

use App\Document\Chat;
use App\Enum\MessageType;
use App\Repository\MessageRepository;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\Parser\InlineToolCallParser;
use Psr\Log\LoggerInterface;

/**
 * Генерує короткий опис бесіди через LLM на основі збереженої переписки.
 */
final class ChatConversationSummarizer
{
    private const MAX_MESSAGES = 30;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Ти допомагаєш користувачу згадати зміст бесіди. На основі наданої переписки напиши короткий опис українською (3–6 речень): про що була розмова, ключові теми та висновки. Без зайвих вступів, без markdown і без списків — лише зв’язний текст.
PROMPT;

    public function __construct(
        private readonly TextLLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly InlineToolCallParser $inlineToolCallParser,
        private readonly LoggerInterface $logger,
    ) {}

    public function summarize(Chat $chat): string
    {
        $conversation = $this->buildConversationMessages($chat);
        if ($conversation === []) {
            return $this->fallbackText($chat);
        }

        if (!$this->llm->isConfigured()) {
            return $this->fallbackText($chat);
        }

        try {
            $answer = trim($this->llm->complete(
                new PromptDTO(
                    messages: $conversation,
                    systemPrompt: self::SYSTEM_PROMPT . "\n\nНазва бесіди: " . ($chat->getTitle() ?? 'Без назви'),
                    tools: [],
                ),
                ['max_tokens' => 400],
            ));

            if ($answer === '' || $this->inlineToolCallParser->looksLikeToolCall($answer)) {
                return $this->fallbackText($chat);
            }

            return mb_substr($answer, 0, 3500);
        } catch (\Throwable $e) {
            $this->logger->warning('Не вдалося згенерувати опис бесіди chat={chat}: {error}', [
                'chat' => $chat->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackText($chat);
        }
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function buildConversationMessages(Chat $chat): array
    {
        $stored = $this->messages->findByLogicalChatOrderedForContext($chat, self::MAX_MESSAGES);
        $result = [];

        foreach ($stored as $doc) {
            $text = $doc->getText();
            if ($text === null || trim($text) === '') {
                continue;
            }

            $role = match ($doc->getType()) {
                MessageType::UserPrivate, MessageType::UserGroup => 'user',
                MessageType::AgentPrivate, MessageType::AgentGroup => 'assistant',
            };

            if ($role === 'assistant' && $this->inlineToolCallParser->looksLikeToolCall($text)) {
                continue;
            }

            $result[] = [
                'role' => $role,
                'content' => trim($text),
            ];
        }

        return $result;
    }

    private function fallbackText(Chat $chat): string
    {
        $title = $chat->getTitle() ?? 'Без назви';
        $count = $this->messages->countByLogicalChat($chat);

        if ($count === 0) {
            return sprintf('Бесіда «%s» ще без повідомлень. Можете почати нову розмову.', $title);
        }

        return sprintf(
            'Бесіда «%s». У збереженій історії %d повідомлень. Детальний опис тимчасово недоступний.',
            $title,
            $count,
        );
    }
}

