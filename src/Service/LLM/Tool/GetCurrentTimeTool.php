<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;

final class GetCurrentTimeTool implements ToolInterface
{
    public function getName(): ToolName
    {
        return ToolName::GET_CURRENT_TIME;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Get the current date and time.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'timezone' => [
                            'type' => 'string',
                            'description' => 'IANA timezone, e.g. Europe/Kyiv. Defaults to UTC.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $timezone = isset($arguments['timezone']) && is_string($arguments['timezone']) && $arguments['timezone'] !== ''
            ? $arguments['timezone']
            : 'UTC';

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Invalid timezone: %s', $timezone));
        }

        return json_encode([
            'timezone' => $timezone,
            'datetime' => $now->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);
    }
}
