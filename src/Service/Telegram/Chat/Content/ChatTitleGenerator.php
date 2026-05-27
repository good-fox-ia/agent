<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat\Content;

use App\Document\Chat;
use App\Enum\MessageType;
use App\Repository\MessageRepository;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\InlineToolCallParser;
use App\Service\LLM\LLMInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Генерує коротку назву бесіди через LLM на основі збереженої переписки.
 */
final class ChatTitleGenerator
{
    private const MAX_MESSAGES = 20;

    private const MIN_MESSAGES = 2;

    private const TITLE_MAX_LENGTH = 60;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Ти допомагаєш назвати бесіду. На основі наданої переписки запропонуй коротку назву українською (2–6 слів), що відображає головну тему розмови. Без лапок, без крапки в кінці, без пояснень — лише назва.
PROMPT;

    public function __construct(
        private readonly LLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly InlineToolCallParser $inlineToolCallParser,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function updateTitleIfNeeded(Chat $chat): void
    {
        if ($chat->getGroup() !== null || !$this->isPlaceholderTitle($chat->getTitle())) {
            return;
        }

        if ($this->messages->countByLogicalChat($chat) < self::MIN_MESSAGES) {
            return;
        }

        if (!$this->llm->isConfigured()) {
            return;
        }

        $title = $this->generate($chat);
        if ($title === null) {
            return;
        }

        $chat->setTitle($title);
        $this->documentManager->flush();
    }

    private function generate(Chat $chat): ?string
    {
        $conversation = $this->buildConversationMessages($chat);
        if ($conversation === []) {
            return null;
        }

        try {
            $answer = trim($this->llm->complete(
                new PromptDTO(
                    messages: $conversation,
                    systemPrompt: self::SYSTEM_PROMPT,
                    tools: [],
                ),
                ['max_tokens' => 40],
            ));

            if ($answer === '' || $this->inlineToolCallParser->looksLikeToolCall($answer)) {
                return null;
            }

            $title = $this->sanitizeTitle($answer);

            return $title !== '' ? $title : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Не вдалося згенерувати назву бесіди chat={chat}: {error}', [
                'chat' => $chat->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function isPlaceholderTitle(?string $title): bool
    {
        if ($title === null || $title === '') {
            return true;
        }

        return (bool) preg_match('/^Бесіда #\d+$/u', $title);
    }

    private function sanitizeTitle(string $raw): string
    {
        $title = trim($raw);
        $title = trim($title, "\"'«»„“”");
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = rtrim($title, '.…');

        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            $title = mb_substr($title, 0, self::TITLE_MAX_LENGTH - 1).'…';
        }

        return trim($title);
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
}
