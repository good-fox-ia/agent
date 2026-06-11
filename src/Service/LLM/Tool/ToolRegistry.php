<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;

final class ToolRegistry
{
    /** @var array<string, ToolInterface>|null */
    private ?array $toolsByName = null;

    /**
     * Тулзи ітеруються ліниво (не в конструкторі): деякі тулзи залежать від LLM-клієнтів,
     * чиї prompt-адаптери своєю чергою залежать від ToolRegistry. Жадібна ітерація
     * створює циклічну рекурсію інстанціювання сервісів і OOM.
     *
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(private readonly iterable $tools) {}

    /**
     * @return array<string, ToolInterface>
     */
    private function toolsByName(): array
    {
        if ($this->toolsByName === null) {
            $this->toolsByName = [];
            foreach ($this->tools as $tool) {
                $this->toolsByName[$tool->getName()->value] = $tool;
            }
        }

        return $this->toolsByName;
    }

    /**
     * @return list<ToolName>
     */
    public function getAllNames(): array
    {
        $names = [];
        foreach ($this->toolsByName() as $tool) {
            $names[] = $tool->getName();
        }

        return $names;
    }

    /**
     * @param list<ToolName> $names
     *
     * @return list<array<string, mixed>>
     */
    public function getDefinitionsFor(array $names): array
    {
        $definitions = [];
        $toolsByName = $this->toolsByName();
        foreach ($names as $name) {
            $tool = $toolsByName[$name->value] ?? null;
            if ($tool === null) {
                throw new \InvalidArgumentException(sprintf('Unknown tool: %s', $name->value));
            }
            $definitions[] = $tool->getDescription();
        }

        return $definitions;
    }

    public function executeTool(string $name, array $arguments): string
    {
        $tool = $this->toolsByName()[$name] ?? null;
        if ($tool === null) throw new \InvalidArgumentException(sprintf('Unknown tool: %s', $name));

        return $tool->execute($arguments);
    }
}
