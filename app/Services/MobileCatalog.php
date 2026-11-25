<?php

namespace App\Services;

use App\Models\TravelGoContent;
use App\Support\CatalogPresentation;
use App\Support\CatalogQuery;
use App\Support\TravelGoType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MobileCatalog
{
    public function __construct(private readonly CatalogPresentation $response) {}

    public function locate(array $point, string $language): array
    {
        $city = $this->cityAt($point);
        if ($city !== null) {
            return [
                'city' => $this->response->city($city, $language) + [
                    'distanceKm' => CatalogPresentation::kilometres((float) $city->distance_m),
                    'timeZone' => $city->data['timeZone'],
                ],
                'region' => $this->response->region($city->region, $language),
                'country' => $this->response->country($city->parent, $language),
            ];
        }

        $country = DB::getDriverName() === 'pgsql'
            ? TravelGoContent::visible(TravelGoType::Country)->whereNotNull('country_boundary')
                ->whereRaw('ST_Covers(country_boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$point[1], $point[0]])
                ->orderBy('country_code')->first() : null;

        return ['city' => null, 'region' => null, 'country' => $country ? $this->response->country($country, $language) : null];
    }

    private function cityAt(array $point): ?TravelGoContent
    {
        $query = CatalogQuery::withDistance(CatalogQuery::located(TravelGoContent::visible(TravelGoType::City)), $point)
            ->with(['parent', 'region']);
        CatalogQuery::within($query, $point, 200000);
        $radius = "COALESCE(CAST(data ->> 'radiusKm' AS real), 15)";

        return TravelGoContent::query()->fromSub($query->toBase(), 'travelgo_contents')
            ->whereRaw('distance_m <= '.$radius.' * 1000')->orderBy('distance_m')->orderBy('id')
            ->with(['parent', 'region'])->first();
    }

    public function nearbyPlaces(array $point, int $maxKm, array $categories, ?string $cursor, int $limit, string $language): array
    {
        $query = CatalogQuery::withDistance($this->places($categories), $point);
        CatalogQuery::within($query, $point, $maxKm * 1000);
        $ranked = TravelGoContent::query()->fromSub($query->toBase(), 'travelgo_contents')
            ->select('travelgo_contents.*')
            ->selectRaw("CAST(FLOOR(distance_m / 100) AS integer) AS band, -COALESCE(CAST(data ->> 'priority' AS integer), 3) AS priority_rank")
            ->selectRaw('CAST(FLOOR(distance_m * 1000) AS bigint) AS distance_rank');
        $feed = TravelGoContent::query()->fromSub($ranked->toBase(), 'travelgo_contents')->with('parent');

        [$places, $nextCursor] = CatalogQuery::orderedPage($feed, ['band', 'priority_rank', 'distance_rank'],
            CatalogQuery::scopedHash('places', $point, [$maxKm, $categories]), $cursor, $limit);
        $routes = $this->routeCountsForPlaces($places->modelKeys());

        return [
            'items' => $places->map(fn (TravelGoContent $place): array => $this->response->placeCard($place, $language, $routes[$place->id] ?? 0))->all(),
            'nextCursor' => $nextCursor,
        ];
    }

    public function mapPlaces(array $box, array $categories, string $language): array
    {
        [$minLat, $minLng, $maxLat, $maxLng] = $box;
        $query = $this->places($categories)->whereBetween('latitude', [$minLat, $maxLat]);
        if ($minLng <= $maxLng) {
            $query->whereBetween('longitude', [$minLng, $maxLng]);
        } else {
            $query->where(fn (Builder $query) => $query->where('longitude', '>=', $minLng)->orWhere('longitude', '<=', $maxLng));
        }
        $widthDegrees = $minLng <= $maxLng ? $maxLng - $minLng : 360 - $minLng + $maxLng;
        $wide = ($maxLat - $minLat) * 111.2 > 50 || $widthDegrees * 111.2 * cos(deg2rad(($minLat + $maxLat) / 2)) > 50;
        $priority = "COALESCE(CAST(data ->> 'priority' AS integer), 3)";
        if ($wide) {
            $query->whereRaw($priority.' >= 4');
        }

        return $query->orderByRaw($priority.' DESC')->orderBy('id')->limit(200)->get()
            ->map(fn (TravelGoContent $place): array => $this->response->placeMarker($place, $language))->all();
    }

    public function place(string $id, ?array $point, string $language, ?int $userId): array
    {
        $query = $this->places([])->with('parent');
        if ($point !== null) {
            CatalogQuery::withDistance($query, $point);
        }
        $place = CatalogQuery::byMobileId($query, $id)->firstOrFail();
        $routes = $this->usingPlaces([$place->id])->with(CatalogQuery::routeRelations())->orderBy('id')->get();
        $saved = CatalogQuery::saved($routes, $userId);

        return $this->response->placeDetails($place, $language, $routes->map(
            fn (TravelGoContent $route): array => $this->response->routeCard($route, $language, isset($saved[$route->id])),
        )->all());
    }

    public function nearbyRoutes(array $point, int $maxKm, ?string $transport, ?string $cursor, int $limit, string $language, ?int $userId): array
    {
        $city = $this->cityAt($point);
        $scopeId = $city?->id;
        $query = CatalogQuery::withDistance(CatalogQuery::located($this->routes($transport)), $point);
        $query->where(function (Builder $query) use ($scopeId, $point, $maxKm): void {
            if ($scopeId !== null) {
                $query->where('parent_id', $scopeId)->orWhere(fn (Builder $q) => CatalogQuery::within($q, $point, $maxKm * 1000));
            } else {
                CatalogQuery::within($query, $point, $maxKm * 1000);
            }
        });
        $query->selectRaw('CASE WHEN parent_id = ? THEN 0 ELSE 1 END AS zone', [$scopeId]);
        $ranked = TravelGoContent::query()->fromSub($query->toBase(), 'travelgo_contents')->select('travelgo_contents.*')
            ->selectRaw("CASE WHEN zone = 0 AND data ->> 'isOfficial' IN ('true', '1') THEN -1 ELSE 0 END AS official_rank")
            ->selectRaw("CASE WHEN zone = 0 THEN -COALESCE(CAST(data ->> 'sortPriority' AS integer), 0) ELSE 0 END AS editorial_rank")
            ->selectRaw("CASE WHEN zone = 0 THEN -COALESCE(CAST(data ->> 'usedCount' AS integer), 0) ELSE 0 END AS popularity_rank")
            ->selectRaw('CASE WHEN zone = 1 THEN CAST(FLOOR(distance_m * 1000) AS bigint) ELSE 0 END AS distance_rank');
        $feed = TravelGoContent::query()->fromSub($ranked->toBase(), 'travelgo_contents')->with(CatalogQuery::routeRelations());
        [$routes, $nextCursor] = CatalogQuery::orderedPage($feed, ['zone', 'official_rank', 'editorial_rank', 'popularity_rank', 'distance_rank'],
            CatalogQuery::scopedHash('routes', $point, [$scopeId, $maxKm, $transport]), $cursor, $limit);
        $saved = CatalogQuery::saved($routes, $userId);

        return [
            'city' => $city === null ? null : $this->response->city($city, $language),
            'items' => $routes->map(fn (TravelGoContent $route) => [
                'scope' => (int) $route->zone === 0 ? 'city' : 'nearby',
                'distanceKm' => CatalogPresentation::kilometres((float) $route->distance_m),
                'route' => $this->response->routeCard($route, $language, isset($saved[$route->id])),
            ])->all(),
            'nextCursor' => $nextCursor,
            'suggestion' => $routes->isEmpty() && $cursor === null ? $this->suggest($point, $language, $transport) : null,
        ];
    }

    private function suggest(array $point, string $language, ?string $transport): ?array
    {
        $query = $this->citiesWithRoutes($transport);
        [$distance, $parameters] = CatalogQuery::distance($point);
        $cities = $query->selectRaw($distance.' AS distance_m', $parameters)->orderBy('distance_m')->orderBy('id')->limit(3)->get();
        if ($cities->isEmpty()) {
            return null;
        }

        return ['cities' => $cities->map(fn (TravelGoContent $city): array => $this->response->city($city, $language) + [
            'distanceKm' => CatalogPresentation::kilometres((float) $city->distance_m), 'routesCount' => (int) $city->routes_count,
            'latitude' => $city->latitude, 'longitude' => $city->longitude,
        ])->all()];
    }

    public function route(string $id, string $language, ?int $userId): array
    {
        $route = CatalogQuery::byMobileId($this->routes()->with(CatalogQuery::routeRelations()), $id)->firstOrFail();
        $saved = $userId !== null && DB::table('travelgo_saved_routes')->where(['user_id' => $userId, 'route_id' => $route->id])->exists();

        return $this->response->routeDetails($route, $language, $saved);
    }

    public function cities(string $language): array
    {
        $cities = $this->citiesWithRoutes()->with('parent')->get();

        return $cities->map(fn (TravelGoContent $city): array => [
            ...$this->response->city($city, $language),
            'country' => $this->response->country($city->parent, $language), 'latitude' => $city->latitude,
            'longitude' => $city->longitude, 'routesCount' => (int) $city->routes_count,
            '_countryOrder' => (int) $city->parent->sort_order,
        ])->sort(fn (array $a, array $b): int => $a['_countryOrder'] <=> $b['_countryOrder']
            ?: strcoll($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']))
            ->values()->map(fn (array $city): array => array_diff_key($city, ['_countryOrder' => true]))->all();
    }

    private function citiesWithRoutes(?string $transport = null): Builder
    {
        $counts = $this->routes($transport)->selectRaw('parent_id AS city_id, COUNT(*) AS routes_count')->groupBy('parent_id');

        return CatalogQuery::located(TravelGoContent::visible(TravelGoType::City))
            ->select('travelgo_contents.*', 'route_totals.routes_count')->joinSub($counts->toBase(), 'route_totals', 'route_totals.city_id', '=', 'travelgo_contents.id');
    }

    private function places(array $categories): Builder
    {
        return CatalogQuery::located(TravelGoContent::visible(TravelGoType::Place))
            ->when($categories !== [], fn (Builder $query) => $query->whereIn('data->category', $categories));
    }

    private function routes(?string $transport = null): Builder
    {
        return TravelGoContent::visible(TravelGoType::Route)
            ->when($transport !== null, fn (Builder $query) => $query->where('data->transport', $transport));
    }

    private function usingPlaces(array $ids): Builder
    {
        return $this->routes()->where(fn (Builder $route) => $route->whereIn('start_place_id', $ids)
            ->orWhereIn('end_place_id', $ids)
            ->orWhereIn('id', DB::table('travelgo_route_stops')->whereIn('place_id', $ids)->select('route_id')));
    }

    private function routeCountsForPlaces(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $routes = $this->usingPlaces($ids)->select(['id', 'start_place_id', 'end_place_id'])
            ->with(['stops' => fn ($relation) => $relation->select('travelgo_contents.id')->whereIn('travelgo_contents.id', $ids)])
            ->get();
        $counts = [];
        foreach ($routes as $route) {
            foreach (array_unique(array_filter([$route->start_place_id, $route->end_place_id, ...$route->stops->modelKeys()])) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
