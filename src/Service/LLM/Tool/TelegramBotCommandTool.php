<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\TelegramBotCommand;
use App\Enum\ToolName;
use App\Service\Telegram\Command\CommandProcessor;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;

final class TelegramBotCommandTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramBotCommand $command,
        private readonly ToolName $toolName,
        private readonly string $description,
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly CommandProcessor $commandProcessor,
        private readonly ?string $usernameArgument = null,
    ) {}

    public function getName(): ToolName
    {
        return $this->toolName;
    }

    public function getDescription(): array
    {
        $parameters = [
            'type' => 'object',
            'properties' => (object) [],
            'required' => [],
        ];

        if ($this->usernameArgument !== null) {
            $parameters = [
                'type' => 'object',
                'properties' => [
                    $this->usernameArgument => [
                        'type' => 'string',
                        'description' => 'Telegram username of the friend to add, with or without @.',
                    ],
                ],
                'required' => [$this->usernameArgument],
            ];
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => $this->description,
                'parameters' => $parameters,
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        if (!$this->invocationContext->isActive()) {
            throw new \RuntimeException('Telegram command tools are only available during a Telegram chat LLM reply.');
        }

        $commandArgs = $this->resolveCommandArguments($arguments);
        $telegramMessage = $commandArgs !== ''
            ? TelegramMessageHelper::withCommandTextAndArgs(
                $this->invocationContext->getTelegramMessage(),
                $this->command,
                $commandArgs,
            )
            : TelegramMessageHelper::withCommandText(
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

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveCommandArguments(array $arguments): string
    {
        if ($this->usernameArgument === null) {
            return '';
        }

        $value = $arguments[$this->usernameArgument] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $this->usernameArgument));
        }

        return trim($value);
    }
}
