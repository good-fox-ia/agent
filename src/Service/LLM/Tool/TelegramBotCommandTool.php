<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\TelegramBotCommand;
use App\Enum\ToolName;
use App\Service\Telegram\Command\CommandProcessor;
use App\Service\Telegram\TelegramLlmInvocationContext;
use App\Service\Telegram\TelegramMessageHelper;

final class TelegramBotCommandTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramBotCommand $command,
        private readonly ToolName $toolName,
        private readonly string $description,
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly CommandProcessor $commandProcessor,
    ) {}

    public function getName(): ToolName
    {
        return $this->toolName;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => $this->description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        if (!$this->invocationContext->isActive()) {
            throw new \RuntimeException('Telegram command tools are only available during a Telegram chat LLM reply.');
        }

        $telegramMessage = TelegramMessageHelper::withCommandText(
            $this->invocationContext->getTelegramMessage(),
            $this->command,
        );

        $processed = $this->commandProcessor->process(
            $this->command,
            $telegramMessage,
            $this->invocationContext->getInbound(),
        );

        return json_encode([
            'ok' => $processed,
            'command' => $this->command->value,
            'slash' => $this->command->asSlash(),
            'user_notified' => $processed,
            'hint' => $processed
                ? 'Command executed in Telegram. Do not repeat the bot automatic confirmation to the user.'
                : 'Command was not handled.',
        ], JSON_THROW_ON_ERROR);
    }
}
