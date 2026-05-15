<?php

declare(strict_types=1);

namespace App\Service\LLM\DTO;

use App\Enum\ToolName;

final readonly class PromptDTO
{
    /** @var list<ToolName> */
    private array $tools;

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param list<ToolName>|null                        $tools null — усі тулзи; [] — без тулзів
     */
    public function __construct(
        private array $messages = [],
        private ?string $systemPrompt = null,
        ?array $tools = null,
    ) {
        $this->tools = $tools ?? array_values(ToolName::cases());
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    /**
     * @return list<ToolName>
     */
    public function getTools(): array
    {
        return $this->tools;
    }
}
