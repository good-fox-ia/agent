<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;

/**
 * Ставить користувачу питання з варіантами відповіді (inline-клавіатура, callback_data: aq:{index}).
 * Відповідь обробляє AskQuestionAnswerProcessor і продовжує діалог через LLM.
 */
final class AskUserQuestionTool implements ToolInterface
{
    public const CALLBACK_PREFIX = 'aq:';

    private const MIN_OPTIONS = 2;

    private const MAX_OPTIONS = 10;

    /** Telegram обмежує текст кнопки; довші підписи обрізаємо. */
    private const MAX_BUTTON_LABEL_LENGTH = 64;

    public function __construct(
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
    ) {}

    public function getName(): ToolName
    {
        return ToolName::ASK_USER_QUESTION;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Ask the user a question with predefined answer options shown as inline buttons in the chat. Use when you need the user to choose one of several options to continue. After calling this tool, do not write any reply text — the question message is the reply.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => [
                            'type' => 'string',
                            'description' => 'The question text to show to the user.',
                        ],
                        'options' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Possible answers (2-10 short strings), each shown as a button.',
                        ],
                    ],
                    'required' => ['question', 'options'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        if (!$this->invocationContext->isActive()) {
            throw new \RuntimeException('ask_user_question is only available during a Telegram chat LLM reply.');
        }

        $question = isset($arguments['question']) && is_string($arguments['question'])
            ? trim($arguments['question'])
            : '';
        if ($question === '') {
            throw new \InvalidArgumentException('question is required.');
        }

        $options = $this->normalizeOptions($arguments['options'] ?? null);

        $telegramMessage = $this->invocationContext->getTelegramMessage();
        $telegramChatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        if ($telegramChatId === 0) {
            throw new \RuntimeException('Cannot resolve Telegram chat id from invocation context.');
        }
        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);

        $keyboard = [];
        foreach ($options as $index => $option) {
            $keyboard[] = [[
                'text' => mb_substr($option, 0, self::MAX_BUTTON_LABEL_LENGTH),
                'callback_data' => self::CALLBACK_PREFIX . $index,
            ]];
        }

        $sent = $this->messageSender->send(
            $telegramChatId,
            htmlspecialchars($question, ENT_NOQUOTES),
            $isGroup,
            ['reply_markup' => ['inline_keyboard' => $keyboard]],
        );

        // У історії зберігаємо питання разом із варіантами, щоб LLM бачила повний контекст.
        $historyText = $question . "\nВаріанти відповіді: " . implode(' | ', $options);
        $this->persistence->recordAgentOutboundFromTelegramSend(
            $sent + ['text' => $historyText],
            $isGroup,
            $this->invocationContext->getInbound(),
        );

        // Питання з кнопками — єдина відповідь; фінальний текст LLM не надсилаємо.
        $this->invocationContext->suppressReply();

        return json_encode([
            'ok' => true,
            'sent' => true,
            'note' => 'Question with answer buttons was sent to the chat. Do not write any reply text now; the conversation continues when the user picks an option.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private function normalizeOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('options must be an array of strings.');
        }

        $options = [];
        foreach ($raw as $option) {
            if (!is_string($option)) {
                continue;
            }
            $option = trim($option);
            if ($option !== '') {
                $options[] = $option;
            }
        }

        $count = count($options);
        if ($count < self::MIN_OPTIONS || $count > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException(sprintf(
                'options must contain between %d and %d non-empty strings, got %d.',
                self::MIN_OPTIONS,
                self::MAX_OPTIONS,
                $count,
            ));
        }

        return $options;
    }
}
