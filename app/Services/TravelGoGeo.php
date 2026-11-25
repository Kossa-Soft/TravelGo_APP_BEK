<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TravelGoGeo
{
    public function search(string $query): ?array
    {
        $url = config('services.travelgo.geocoder_url');
        if (blank($url)) {
            return null;
        }
        $results = $this->get($url, ['q' => $query, 'format' => 'jsonv2', 'limit' => 6]);
        if (! is_array($results) || ! array_is_list($results)) {
            return null;
        }

        $matches = [];
        foreach (array_slice($results, 0, 6) as $match) {
            if (is_string($match['display_name'] ?? null) && $this->isPoint($match['lon'] ?? null, $match['lat'] ?? null)) {
                $matches[] = ['label' => Str::limit($match['display_name'], 500, ''),
                    'latitude' => (float) $match['lat'], 'longitude' => (float) $match['lon']];
            }
        }

        return $matches;
    }

    public function timeZone(float $lat, float $lng): ?string
    {
        $username = config('services.travelgo.geonames_username');
        if (blank($username)) {
            return null;
        }
        $data = $this->get('https://secure.geonames.org/timezoneJSON', ['lat' => $lat, 'lng' => $lng, 'username' => $username]);
        $timezone = $data['timezoneId'] ?? null;

        return is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : null;
    }

    /** @return array{coordinates: array, distanceKm: float, minutes: int}|null */
    public function walkingRoute(array $points): ?array
    {
        $base = config('services.travelgo.osrm_url');
        if (blank($base)) {
            return null;
        }
        $coordinates = implode(';', array_map(fn (array $point): string => sprintf('%.6F,%.6F', $point[0], $point[1]), $points));
        $data = $this->get(rtrim($base, '/').'/route/v1/foot/'.$coordinates, ['geometries' => 'geojson', 'overview' => 'simplified', 'steps' => 'false']);
        $route = $data['routes'][0] ?? null;
        $line = $route['geometry']['coordinates'] ?? null;
        if (! is_array($line) || ! array_is_list($line) || count($line) < 2 || count($line) > 10000
            || ($route['geometry']['type'] ?? null) !== 'LineString'
            || ! is_numeric($route['distance'] ?? null) || $route['distance'] <= 0
            || ! is_numeric($route['duration'] ?? null) || $route['duration'] <= 0
        ) {
            return null;
        }
        foreach ($line as $point) {
            if (! is_array($point) || ! $this->isPoint($point[0] ?? null, $point[1] ?? null)) {
                return null;
            }
        }

        $step = (int) ceil((count($line) - 1) / 499);
        $coordinates = [];
        for ($index = 0; $index < count($line) - 1; $index += $step) {
            $coordinates[] = array_map(floatval(...), array_slice($line[$index], 0, 2));
        }
        $coordinates[] = array_map(floatval(...), array_slice($line[array_key_last($line)], 0, 2));

        return ['coordinates' => $coordinates, 'distanceKm' => round($route['distance'] / 1000, 2), 'minutes' => (int) round($route['duration'] / 60)];
    }

    private function isPoint(mixed $lng, mixed $lat): bool
    {
        return is_numeric($lng) && is_numeric($lat) && $lng >= -180 && $lng <= 180 && $lat >= -90 && $lat <= 90;
    }

    private function get(string $url, array $query): ?array
    {
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(4)->get($url, $query);
        } catch (ConnectionException) {
            return null;
        }

        $data = $response->successful() ? $response->json() : null;

        return is_array($data) ? $data : null;
    }
}
