<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LLM\Adapter;

use App\Enum\ToolName;
use App\Service\LLM\Adapter\GeminiPromptAdapter;
use App\Service\LLM\DTO\PromptDTO;
use App\Service\LLM\Tool\ToolRegistry;
use App\Tests\Support\FakeTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GeminiPromptAdapterTest extends TestCase
{
    public function testMapsRolesToGeminiFormat(): void
    {
        $adapter = new GeminiPromptAdapter($this->emptyRegistry());

        $body = $adapter->adapt(new PromptDTO(
            messages: [
                ['role' => 'user', 'content' => 'Привіт'],
                ['role' => 'assistant', 'content' => 'Вітаю!'],
                ['role' => 'system', 'content' => 'невідома роль іде як user'],
            ],
            tools: [],
        ));

        self::assertSame([
            ['role' => 'user', 'parts' => [['text' => 'Привіт']]],
            ['role' => 'model', 'parts' => [['text' => 'Вітаю!']]],
            ['role' => 'user', 'parts' => [['text' => 'невідома роль іде як user']]],
        ], $body['contents']);
    }

    public function testAddsSystemInstruction(): void
    {
        $adapter = new GeminiPromptAdapter($this->emptyRegistry());

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'x']],
            systemPrompt: 'Ти помічник.',
            tools: [],
        ));

        self::assertSame(
            ['parts' => [['text' => 'Ти помічник.']]],
            $body['systemInstruction'],
        );
    }

    public function testOmitsSystemInstructionWhenAbsentOrEmpty(): void
    {
        $adapter = new GeminiPromptAdapter($this->emptyRegistry());

        $withoutPrompt = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'x']],
            tools: [],
        ));
        $withEmptyPrompt = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'x']],
            systemPrompt: '',
            tools: [],
        ));

        self::assertArrayNotHasKey('systemInstruction', $withoutPrompt);
        self::assertArrayNotHasKey('systemInstruction', $withEmptyPrompt);
    }

    public function testConvertsOpenAiToolDefinitionsToFunctionDeclarations(): void
    {
        $parameters = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ];
        $registry = new ToolRegistry(
            [
                new FakeTool(ToolName::GET_WEATHER, [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Погода у місті',
                        'parameters' => $parameters,
                    ],
                ]),
            ],
            new NullLogger(),
        );
        $adapter = new GeminiPromptAdapter($registry);

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'Яка погода?']],
            tools: [ToolName::GET_WEATHER],
        ));

        self::assertSame([
            [
                'functionDeclarations' => [
                    [
                        'name' => 'get_weather',
                        'description' => 'Погода у місті',
                        'parameters' => $parameters,
                    ],
                ],
            ],
        ], $body['tools']);
    }

    public function testSkipsMalformedToolDefinitionsAndAppliesDefaults(): void
    {
        $registry = new ToolRegistry(
            [
                // без ключа function — пропускається
                new FakeTool(ToolName::DO_NOTHING, ['type' => 'function']),
                // без parameters і description — підставляються дефолти
                new FakeTool(ToolName::GET_CURRENT_TIME, [
                    'type' => 'function',
                    'function' => ['name' => 'get_current_time'],
                ]),
            ],
            new NullLogger(),
        );
        $adapter = new GeminiPromptAdapter($registry);

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'x']],
            tools: [ToolName::DO_NOTHING, ToolName::GET_CURRENT_TIME],
        ));

        self::assertSame([
            [
                'name' => 'get_current_time',
                'description' => '',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ], $body['tools'][0]['functionDeclarations']);
    }

    public function testNoToolsKeyWhenToolListIsEmpty(): void
    {
        $adapter = new GeminiPromptAdapter($this->emptyRegistry());

        $body = $adapter->adapt(new PromptDTO(
            messages: [['role' => 'user', 'content' => 'x']],
            tools: [],
        ));

        self::assertArrayNotHasKey('tools', $body);
    }

    private function emptyRegistry(): ToolRegistry
    {
        return new ToolRegistry([], new NullLogger());
    }
}
