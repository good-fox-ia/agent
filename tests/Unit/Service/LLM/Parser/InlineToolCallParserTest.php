<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LLM\Parser;

use App\Service\LLM\Parser\InlineToolCallParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineToolCallParserTest extends TestCase
{
    private InlineToolCallParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InlineToolCallParser();
    }

    public function testParsesNameWithParameters(): void
    {
        $result = $this->parser->parse('{"name": "get_weather", "parameters": {"city": "Kyiv"}}');

        self::assertSame(
            ['name' => 'get_weather', 'arguments' => ['city' => 'Kyiv']],
            $result,
        );
    }

    public function testParsesNameWithArgumentsKey(): void
    {
        $result = $this->parser->parse('{"name": "get_weather", "arguments": {"city": "Lviv"}}');

        self::assertSame(
            ['name' => 'get_weather', 'arguments' => ['city' => 'Lviv']],
            $result,
        );
    }

    public function testParsesArgumentsEncodedAsJsonString(): void
    {
        $result = $this->parser->parse('{"name": "get_weather", "arguments": "{\"city\": \"Odesa\"}"}');

        self::assertSame(
            ['name' => 'get_weather', 'arguments' => ['city' => 'Odesa']],
            $result,
        );
    }

    public function testInvalidArgumentsStringFallsBackToEmptyArray(): void
    {
        $result = $this->parser->parse('{"name": "get_weather", "arguments": "not-json"}');

        self::assertSame(['name' => 'get_weather', 'arguments' => []], $result);
    }

    public function testMissingArgumentsDefaultsToEmptyArray(): void
    {
        $result = $this->parser->parse('{"name": "do_nothing"}');

        self::assertSame(['name' => 'do_nothing', 'arguments' => []], $result);
    }

    public function testParsesOpenAiStyleFunctionWrapper(): void
    {
        $result = $this->parser->parse(
            '{"function": {"name": "web_search", "arguments": "{\"query\": \"php\"}"}}',
        );

        self::assertSame(
            ['name' => 'web_search', 'arguments' => ['query' => 'php']],
            $result,
        );
    }

    public function testParsesFunctionWrapperWithArrayArguments(): void
    {
        $result = $this->parser->parse(
            '{"function": {"name": "web_search", "arguments": {"query": "symfony"}}}',
        );

        self::assertSame(
            ['name' => 'web_search', 'arguments' => ['query' => 'symfony']],
            $result,
        );
    }

    public function testStripsJsonCodeFence(): void
    {
        $content = "```json\n{\"name\": \"get_weather\", \"parameters\": {\"city\": \"Kyiv\"}}\n```";

        $result = $this->parser->parse($content);

        self::assertNotNull($result);
        self::assertSame('get_weather', $result['name']);
    }

    public function testStripsPlainCodeFence(): void
    {
        $content = "```\n{\"name\": \"get_weather\", \"parameters\": {}}\n```";

        $result = $this->parser->parse($content);

        self::assertNotNull($result);
        self::assertSame('get_weather', $result['name']);
    }

    #[DataProvider('provideNonToolCallContent')]
    public function testReturnsNullForNonToolCallContent(string $content): void
    {
        self::assertNull($this->parser->parse($content));
        self::assertFalse($this->parser->looksLikeToolCall($content));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonToolCallContent(): iterable
    {
        yield 'порожній рядок' => [''];
        yield 'лише пробіли' => ['   '];
        yield 'звичайний текст' => ['Привіт! Чим можу допомогти?'];
        yield 'текст перед json' => ['Ось результат: {"name": "x"}'];
        yield 'битий json' => ['{"name": "get_weather", '];
        yield 'json без name і function' => ['{"foo": "bar"}'];
        yield 'порожнє ім\'я' => ['{"name": ""}'];
        yield 'name не рядок' => ['{"name": 123}'];
        yield 'function не масив' => ['{"function": "get_weather"}'];
        yield 'function без name' => ['{"function": {"arguments": {}}}'];
        yield 'json-масив' => ['[1, 2, 3]'];
    }

    public function testLooksLikeToolCallForValidPayload(): void
    {
        self::assertTrue($this->parser->looksLikeToolCall('{"name": "do_nothing"}'));
    }

    public function testToApiToolCallBuildsOpenAiStructure(): void
    {
        $apiCall = $this->parser->toApiToolCall([
            'name' => 'get_weather',
            'arguments' => ['city' => 'Kyiv'],
        ]);

        self::assertStringStartsWith('call_', $apiCall['id']);
        self::assertSame('function', $apiCall['type']);
        self::assertSame('get_weather', $apiCall['function']['name']);
        self::assertSame(['city' => 'Kyiv'], json_decode($apiCall['function']['arguments'], true));
    }
}
