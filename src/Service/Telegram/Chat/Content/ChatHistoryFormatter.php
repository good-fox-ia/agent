<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat\Content;

use App\Document\Chat;
use App\Document\Message;
use App\Enum\MessageType;
use App\Repository\MessageRepository;
use App\Service\LLM\Parser\InlineToolCallParser;

/**
 * Форматує повну переписку логічної бесіди для надсилання в Telegram (з розбиттям на частини).
 */
final class ChatHistoryFormatter
{
    private const CHUNK_MAX = 4000;

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly InlineToolCallParser $inlineToolCallParser,
    ) {}

    /**
     * @return list<string>
     */
    public function formatChunks(Chat $chat): array
    {
        $lines = [];
        foreach ($this->messages->findAllByLogicalChatOrdered($chat) as $doc) {
            $line = $this->formatLine($doc);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            return ['📭 У цій бесіді ще немає збережених повідомлень.'];
        }

        $header = sprintf("📜 Повна переписка: %s\n\n", $chat->getTitle() ?? 'Бесіда');
        $body = implode("\n", $lines);

        return $this->splitText($header . $body);
    }

    private function formatLine(Message $doc): ?string
    {
        $text = $doc->getText();
        if ($text === null || trim($text) === '') {
            return null;
        }

        $text = trim($text);
        if ($this->inlineToolCallParser->looksLikeToolCall($text)) {
            return null;
        }

        $who = match ($doc->getType()) {
            MessageType::UserPrivate, MessageType::UserGroup => 'Користувач',
            MessageType::AgentPrivate, MessageType::AgentGroup => 'Агент',
        };

        return sprintf(
            '[%s] %s: %s',
            $doc->getCreatedAt()->format('d.m.Y H:i'),
            $who,
            $text,
        );
    }

    /**
     * @return list<string>
     */
    private function splitText(string $text): array
    {
        if (mb_strlen($text) <= self::CHUNK_MAX) {
            return [$text];
        }

        $chunks = [];
        $length = mb_strlen($text);
        for ($offset = 0; $offset < $length; $offset += self::CHUNK_MAX) {
            $chunks[] = mb_substr($text, $offset, self::CHUNK_MAX);
        }

        return $chunks;
    }
}

