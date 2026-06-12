<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\ToolName;
use App\Service\LLM\Tool\ToolInterface;

final class FakeTool implements ToolInterface
{
    /** @var list<array<string, mixed>> */
    public array $executedWith = [];

    public function __construct(
        private readonly ToolName $name,
        private readonly array $description = [],
        private readonly ?\Closure $execute = null,
    ) {}

    public function getName(): ToolName
    {
        return $this->name;
    }

    public function getDescription(): array
    {
        return $this->description;
    }

    public function execute(array $arguments): string
    {
        $this->executedWith[] = $arguments;

        return $this->execute !== null ? ($this->execute)($arguments) : 'ok';
    }
}
