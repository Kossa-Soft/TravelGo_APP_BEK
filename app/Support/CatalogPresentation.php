<?php

namespace App\Support;

use App\Models\TravelGoContent;
use App\Services\TravelGoMedia;

class CatalogPresentation
{
    public function __construct(private readonly TravelGoMedia $media) {}

    public function text(?array $translations, string $language, string $default = ''): string
    {
        return $translations[$language] ?? $translations['en'] ?? $translations['uk'] ?? $default;
    }

    public function city(TravelGoContent $city, string $language): array
    {
        return ['id' => $city->mobileKey(), 'name' => $this->text($city->name, $language)];
    }

    public function country(TravelGoContent $country, string $language): array
    {
        return ['code' => $country->country_code, 'name' => $this->text($country->name, $language)];
    }

    public function region(?TravelGoContent $region, string $language): ?array
    {
        return $region === null ? null : ['id' => $region->mobileKey(), 'name' => $this->text($region->name, $language)];
    }

    public function placeMarker(TravelGoContent $place, string $language): array
    {
        return [
            'id' => $place->mobileKey(), 'name' => $this->text($place->name, $language),
            'category' => $place->data['category'], 'latitude' => $place->latitude, 'longitude' => $place->longitude,
            'priority' => (int) ($place->data['priority'] ?? 3),
        ];
    }

    public function placeCard(TravelGoContent $place, string $language, int $routesCount): array
    {
        $data = $place->data;

        return [
            'id' => $place->mobileKey(), 'name' => $this->text($place->name, $language), 'category' => $data['category'],
            'shortDescription' => $this->text($data['shortDescription'] ?? null, $language),
            'address' => $data['address'] ?? '', 'latitude' => $place->latitude, 'longitude' => $place->longitude,
            'distanceKm' => $place->distance_m === null ? null : self::kilometres((float) $place->distance_m),
            'image' => $this->media->mobileUrl($place, $data['images'][0]['url'] ?? null, 400),
            'hours' => $this->hours($data['hours'] ?? null), 'timeZone' => $place->parent->data['timeZone'],
            'city' => $this->city($place->parent, $language), 'routesCount' => $routesCount,
        ];
    }

    public function placeDetails(TravelGoContent $place, string $language, array $routes): array
    {
        $data = $place->data;

        return $this->placeCard($place, $language, count($routes)) + [
            'description' => $this->paragraphs($data['description'] ?? [], $language),
            'history' => $this->paragraphs($data['history'] ?? [], $language), 'facts' => $this->paragraphs($data['facts'] ?? [], $language),
            'images' => array_map(fn (array $image): array => array_filter([
                'url' => $this->media->mobileUrl($place, $image['url'], 1200), 'credit' => $image['credit'] ?? null,
            ], fn (mixed $field): bool => $field !== null), $data['images'] ?? []),
            'website' => $data['website'] ?? null, 'visitMinutes' => (int) ($data['visitMinutes'] ?? 20),
            'wiki' => $data['wiki'] ?? null, 'routes' => $routes,
        ];
    }

    public function routeCard(TravelGoContent $route, string $language, bool $saved, int $coverSize = 400): array
    {
        $data = $route->data;
        $city = $route->parent;

        return [
            'id' => $route->mobileKey(), 'title' => $this->text($route->name, $language),
            'description' => $this->text($data['description'] ?? null, $language),
            'coverImage' => $this->media->mobileUrl($route, $data['coverImage'] ?? null, $coverSize),
            'coverCredit' => $data['coverCredit'] ?? null,
            'city' => $this->city($city, $language), 'country' => $this->country($city->parent, $language),
            'region' => $this->region($city->region, $language),
            'durationMinutes' => (int) ($data['durationMinutes'] ?? 0), 'distanceKm' => (float) ($data['distanceKm'] ?? 0),
            'placesCount' => (int) ($data['placesCount'] ?? $route->stops->count()), 'badge' => $data['badge'] ?? null,
            'rating' => $data['rating'] ?? null, 'reviewsCount' => (int) ($data['reviewsCount'] ?? 0),
            'usedCount' => (int) ($data['usedCount'] ?? 0), 'travelersCount' => (int) ($data['travelersCount'] ?? 0),
            'isOfficial' => (bool) ($data['isOfficial'] ?? false), 'transport' => $data['transport'] ?? 'walk',
            'source' => $data['source'] ?? ['kind' => 'travelgo'], 'isSaved' => $saved,
            'start' => $this->waypoint($data['start'] ?? null, $route->startPlace ?? $route->stops->first(), $language),
        ];
    }

    public function routeDetails(TravelGoContent $route, string $language, bool $saved): array
    {
        $data = $route->data;
        $card = $this->routeCard($route, $language, $saved, 1200);

        return $card + [
            'about' => $this->paragraphs($data['about'] ?? [], $language), 'timeZone' => $route->parent->data['timeZone'],
            'end' => ($data['isLoop'] ?? false) ? $card['start']
                : $this->waypoint($data['end'] ?? null, $route->endPlace ?? $route->stops->last(), $language),
            'isLoop' => (bool) ($data['isLoop'] ?? false), 'path' => $data['path'] ?? null,
            'stops' => $route->stops->map(fn (TravelGoContent $place): array => [
                'position' => (int) $place->pivot->position, 'visitMinutes' => (int) $place->pivot->visit_minutes,
                'note' => $this->text(json_decode($place->pivot->note ?? 'null', true), $language) ?: null,
                'place' => [
                    'id' => $place->mobileKey(), 'name' => $this->text($place->name, $language),
                    'description' => $this->text($place->data['shortDescription'] ?? null, $language),
                    'address' => $place->data['address'] ?? '', 'latitude' => $place->latitude, 'longitude' => $place->longitude,
                    'hours' => $this->hours($place->data['hours'] ?? null), 'wiki' => $place->data['wiki'] ?? null,
                    'images' => array_map(fn (array $image): ?string => $this->media->mobileUrl($place, $image['url'], 1200), $place->data['images'] ?? []),
                ],
            ])->all(),
        ];
    }

    private function waypoint(?array $explicit, ?TravelGoContent $place, string $language): array
    {
        if (isset($explicit['latitude'], $explicit['longitude'])) {
            return ['name' => $this->text($explicit['name'] ?? null, $language),
                'latitude' => (float) $explicit['latitude'], 'longitude' => (float) $explicit['longitude']];
        }

        return ['name' => $this->text($place->name, $language), 'latitude' => $place->latitude,
            'longitude' => $place->longitude, 'placeId' => $place->mobileKey()];
    }

    private function paragraphs(array $paragraphs, string $language): array
    {
        return array_values(array_filter(array_map(fn (array $t): string => $this->text($t, $language), $paragraphs), fn (string $t): bool => $t !== ''));
    }

    private function hours(mixed $hours): array|string|null
    {
        if (is_array($hours) && isset($hours['closedOn'])) {
            $hours['closedOn'] = array_map(intval(...), $hours['closedOn']);
        }

        return $hours;
    }

    public static function kilometres(float $metres): float
    {
        return round($metres / 1000, 1);
    }
}
