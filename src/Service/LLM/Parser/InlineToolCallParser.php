<?php

declare(strict_types=1);

namespace App\Service\LLM\Parser;

/**
 * Деякі моделі (напр. gpt-oss на Groq) повертають виклик тулза JSON у content замість tool_calls.
 */
final class InlineToolCallParser
{
    /**
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    public function parse(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^```(?:json)?\s*\n?(.*)\n?```$#si', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        if (!str_starts_with($trimmed, '{')) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return $this->extractFromDecoded($decoded);
    }

    public function looksLikeToolCall(string $content): bool
    {
        return $this->parse($content) !== null;
    }

    /**
     * @return array{id: string, type: string, function: array{name: string, arguments: string}}
     */
    public function toApiToolCall(array $parsed): array
    {
        return [
            'id' => 'call_'.bin2hex(random_bytes(8)),
            'type' => 'function',
            'function' => [
                'name' => $parsed['name'],
                'arguments' => json_encode($parsed['arguments'], JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    private function extractFromDecoded(array $decoded): ?array
    {
        if (isset($decoded['name']) && is_string($decoded['name']) && $decoded['name'] !== '') {
            $args = $decoded['parameters'] ?? $decoded['arguments'] ?? [];
            if (is_string($args)) {
                $args = json_decode($args, true) ?? [];
            }

            return [
                'name' => $decoded['name'],
                'arguments' => is_array($args) ? $args : [],
            ];
        }

        $function = $decoded['function'] ?? null;
        if (!is_array($function)) {
            return null;
        }

        $name = $function['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $args = $function['arguments'] ?? [];
        if (is_string($args)) {
            $args = json_decode($args, true) ?? [];
        }

        return [
            'name' => $name,
            'arguments' => is_array($args) ? $args : [],
        ];
    }
}
