<?php

declare(strict_types=1);

namespace App\Service\LLM\Adapter;

use App\Service\LLM\DTO\PromptDTO;

interface PromptAdapterInterface
{
    public function adapt(PromptDTO $prompt): array;
}
