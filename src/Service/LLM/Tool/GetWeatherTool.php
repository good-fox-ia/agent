<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\Http\Client;

final class GetWeatherTool implements ToolInterface
{
    private const GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(private readonly Client $httpClient) {}

    public function getName(): ToolName
    {
        return ToolName::GET_WEATHER;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Get current weather for a location.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'location' => [
                            'type' => 'string',
                            'description' => 'City or place name.',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $location = isset($arguments['location']) && is_string($arguments['location'])
            ? trim($arguments['location'])
            : '';

        if ($location === '') {
            throw new \InvalidArgumentException('Location is required.');
        }

        $place = $this->geocode($location);
        $weather = $this->fetchCurrentWeather($place['latitude'], $place['longitude']);

        return json_encode([
            'location' => $place['name'],
            'country' => $place['country'] ?? null,
            'latitude' => $place['latitude'],
            'longitude' => $place['longitude'],
            'temperature_c' => $weather['temperature_2m'],
            'apparent_temperature_c' => $weather['apparent_temperature'] ?? null,
            'humidity_percent' => $weather['relative_humidity_2m'] ?? null,
            'wind_speed_kmh' => $weather['wind_speed_10m'] ?? null,
            'precipitation_mm' => $weather['precipitation'] ?? null,
            'condition' => $this->weatherCodeToCondition($weather['weather_code'] ?? 0),
            'observed_at' => $weather['time'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{name: string, latitude: float, longitude: float, country?: string}
     */
    private function geocode(string $location): array
    {
        $url = self::GEOCODING_URL . '?' . http_build_query([
            'name' => $location,
            'count' => 1,
            'language' => 'en',
            'format' => 'json',
        ]);

        $raw = $this->httpClient->get($url);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || empty($data['results'][0])) {
            throw new \InvalidArgumentException(sprintf('Location not found: %s', $location));
        }

        $result = $data['results'][0];

        return [
            'name' => (string) ($result['name'] ?? $location),
            'country' => isset($result['country']) ? (string) $result['country'] : null,
            'latitude' => (float) $result['latitude'],
            'longitude' => (float) $result['longitude'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCurrentWeather(float $latitude, float $longitude): array
    {
        $url = self::FORECAST_URL . '?' . http_build_query([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m',
            'timezone' => 'auto',
        ]);

        $raw = $this->httpClient->get($url);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['current']) || !is_array($data['current'])) {
            throw new \RuntimeException('Weather data is unavailable.');
        }

        return $data['current'];
    }

    private function weatherCodeToCondition(int $code): string
    {
        return match (true) {
            $code === 0 => 'clear sky',
            $code === 1 => 'mainly clear',
            $code === 2 => 'partly cloudy',
            $code === 3 => 'overcast',
            $code >= 45 && $code <= 48 => 'fog',
            $code >= 51 && $code <= 57 => 'drizzle',
            $code >= 61 && $code <= 67 => 'rain',
            $code >= 71 && $code <= 77 => 'snow',
            $code >= 80 && $code <= 82 => 'rain showers',
            $code >= 85 && $code <= 86 => 'snow showers',
            $code >= 95 && $code <= 99 => 'thunderstorm',
            default => 'unknown',
        };
    }
}
