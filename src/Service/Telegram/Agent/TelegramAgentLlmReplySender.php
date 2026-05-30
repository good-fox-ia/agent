<?php

declare(strict_types=1);

namespace App\Service\Telegram\Agent;

use App\Document\Chat;
use App\Document\Group;
use App\Document\User;
use App\Enum\MessageType;
use App\Repository\GroupRepository;
use App\Repository\MessageRepository;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\InlineToolCallParser;
use App\Service\LLM\LLMInterface;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Chat\Content\ChatTitleGenerator;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;
use App\Service\Telegram\Persistence\ActiveChatService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Генерує відповідь через LLM, надсилає її в Telegram і зберігає запис агента в MongoDB.
 */
final class TelegramAgentLlmReplySender
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    private const LLM_MAX_CONTEXT_MESSAGES = 20;

    private const LLM_SYSTEM_PROMPT = <<<'PROMPT'
Ти корисний асистент. Відповідай українською, стисло та по суті.

Якщо користувач просить те саме, що роблять команди бота (/start, /help, /newchat, /keyboardon, /keyboardoff, /listchats, /friends, /addfriend, /edit_system_promt), виклич відповідний інструмент telegram_command_* замість імітації команди текстом. Після виконання такого інструменту не дублюй автоматичні повідомлення бота — коротко підтвердь або промовчи, якщо відповідь вже надіслана.
PROMPT;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly LLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly GroupRepository $groups,
        private readonly ActiveChatService $activeChat,
        private readonly TelegramPersistenceService $persistence,
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly InlineToolCallParser $inlineToolCallParser,
        private readonly ChatTitleGenerator $chatTitleGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed>|null $telegramMessage
     */
    public function sendLlmReplyForChat(
        int $telegramChatId,
        bool $isGroup,
        int $triggerTelegramMessageId,
        ?array $telegramMessage = null,
    ): void
    {
        if (!$this->telegram->isConfigured() || !$this->llm->isConfigured()) {
            $this->logger->error('Telegram або LLM не налаштовані, відповідь пропущено для chat {chat}', ['chat' => $telegramChatId]);

            return;
        }

        $replyToInbound = $this->messages->findOneByTelegramMessageIds($telegramChatId, $triggerTelegramMessageId);
        $logicalChat = $this->resolveLogicalChatForLlm($telegramChatId, $isGroup, $replyToInbound);
        if ($logicalChat === null) {
            $this->logger->warning('Не вдалося визначити активну бесіду для chat {chat}', ['chat' => $telegramChatId]);

            return;
        }

        if ($replyToInbound !== null && $replyToInbound->getChat() === null) {
            $this->messages->linkExistingMessageToChat($replyToInbound, $logicalChat);
        }

        $contextMessage = $telegramMessage ?? $this->buildTelegramMessageFallback(
            $telegramChatId,
            $isGroup,
            $triggerTelegramMessageId,
            $replyToInbound,
        );

        $this->invocationContext->begin($contextMessage, $replyToInbound);
        try {
            $answer = $this->llm->complete($this->buildPromptDtoForChat($logicalChat));
            if ($this->inlineToolCallParser->looksLikeToolCall($answer)) {
                $this->logger->warning('LLM повернув сирий виклик тулза замість тексту, пропускаємо відправку chat={chat}', [
                    'chat' => $telegramChatId,
                ]);

                return;
            }
            $answer = mb_substr(trim($answer), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH);
            if ($answer === '') {
                return;
            }
            $sent = $this->messageSender->send($telegramChatId, $answer, $isGroup);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $replyToInbound, $logicalChat);

            if (!$isGroup) {
                $this->chatTitleGenerator->updateTitleIfNeeded($logicalChat);
            }
            $this->logger->info('Відповідь агента надіслано в chat {chat}', ['chat' => $telegramChatId]);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка LLM/sendMessage chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ], $e);
        } finally {
            $this->invocationContext->clear();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTelegramMessageFallback(
        int $telegramChatId,
        bool $isGroup,
        int $triggerTelegramMessageId,
        ?\App\Document\Message $trigger,
    ): array {
        $chatType = $isGroup ? 'group' : 'private';
        $message = [
            'chat' => ['id' => $telegramChatId, 'type' => $chatType],
            'message_id' => $triggerTelegramMessageId,
        ];

        if ($isGroup) {
            $group = $trigger?->getGroup() ?? $this->groups->findOneBy(['telegramChatId' => $telegramChatId]);
            if ($group instanceof Group) {
                $message['chat']['title'] = $group->getTitle();
                if ($group->getType() !== null) {
                    $message['chat']['type'] = $group->getType();
                }
            }
        }

        $author = $trigger?->getAuthor();
        if ($author instanceof User) {
            $message['from'] = [
                'id' => $author->getTelegramUserId(),
                'first_name' => $author->getFirstName(),
                'last_name' => $author->getLastName(),
                'username' => $author->getUsername(),
            ];
        }

        $text = $trigger?->getText();
        if ($text !== null && trim($text) !== '') {
            $message['text'] = $text;
        }

        return $message;
    }

    private function resolveLogicalChatForLlm(int $telegramChatId, bool $isGroup, ?\App\Document\Message $trigger): ?Chat
    {
        if ($trigger?->getChat() !== null) {
            return $trigger->getChat();
        }

        if ($isGroup) {
            $group = $this->groups->findOneBy(['telegramChatId' => $telegramChatId]);
            if ($group === null) {
                return null;
            }

            return $this->activeChat->ensureForGroup($group);
        }

        $author = $trigger?->getAuthor();
        if ($author === null) {
            return null;
        }

        return $this->activeChat->ensureForUser($author);
    }

    private function buildPromptDtoForChat(Chat $chat): PromptDTO
    {
        return new PromptDTO(
            messages: $this->buildChatMessagesForLlm($chat),
            systemPrompt: $this->buildSystemPromptForChat($chat),
        );
    }

    private function buildSystemPromptForChat(Chat $chat): string
    {
        $custom = $chat->getSystemPrompt();
        $base = self::LLM_SYSTEM_PROMPT
            . $this->buildFriendsContextSuffix($chat);

        if ($custom === null || trim($custom) === '') {
            return $base;
        }

        return $base
            . "\n\nДодаткові інструкції для цієї бесіди:\n"
            . trim($custom);
    }

    private function buildFriendsContextSuffix(Chat $chat): string
    {
        $author = $this->invocationContext->getInbound()?->getAuthor();
        if (!$author instanceof User) {
            return '';
        }

        // Only for private chats: in groups, "friends" are ambiguous.
        if ($chat->getGroup() !== null) {
            return '';
        }

        $friends = array_values(array_filter(
            $author->getFriends()->toArray(),
            static fn (mixed $u): bool => $u instanceof User,
        ));

        if ($friends === []) {
            return "\n\nДрузі користувача: (порожньо).";
        }

        usort($friends, static function (User $a, User $b): int {
            $ua = mb_strtolower($a->getUsername() ?? '');
            $ub = mb_strtolower($b->getUsername() ?? '');

            return $ua <=> $ub;
        });

        $lines = [];
        foreach ($friends as $f) {
            $handle = $f->getUsername();
            $handle = $handle !== null && trim($handle) !== '' ? '@' . ltrim(trim($handle), '@') : '(no username)';
            $name = trim(($f->getFirstName() ?? '') . ' ' . ($f->getLastName() ?? ''));
            if ($name === '') $name = '—';
            $lines[] = sprintf('- %s (%s)', $handle, $name);
        }

        return "\n\nДрузі користувача:\n" . implode("\n", $lines);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function buildChatMessagesForLlm(Chat $chat): array
    {
        $stored = $this->messages->findByLogicalChatOrderedForContext($chat, self::LLM_MAX_CONTEXT_MESSAGES);

        $messages = [];
        foreach ($stored as $doc) {
            $t = $doc->getText();
            if ($t === null || trim($t) === '') {
                continue;
            }
            $role = match ($doc->getType()) {
                MessageType::UserPrivate, MessageType::UserGroup => 'user',
                MessageType::AgentPrivate, MessageType::AgentGroup => 'assistant',
            };
            if ($role === 'assistant' && $this->inlineToolCallParser->looksLikeToolCall($t)) {
                continue;
            }
            $messages[] = [
                'role' => $role,
                'content' => $t,
            ];
        }

        return $messages;
    }
}

