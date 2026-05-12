<?php

namespace App\Service\Http;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Client
{
    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public function get(string $url, array $headers = []): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);

        if ($status >= 400) throw new \RuntimeException('HTTP error (' . $status . '): ' . $raw, $status);

        return $raw ?? '';
    }

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
        if ($status >= 400) throw new \RuntimeException('HTTP error (' . $status . '): ' . $raw, $status);

        return $decoded;
    }

    public function postMultipart(string $url, array $body, array $headers = []): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('Response is not valid JSON.');
        if ($status >= 400) throw new \RuntimeException('HTTP error (' . $status . '): ' . $raw, $status);

        return $decoded;
    }
}
