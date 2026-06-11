<?php

declare(strict_types=1);

namespace App\Service\Telegram\Callback\Handler;

use App\Service\LLM\Tool\AskUserQuestionTool;
use App\Service\Telegram\Agent\TelegramAgentLlmReplySender;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Callback\CallbackDTO;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use Psr\Log\LoggerInterface;

/**
 * Обробляє відповідь на питання від LLM (callback_data: aq:{index}).
 * Записує вибір як вхідне повідомлення користувача і продовжує діалог через LLM.
 */
final class AskQuestionAnswerProcessor
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramPersistenceService $persistence,
        private readonly TelegramAgentLlmReplySender $agentLlmReplySender,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(string $data): bool
    {
        return str_starts_with($data, AskUserQuestionTool::CALLBACK_PREFIX);
    }

    public function process(CallbackDTO $callback): void
    {
        if (!$this->handles($callback->data)) return;

        try {
            $answer = $this->resolveAnswerText($callback);
            if ($answer === null) {
                $this->telegram->answerCallbackQuery($callback->callbackId, 'Варіант не знайдено');

                return;
            }

            $this->telegram->answerCallbackQuery($callback->callbackId, $answer);

            // Прибираємо клавіатуру і фіксуємо вибір у тексті питання.
            $question = trim((string) ($callback->message['text'] ?? ''));
            if ($callback->messageId > 0 && $question !== '') {
                $this->telegram->editMessageText(
                    $callback->chatId,
                    $callback->messageId,
                    $question . "\n\n✅ " . $answer,
                );
            }

            $isGroup = $callback->chatId < 0;
            // Натискання кнопки не створює повідомлення в Telegram — використовуємо
            // синтетичний від'ємний message_id, що не конфліктує з реальними id.
            $syntheticMessageId = -$callback->messageId;
            $inboundPayload = [
                'chat' => $callback->message['chat'] ?? [
                    'id' => $callback->chatId,
                    'type' => $isGroup ? 'group' : 'private',
                ],
                'from' => ['id' => $callback->fromId],
                'message_id' => $syntheticMessageId,
                'text' => $answer,
            ];

            $this->persistence->syncParticipantsFromTelegramMessage($inboundPayload);
            $this->persistence->recordInboundUserMessage($inboundPayload);

            $this->telegram->sendChatAction($callback->chatId, 'typing');
            $this->agentLlmReplySender->sendLlmReplyForChat(
                $callback->chatId,
                $isGroup,
                $syntheticMessageId,
                $inboundPayload,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка callback відповіді на питання: {error}', ['error' => $e->getMessage()], $e);
            if ($callback->callbackId !== '') {
                try {
                    $this->telegram->answerCallbackQuery($callback->callbackId);
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }

    /**
     * Текст натиснутої кнопки шукаємо в inline-клавіатурі повідомлення за callback_data.
     */
    private function resolveAnswerText(CallbackDTO $callback): ?string
    {
        $keyboard = $callback->message['reply_markup']['inline_keyboard'] ?? null;
        if (!is_array($keyboard)) {
            return null;
        }

        foreach ($keyboard as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $button) {
                if (!is_array($button)) {
                    continue;
                }
                if (($button['callback_data'] ?? null) === $callback->data) {
                    $text = trim((string) ($button['text'] ?? ''));

                    return $text !== '' ? $text : null;
                }
            }
        }

        return null;
    }
}
