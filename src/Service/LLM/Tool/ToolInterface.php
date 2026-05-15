<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;


interface ToolInterface
{
    public function getName(): ToolName;
    public function getDescription(): array;
    public function execute(array $arguments): string;
}
