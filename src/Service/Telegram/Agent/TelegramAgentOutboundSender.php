<?php

declare(strict_types=1);

namespace App\Service\Telegram\Agent;

use App\Document\Chat;
use App\Document\Message;
use App\Document\User;
use App\Repository\UserRepository;
use App\Service\LLM\Client\Interface\TextLLMInterface;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\Telegram\Api\TelegramHtmlFormatter;
use App\Service\Telegram\Chat\Content\ChatTitleGenerator;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use App\Service\Telegram\Voice\VoiceReplySender;
use Psr\Log\LoggerInterface;

/**
 * Доставляє текстову відповідь агента в Telegram: voice/text, HTML-форматування, відправка, збереження.
 */
final class TelegramAgentOutboundSender
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    /** Спроби format-pass + повтор після помилки Telegram HTML. */
    private const FORMAT_SEND_MAX_ATTEMPTS = 3;

    /** Довгі plain-text відповіді у fallback обгортаються в expandable blockquote. */
    private const FALLBACK_BLOCKQUOTE_MIN_LENGTH = 500;

    private const FORMAT_SYSTEM_PROMPT = <<<'PROMPT'
Ти форматуєш текст відповіді для Telegram (parse_mode HTML).
Отримуєш сирий текст без контексту — лише розмітка; зміст, факти та формулювання не змінюй.

Дозволені теги: <b>, <i>, <u>, <s>, <code>, <pre>, <a href="URL">, <tg-spoiler>, <blockquote expandable>, </blockquote>.
- Виділяй важливе <b>, код — <code> (один рядок) або <pre> (багаторядковий), посилання — <a href="...">.
- Довгі цитати, великі фрагменти коду або другорядні деталі обгорни в <blockquote expandable>…</blockquote>.
  Короткі відповіді (1–3 речення) — без blockquote.
- Кожен відкритий тег закрий парним. Не використовуй Markdown (*, _, `, ```).
- Символи &, <, > поза тегами — &amp;, &lt;, &gt; (наприклад «a &lt; b»).
- Прибери службові позначки [#123] та [#123 → #456], якщо вони залишились у тексті.
- Поверни лише готовий HTML-текст без пояснень, преамбул і без обгортки в ```.
- Максимальна довжина — 4096 символів.
PROMPT;

    public function __construct(
        private readonly UserMessageSender $messageSender,
        private readonly TextLLMInterface $llm,
        private readonly VoiceReplySender $voiceReplySender,
        private readonly UserRepository $users,
        private readonly TelegramPersistenceService $persistence,
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly ChatTitleGenerator $chatTitleGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function deliver(
        int $telegramChatId,
        bool $isGroup,
        Chat $logicalChat,
        string $rawText,
        ?Message $replyToInbound,
    ): void {
        $rawText = TelegramHtmlFormatter::stripMessageIdMarkers(trim($rawText));
        if ($rawText === '') {
            return;
        }

        if ($this->trySendVoiceReply($logicalChat, $telegramChatId, $isGroup, $replyToInbound, $rawText)) {
            return;
        }

        $this->sendTextReply($telegramChatId, $isGroup, $logicalChat, $rawText, $replyToInbound);
    }

    private function sendTextReply(
        int $telegramChatId,
        bool $isGroup,
        Chat $logicalChat,
        string $rawText,
        ?Message $replyToInbound,
    ): void {
        /** @var list<array{role: string, content: string}> $retryMessages */
        $retryMessages = [];

        for ($attempt = 1; $attempt <= self::FORMAT_SEND_MAX_ATTEMPTS; $attempt++) {
            $formatted = $this->formatForTelegram($rawText, $retryMessages);
            $formatted = TelegramHtmlFormatter::truncate($formatted, self::TELEGRAM_MAX_MESSAGE_LENGTH);

            try {
                $sent = $this->messageSender->send($telegramChatId, $formatted, $isGroup);
            } catch (\Throwable $e) {
                if (!$this->isRetriableTelegramSendError($e) || $attempt >= self::FORMAT_SEND_MAX_ATTEMPTS) {
                    throw $e;
                }
                $this->logger->warning(
                    'Telegram відхилив HTML, повторний format-pass chat={chat} attempt={attempt}: {error}',
                    [
                        'chat' => $telegramChatId,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ],
                );
                $retryMessages = [
                    ['role' => 'assistant', 'content' => $formatted],
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

    /**
     * @param list<array{role: string, content: string}> $retryMessages
     */
    private function formatForTelegram(string $rawText, array $retryMessages): string
    {
        if (!$this->llm->isConfigured()) {
            return $this->fallbackFormat($rawText);
        }

        $messages = [
            ['role' => 'user', 'content' => $rawText],
            ...$retryMessages,
        ];

        try {
            $formatted = $this->llm->complete(
                new PromptDTO(
                    messages: $messages,
                    systemPrompt: self::FORMAT_SYSTEM_PROMPT,
                    tools: [],
                ),
                ['temperature' => 0.2],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Format-pass LLM failed, fallback на plain HTML chat: {error}', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackFormat($rawText);
        }

        $formatted = $this->normalizeFormattedOutput($formatted);
        if ($formatted === '') {
            return $this->fallbackFormat($rawText);
        }

        return $formatted;
    }

    private function normalizeFormattedOutput(string $formatted): string
    {
        $formatted = trim($formatted);
        if (preg_match('/^```(?:html)?\s*(.*?)```$/s', $formatted, $matches) === 1) {
            $formatted = trim($matches[1]);
        }

        return TelegramHtmlFormatter::stripMessageIdMarkers($formatted);
    }

    private function fallbackFormat(string $plainText): string
    {
        if (mb_strlen($plainText) >= self::FALLBACK_BLOCKQUOTE_MIN_LENGTH) {
            return TelegramHtmlFormatter::formatPlainTextAsExpandableBlockquote(
                $plainText,
                self::TELEGRAM_MAX_MESSAGE_LENGTH,
            );
        }

        return htmlspecialchars($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Якщо в користувача увімкнено голосові відповіді — озвучує текст через TTS і надсилає voice message.
     * Повертає true, коли голосову відповідь надіслано (текст уже не відправляємо).
     */
    private function trySendVoiceReply(
        Chat $logicalChat,
        int $telegramChatId,
        bool $isGroup,
        ?Message $replyToInbound,
        string $answer,
    ): bool {
        $user = $this->resolveReplyRecipientUser($telegramChatId, $isGroup, $replyToInbound);
        if ($user === null || !$user->isVoiceReplyEnabled() || !$this->voiceReplySender->isAvailable()) {
            return false;
        }

        try {
            $sent = $this->voiceReplySender->sendVoiceReply($telegramChatId, $answer, voice: $user->getTtsVoice());
        } catch (\Throwable $e) {
            $this->logger->warning('Не вдалося надіслати голосову відповідь chat={chat}, fallback на текст: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Voice message не має поля text — зберігаємо текст відповіді, щоб не втратити контекст LLM.
        $this->persistence->recordAgentOutboundFromTelegramSend(
            $sent + ['text' => $answer],
            $isGroup,
            $replyToInbound,
            $logicalChat,
        );

        if (!$isGroup) {
            $this->chatTitleGenerator->updateTitleIfNeeded($logicalChat);
        }
        $this->logger->info('Голосову відповідь агента надіслано в chat {chat}', ['chat' => $telegramChatId]);

        return true;
    }

    /**
     * Користувач, чиї налаштування голосу застосовуємо: автор тригер-повідомлення,
     * а у приватному чаті — користувач за telegram chat id.
     */
    private function resolveReplyRecipientUser(
        int $telegramChatId,
        bool $isGroup,
        ?Message $replyToInbound,
    ): ?User {
        $author = $replyToInbound?->getAuthor()
            ?? $this->invocationContext->getInbound()?->getAuthor();

        if ($author instanceof User) {
            return $author;
        }

        return $isGroup ? null : $this->users->findOneByTelegramUserId($telegramChatId);
    }

    private function buildSendFailureCorrectionPrompt(\Throwable $e): string
    {
        $detail = $this->extractTelegramErrorDescription($e->getMessage());

        return 'Telegram API відхилив попередню відповідь (parse_mode HTML): '
            . $detail
            . "\n\nВиправ розмітку (закрий усі HTML-теги, екрануй &, <, > поза тегами) і надішли лише виправлений HTML-текст — без пояснень про помилку.";
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
}
