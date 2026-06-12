<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\LLM\Tool\ToolRegistry;
use App\Tests\Support\FakeTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ToolRegistryTest extends TestCase
{
    public function testGetAllNamesReturnsRegisteredTools(): void
    {
        $registry = new ToolRegistry(
            [
                new FakeTool(ToolName::GET_WEATHER),
                new FakeTool(ToolName::DO_NOTHING),
            ],
            new NullLogger(),
        );

        self::assertSame([ToolName::GET_WEATHER, ToolName::DO_NOTHING], $registry->getAllNames());
    }

    public function testGetDefinitionsForReturnsDescriptionsInRequestedOrder(): void
    {
        $weatherDef = ['function' => ['name' => 'get_weather']];
        $nothingDef = ['function' => ['name' => 'do_nothing']];
        $registry = new ToolRegistry(
            [
                new FakeTool(ToolName::GET_WEATHER, $weatherDef),
                new FakeTool(ToolName::DO_NOTHING, $nothingDef),
            ],
            new NullLogger(),
        );

        self::assertSame(
            [$nothingDef, $weatherDef],
            $registry->getDefinitionsFor([ToolName::DO_NOTHING, ToolName::GET_WEATHER]),
        );
    }

    public function testGetDefinitionsForUnknownToolThrows(): void
    {
        $registry = new ToolRegistry([], new NullLogger());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: get_weather');

        $registry->getDefinitionsFor([ToolName::GET_WEATHER]);
    }

    public function testExecuteToolDelegatesToTool(): void
    {
        $tool = new FakeTool(ToolName::GET_WEATHER, [], static fn (array $args): string => 'сонячно у ' . $args['city']);
        $registry = new ToolRegistry([$tool], new NullLogger());

        $result = $registry->executeTool('get_weather', ['city' => 'Києві']);

        self::assertSame('сонячно у Києві', $result);
        self::assertSame([['city' => 'Києві']], $tool->executedWith);
    }

    public function testExecuteUnknownToolThrows(): void
    {
        $registry = new ToolRegistry([], new NullLogger());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: missing_tool');

        $registry->executeTool('missing_tool', []);
    }

    public function testToolFailureIsReturnedAsErrorPayloadInsteadOfThrowing(): void
    {
        $tool = new FakeTool(ToolName::GET_WEATHER, [], static function (): string {
            throw new \RuntimeException('Сервіс погоди недоступний');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $registry = new ToolRegistry([$tool], $logger);

        $result = $registry->executeTool('get_weather', []);

        self::assertSame(['error' => 'Сервіс погоди недоступний'], json_decode($result, true));
    }
}
