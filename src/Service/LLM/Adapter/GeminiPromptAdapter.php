<?php

declare(strict_types=1);

namespace App\Service\LLM\Adapter;

use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Tool\ToolRegistry;

final class GeminiPromptAdapter implements PromptAdapterInterface
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
    ) {}

    public function adapt(PromptDTO $prompt): array
    {
        $contents = [];

        foreach ($prompt->getMessages() as $row) {
            $role = (string) $row['role'];
            $content = (string) $row['content'];

            $contents[] = [
                'role' => $this->mapRole($role),
                'parts' => [['text' => $content]],
            ];
        }

        $body = [
            'contents' => $contents,
        ];

        $system = $prompt->getSystemPrompt();
        if ($system !== null && $system !== '') {
            $body['systemInstruction'] = [
                'parts' => [['text' => $system]],
            ];
        }

        $tools = $prompt->getTools();
        if ($tools !== []) {
            $body['tools'] = [
                [
                    'functionDeclarations' => $this->toFunctionDeclarations(
                        $this->toolRegistry->getDefinitionsFor($tools),
                    ),
                ],
            ];
        }

        return $body;
    }

    /**
     * @param list<array<string, mixed>> $openAiTools
     *
     * @return list<array<string, mixed>>
     */
    private function toFunctionDeclarations(array $openAiTools): array
    {
        $declarations = [];
        foreach ($openAiTools as $tool) {
            $function = $tool['function'] ?? null;
            if (!is_array($function)) {
                continue;
            }

            $name = $function['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $declarations[] = [
                'name' => $name,
                'description' => $function['description'] ?? '',
                'parameters' => $function['parameters'] ?? ['type' => 'object', 'properties' => []],
            ];
        }

        return $declarations;
    }

    private function mapRole(string $role): string
    {
        return match ($role) {
            'assistant' => 'model',
            default => 'user',
        };
    }
}
