<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;

/**
 * «Пропустити дію»: LLM викликає цей тулз, коли відповідати в чат не потрібно.
 * Ставить прапорець у контексті виклику — фінальна відповідь LLM не надсилається.
 */
final class DoNothingTool implements ToolInterface
{
    public function __construct(private readonly TelegramLlmInvocationContext $invocationContext) {}

    public function getName(): ToolName
    {
        return ToolName::DO_NOTHING;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Skip replying to the current message. Call this when no answer should be sent to the chat (e.g. the message does not require a response). After calling this tool, do not write any reply text.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        if ($this->invocationContext->isActive()) {
            $this->invocationContext->suppressReply();
        }

        return json_encode([
            'ok' => true,
            'action' => 'reply_skipped',
            'note' => 'No reply will be sent to the chat. Do not write any answer text.',
        ], JSON_THROW_ON_ERROR);
    }
}
