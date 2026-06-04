<?php

declare(strict_types=1);

namespace App\Service\Telegram\Agent;

use App\Document\Chat;
use App\Document\Group;
use App\Document\User;
use App\Enum\MessageType;
use App\Repository\GroupRepository;
use App\Repository\MessageRepository;
use App\Enum\ToolName;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\Parser\InlineToolCallParser;
use App\Service\LLM\Tool\ToolRegistry;
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

    private const LLM_MAX_CONTEXT_MESSAGES = 1000;

    /** Спроби відправки + повторна генерація після помилки Telegram HTML. */
    private const SEND_FIX_MAX_ATTEMPTS = 3;

    private const LLM_SYSTEM_PROMPT = <<<'PROMPT'
Ти корисний асистент. Відповідай українською, стисло та по суті.

Інструменти (function calling):
- Викликай лише інструменти з переліку «Дозволені інструменти» нижче. Жодних інших імен.
- Заборонено вигадувати тулзи: code, python, run, execute, shell, interpreter, calculator, search тощо.
- Код, формули, алгоритми — лише текстом у відповіді; виконання коду недоступне.
- Не виводь у тексті JSON/об’єкти виклику тулза ({ "name": "...", "parameters": ... }) — або офіційний tool_calls API, або звичайна відповідь користувачу.

HTML для Telegram (parse_mode HTML):
- Дозволені теги: <b>, <i>, <u>, <s>, <code>, <pre>, <a href="URL">, <tg-spoiler>.
- Кожен відкритий тег закрий парним: <b>…</b>, <code>…</code>, <pre>…</pre>. Незакриті теги ламають відправку.
- Перед відправкою перевір, що кількість відкривних і закривних тегів збігається для кожного типу.
- Код у <code>одним рядком</code> або багаторядковий у <pre>…</pre>; не змішуй незакриті фрагменти.
- Не використовуй Markdown (*, _, `, ```). Символи &, <, > поза тегами — &amp;, &lt;, &gt; (наприклад «a &lt; b»).

Команди бота: якщо користувач просить те саме, що /start, /help, /newchat, /keyboardon, /keyboardoff, /listchats, /friends, /addfriend, /edit_system_promt — виклич відповідний telegram_command_* замість імітації текстом. Після такого інструменту не дублюй автоматичні повідомлення бота.
PROMPT;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly TextLLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly GroupRepository $groups,
        private readonly ActiveChatService $activeChat,
        private readonly TelegramPersistenceService $persistence,
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly InlineToolCallParser $inlineToolCallParser,
        private readonly ToolRegistry $toolRegistry,
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
            $this->completeAndSendLlmReply($logicalChat, $telegramChatId, $isGroup, $replyToInbound);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка LLM/sendMessage chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ], $e);
        } finally {
            $this->invocationContext->clear();
        }
    }

    private function completeAndSendLlmReply(
        Chat $logicalChat,
        int $telegramChatId,
        bool $isGroup,
        ?\App\Document\Message $replyToInbound,
    ): void {
        $basePrompt = $this->buildPromptDtoForChat($logicalChat);
        /** @var list<array{role: string, content: string}> $retryMessages */
        $retryMessages = [];

        for ($attempt = 1; $attempt <= self::SEND_FIX_MAX_ATTEMPTS; $attempt++) {
            $prompt = $retryMessages === []
                ? $basePrompt
                : new PromptDTO(
                    messages: [...$basePrompt->getMessages(), ...$retryMessages],
                    systemPrompt: $basePrompt->getSystemPrompt(),
                );

            $answer = $this->llm->complete($prompt);
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

            try {
                $sent = $this->messageSender->send($telegramChatId, $answer, $isGroup);
            } catch (\Throwable $e) {
                if (!$this->isRetriableTelegramSendError($e) || $attempt >= self::SEND_FIX_MAX_ATTEMPTS) {
                    throw $e;
                }
                $this->logger->warning(
                    'Telegram відхилив HTML, повторний запит до LLM chat={chat} attempt={attempt}: {error}',
                    [
                        'chat' => $telegramChatId,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ],
                );
                $retryMessages = [
                    ['role' => 'assistant', 'content' => $answer],
                    ['role' => 'user', 'content' => $this->buildSendFailureCorrectionPrompt($e)],
                ];

                continue;
            }

            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $replyToInbound, $logicalChat);

            if (!$isGroup) {
                $this->chatTitleGenerator->updateTitleIfNeeded($logicalChat);
            }
            $this->logger->info('Відповідь агента надіслано в chat {chat}', ['chat' => $telegramChatId]);

            return;
        }
    }

    private function buildSendFailureCorrectionPrompt(\Throwable $e): string
    {
        $detail = $this->extractTelegramErrorDescription($e->getMessage());

        return 'Telegram API відхилив попередню відповідь (parse_mode HTML): '
            . $detail
            . "\n\nВиправ розмітку (закрий усі HTML-теги, екрануй &, <, > поза тегами) і надішли лише виправлений текст відповіді користувачу — без пояснень про помилку.";
    }

    private function extractTelegramErrorDescription(string $message): string
    {
        if (preg_match('/"description":"((?:[^"\\\\]|\\\\.)*)"/', $message, $matches) === 1) {
            return stripcslashes($matches[1]);
        }

        return $message;
    }

    private function isRetriableTelegramSendError(\Throwable $e): bool
    {
        if ($e->getCode() !== 400) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, "can't parse entities")
            || str_contains($message, 'parse entities')
            || str_contains($message, 'unsupported start tag');
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
            . $this->buildAllowedToolsSuffix()
            . $this->buildFriendsContextSuffix($chat);

        if ($custom === null || trim($custom) === '') {
            return $base;
        }

        return $base
            . "\n\nДодаткові інструкції для цієї бесіди:\n"
            . trim($custom);
    }

    private function buildAllowedToolsSuffix(): string
    {
        $names = array_map(
            static fn (ToolName $name): string => $name->value,
            $this->toolRegistry->getAllNames(),
        );
        sort($names);

        return "\n\nДозволені інструменти: "
            . implode(', ', $names)
            . '.';
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
        $isGroupChat = $chat->getGroup() !== null;

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
            $content = $t;
            if ($isGroupChat && $role === 'user') {
                $content = $this->prefixGroupUserMessageWithAuthor($doc, $t);
            }
            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $messages;
    }

    private function prefixGroupUserMessageWithAuthor(\App\Document\Message $doc, string $text): string
    {
        $label = $this->formatGroupAuthorLabel($doc->getAuthor());
        if ($label === null) {
            return $text;
        }

        return $label . ': ' . $text;
    }

    private function formatGroupAuthorLabel(?User $author): ?string
    {
        if (!$author instanceof User) {
            return null;
        }

        $username = $author->getUsername();
        if ($username !== null && trim($username) !== '') {
            return '@' . ltrim(trim($username), '@');
        }

        $name = trim(($author->getFirstName() ?? '') . ' ' . ($author->getLastName() ?? ''));
        if ($name !== '') {
            return $name;
        }

        return null;
    }
}

