<?php

namespace App\Service\Http;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Client
{
    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public function post(string $url, array $body, array $headers = []): array
    {
        if (!isset($headers['Content-Type'])) $headers['Content-Type'] = 'application/json';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $body,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('Response is not valid JSON.');

        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? $decoded['description'] ?? $decoded['message'] ?? $raw;

            throw new \RuntimeException('HTTP error (' . $status . '): ' . $msg, $status);
        }

        return $decoded;
    }
}
