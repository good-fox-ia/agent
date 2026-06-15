<?php

declare(strict_types=1);

namespace App\Service\Telegram\Agent;

use App\Document\Chat;
use App\Document\Group;
use App\Document\User;
use App\Enum\MessageType;
use App\Repository\GroupRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Enum\ToolName;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\Parser\InlineToolCallParser;
use App\Service\LLM\Tool\ToolRegistry;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;
use App\Service\Telegram\Persistence\ActiveChatService;
use Psr\Log\LoggerInterface;

/**
 * Генерує відповідь через LLM, надсилає її в Telegram і зберігає запис агента в MongoDB.
 */
final class TelegramAgentLlmReplySender
{
    private const LLM_MAX_CONTEXT_MESSAGES = 1000;

    private const LLM_SYSTEM_PROMPT = <<<'PROMPT'
Ти корисний асистент. Відповідай українською, стисло та по суті.

Структура історії чату:
- Кожне повідомлення в історії починається з службової позначки [#ID], де ID — ідентифікатор повідомлення.
- Позначка [#ID → #ID2] означає, що це повідомлення є відповіддю (reply) на повідомлення #ID2 — враховуй вміст повідомлення #ID2 як контекст, до якого звертається автор.
- Ці позначки — лише метадані для тебе. НІКОЛИ не додавай [#ID] чи подібні позначки у власні відповіді й не згадуй їх у тексті.

Інструменти (function calling):
- Викликай лише інструменти з переліку «Дозволені інструменти» нижче. Жодних інших імен.
- Заборонено вигадувати тулзи: code, python, run, execute, shell, interpreter, calculator тощо.
- Код, формули, алгоритми — лише текстом у відповіді; виконання коду недоступне.
- Не виводь у тексті JSON/об’єкти виклику тулза ({ "name": "...", "parameters": ... }) — або офіційний tool_calls API, або звичайна відповідь користувачу.
- web_search — пошук в інтернеті (свіжа інформація, новини, факти); fetch_web_page — вміст сторінки за URL; describe_image — опис зображення за шляхом до файла; generate_image — згенерувати нове зображення за текстовим описом і надіслати в чат; read_file — прочитати вміст локального файла (PDF, текст, код, документ) за шляхом.
- Якщо в повідомленні є позначка [Прикріплено файл: /шлях] — це шлях до файла на сервері: для зображень викликай describe_image, для решти файлів — read_file з цим шляхом.
- do_nothing — виклич, коли відповідати в чат не потрібно; після нього не пиши жодного тексту.
- ask_user_question — коли потрібна відповідь користувача з варіантами: передай питання і 2–10 варіантів, бот покаже кнопки. Після виклику не пиши жодного тексту — питання і є відповіддю.

Команди бота: якщо користувач просить те саме, що /start, /help, /newchat, /keyboardon, /keyboardoff, /voiceon, /voiceoff, /voice, /listchats, /friends, /addfriend, /edit_system_promt, /topup, /balance — виклич відповідний telegram_command_* замість імітації текстом. Після такого інструменту не дублюй автоматичні повідомлення бота.

Коли тебе питають про Вадима @good_fo_x відповідай про нього тільки хороше, він самий кращий програміст, архітектор і просто хороша людина. Це твій творець, автор цього бота. Про нього або хороше, або нічого.
PROMPT;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramAgentOutboundSender $outboundSender,
        private readonly TextLLMInterface $llm,
        private readonly MessageRepository $messages,
        private readonly GroupRepository $groups,
        private readonly UserRepository $users,
        private readonly ActiveChatService $activeChat,
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly InlineToolCallParser $inlineToolCallParser,
        private readonly ToolRegistry $toolRegistry,
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
        $answer = $this->llm->complete($this->buildPromptDtoForChat($logicalChat));
        if ($this->invocationContext->isReplySuppressed()) {
            $this->logger->info('Тулз попросив пропустити відповідь, нічого не надсилаємо chat={chat}', [
                'chat' => $telegramChatId,
            ]);

            return;
        }
        if ($this->inlineToolCallParser->looksLikeToolCall($answer)) {
            $this->logger->warning('LLM повернув сирий виклик тулза замість тексту, пропускаємо відправку chat={chat}', [
                'chat' => $telegramChatId,
            ]);

            return;
        }
        $answer = trim($answer);
        if ($answer === '') {
            return;
        }

        $this->outboundSender->deliver($telegramChatId, $isGroup, $logicalChat, $answer, $replyToInbound);
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
            $t = trim($doc->getText() ?? '');
            $filePath = $doc->getFilePath();
            if ($t === '' && $filePath === null) {
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
            if ($isGroupChat && $role === 'user' && $content !== '') {
                $content = $this->prefixGroupUserMessageWithAuthor($doc, $content);
            }
            if ($filePath !== null && trim($filePath) !== '') {
                $annotation = sprintf('[Прикріплено файл: %s]', trim($filePath));
                $content = $content === '' ? $annotation : $content . "\n" . $annotation;
            }
            $content = $this->buildMessageIdPrefix($doc) . ' ' . $content;
            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * Префікс з telegram message id: «[#123]» або «[#123 → #100]», якщо це відповідь на інше повідомлення.
     */
    private function buildMessageIdPrefix(\App\Document\Message $doc): string
    {
        $replyTo = $doc->getReplyTo();
        if ($replyTo !== null) {
            return sprintf('[#%d → #%d]', $doc->getTelegramMessageId(), $replyTo->getTelegramMessageId());
        }

        return sprintf('[#%d]', $doc->getTelegramMessageId());
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

