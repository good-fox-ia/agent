<?php

declare(strict_types=1);

namespace App\Service\LLM\DTO;


final readonly class PromptDTO
{
    public function __construct(
        private array $messages = [],
        private array $tools = [],
        private ?string $systemPrompt = null,
    ) {}

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }
}
