<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class CampusWeatherService
{
    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        if (! config('services.campus_weather.enabled') || config('services.campus_weather.provider') !== 'open_meteo') {
            return null;
        }

        $key = 'campus-weather:'.sha1(json_encode([
            config('services.campus_weather.provider'),
            config('services.campus_weather.latitude'),
            config('services.campus_weather.longitude'),
        ]));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $forecast = $this->fetchOpenMeteo();
            Cache::put($key, $forecast, now()->addMinutes((int) config('services.campus_weather.cache_minutes', 45)));

            return $forecast;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function fetchOpenMeteo(): array
    {
        $response = Http::acceptJson()
            ->timeout((int) config('services.campus_weather.timeout', 3))
            ->get((string) config('services.campus_weather.base_url'), [
                'latitude' => (float) config('services.campus_weather.latitude'),
                'longitude' => (float) config('services.campus_weather.longitude'),
                'current' => 'temperature_2m,weather_code',
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,weather_code',
                'timezone' => 'Asia/Manila',
                'forecast_days' => 1,
            ])->throw()->json();

        $temperature = data_get($response, 'current.temperature_2m');
        $rain = data_get($response, 'daily.precipitation_probability_max.0');
        $code = data_get($response, 'current.weather_code', data_get($response, 'daily.weather_code.0'));
        if (! is_numeric($temperature) || ! is_numeric($rain) || ! is_numeric($code)) {
            throw new \UnexpectedValueException('Weather provider returned an incomplete forecast.');
        }

        $rain = (int) $rain;
        $condition = $this->condition((int) $code);

        return [
            'temperature_c' => round((float) $temperature, 1),
            'rain_probability' => $rain,
            'condition' => $condition,
            'message' => sprintf(
                '%s in %s: %.1f°C with %d%% rain probability.%s',
                $condition,
                config('services.campus_weather.location_name'),
                (float) $temperature,
                $rain,
                $rain >= 50 ? ' Consider bringing an umbrella.' : '',
            ),
            'source' => 'Open-Meteo',
            'attribution_url' => 'https://open-meteo.com/',
            'forecast_at' => (string) data_get($response, 'current.time', now('Asia/Manila')->toIso8601String()),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    private function condition(int $code): string
    {
        return match (true) {
            $code === 0 => 'Clear weather forecast',
            in_array($code, [1, 2, 3], true) => 'Partly cloudy forecast',
            in_array($code, [45, 48], true) => 'Foggy forecast',
            in_array($code, [51, 53, 55, 56, 57], true) => 'Drizzle forecast',
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => 'Rain forecast',
            in_array($code, [95, 96, 99], true) => 'Thunderstorm forecast',
            default => 'Campus weather forecast',
        };
    }
}
