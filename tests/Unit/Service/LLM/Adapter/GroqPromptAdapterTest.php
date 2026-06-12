<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LLM\Adapter;

use App\Enum\ToolName;
use App\Service\LLM\Adapter\GroqPromptAdapter;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Tool\ToolRegistry;
use App\Tests\Support\FakeTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GroqPromptAdapterTest extends TestCase
{
    public function testMapsMessagesAsIs(): void
    {
        $adapter = new GroqPromptAdapter($this->emptyRegistry());

        $body = $adapter->adapt(new PromptDTO(
            messages: [
                ['role' => 'user', 'content' => 'Привіт'],
                ['role' => 'assistant', 'content' => 'Вітаю!'],
            ],
            tools: [],
        ));

        self::assertSame([
            'messages' => [
                ['role' => 'user', 'content' => 'Привіт'],
                ['role' => 'assistant', 'content' => 'Вітаю!'],
            ],
        ], $body);
    }

    public function testPrependsSystemPromptAsFirstMessage(): void
    {
        $adapter = new GroqPromptAdapter($this->emptyRegistry());

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'Питання']],
            systemPrompt: 'Ти помічник.',
            tools: [],
        ));

        self::assertSame(
            ['role' => 'system', 'content' => 'Ти помічник.'],
            $body['messages'][0],
        );
        self::assertCount(2, $body['messages']);
    }

    public function testEmptySystemPromptIsOmitted(): void
    {
        $adapter = new GroqPromptAdapter($this->emptyRegistry());

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'Питання']],
            systemPrompt: '',
            tools: [],
        ));

        self::assertCount(1, $body['messages']);
        self::assertSame('user', $body['messages'][0]['role']);
    }

    public function testNoToolsKeyWhenToolListIsEmpty(): void
    {
        $adapter = new GroqPromptAdapter($this->emptyRegistry());

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'x']],
            tools: [],
        ));

        self::assertArrayNotHasKey('tools', $body);
        self::assertArrayNotHasKey('tool_choice', $body);
    }

    public function testAddsToolDefinitionsAndAutoToolChoice(): void
    {
        $definition = [
            'type' => 'function',
            'function' => ['name' => 'get_weather', 'description' => 'Погода'],
        ];
        $registry = new ToolRegistry(
            [new FakeTool(ToolName::GET_WEATHER, $definition)],
            new NullLogger(),
        );
        $adapter = new GroqPromptAdapter($registry);

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'Яка погода?']],
            tools: [ToolName::GET_WEATHER],
        ));

        self::assertSame([$definition], $body['tools']);
        self::assertSame('auto', $body['tool_choice']);
    }

    private function emptyRegistry(): ToolRegistry
    {
        return new ToolRegistry([], new NullLogger());
    }
}
