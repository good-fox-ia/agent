<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $toolsByName = [];

    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->toolsByName[$tool->getName()->value] = $tool;
        }
    }

    /**
     * @return list<ToolName>
     */
    public function getAllNames(): array
    {
        $names = [];
        foreach ($this->toolsByName as $tool) {
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
        foreach ($names as $name) {
            $tool = $this->toolsByName[$name->value] ?? null;
            if ($tool === null) {
                throw new \InvalidArgumentException(sprintf('Unknown tool: %s', $name->value));
            }
            $definitions[] = $tool->getDescription();
        }

        return $definitions;
    }

    public function executeTool(string $name, array $arguments): string
    {
        $tool = $this->toolsByName[$name] ?? null;
        if ($tool === null) throw new \InvalidArgumentException(sprintf('Unknown tool: %s', $name));

        return $tool->execute($arguments);
    }
}
