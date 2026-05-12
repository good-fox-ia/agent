<?php

namespace App\Service\LLM;

abstract class AbstractLLM implements LLMInterface
{
    protected function post(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('Failed to initialize cURL.');

        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;

        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => $headerLines,
            \CURLOPT_POSTFIELDS => json_encode($body, \JSON_THROW_ON_ERROR),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) throw new \RuntimeException('HTTP request failed: ' . $error, $errno);

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('Response is not valid JSON.');

        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? $decoded['message'] ?? (string) $raw;
            throw new \RuntimeException('HTTP error (' . $status . '): ' . $msg, $status);
        }

        return $decoded;
    }
}
