<?php

declare(strict_types=1);

namespace App\Service\LLM\Adapter;

use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Tool\ToolRegistry;

final class GroqPromptAdapter implements PromptAdapterInterface
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
    ) {}

    public function adapt(PromptDTO $prompt): array
    {
        $messages = [];

        $system = $prompt->getSystemPrompt();
        if ($system !== null && $system !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $system,
            ];
        }

        foreach ($prompt->getMessages() as $row) {
            $messages[] = [
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
            ];
        }

        $body = [
            'messages' => $messages,
        ];

        $tools = $prompt->getTools();
        if ($tools !== []) {
            $body['tools'] = $this->toolRegistry->getDefinitionsFor($tools);
            $body['tool_choice'] = 'auto';
        }

        return $body;
    }
}
