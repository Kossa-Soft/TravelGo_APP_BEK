<?php

namespace App\Support;

use App\Models\TravelGoContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogQuery
{
    public const string POINT_SQL = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';

    /** @return array{string, array<int, float>} */
    public static function distance(array $point): array
    {
        [$lat, $lng] = $point;
        if (DB::getDriverName() === 'pgsql') {
            return ['ST_Distance(location, '.self::POINT_SQL.', false)', [$lng, $lat]];
        }

        return [
            '12742017.6 * asin(sqrt(min(1.0, pow(sin(radians(latitude - ?) / 2), 2) + cos(radians(latitude)) * cos(radians(?)) * pow(sin(radians(longitude - ?) / 2), 2))))',
            [$lat, $lat, $lng],
        ];
    }

    /** @param Builder<TravelGoContent> $query */
    public static function within(Builder $query, array $point, float $metres): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw('ST_DWithin(location, '.self::POINT_SQL.', ?, false)', [$point[1], $point[0], $metres]);

            return;
        }
        [$distance, $parameters] = self::distance($point);
        $query->whereRaw($distance.' <= CAST(? AS REAL)', [...$parameters, $metres]);
    }

    /** @param Builder<TravelGoContent> $query */
    public static function located(Builder $query): Builder
    {
        return DB::getDriverName() === 'pgsql' ? $query->whereNotNull('location')
            : $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    /** @param Builder<TravelGoContent> $query */
    public static function withDistance(Builder $query, array $point): Builder
    {
        [$distance, $parameters] = self::distance($point);

        return $query->select('travelgo_contents.*')->selectRaw($distance.' AS distance_m', $parameters);
    }

    /** @param Builder<TravelGoContent> $query */
    public static function orderedPage(Builder $query, array $ranks, string $scope, ?string $cursor, int $limit): array
    {
        if (($after = CatalogCursor::decode($cursor, $scope, count($ranks))) !== null) {
            $tuple = implode(', ', [...$ranks, 'id']);
            $parameters = implode(', ', array_fill(0, count($ranks) + 1, '?'));
            $query->whereRaw('('.$tuple.') > ('.$parameters.')', [...$after[0], $after[1]]);
        }
        foreach ($ranks as $rank) {
            $query->orderBy($rank);
        }
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $rows->pop();
            $last = $rows->last();
            $nextCursor = CatalogCursor::encode($scope, array_map(fn (string $rank): int => (int) $last->$rank, $ranks), $last->id);
        }

        return [$rows, $nextCursor];
    }

    public static function scopedHash(string $feed, array $coordinates, array $filters): string
    {
        return hash_hmac('sha256', json_encode([$feed, $coordinates, $filters], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param Builder<TravelGoContent> $query */
    public static function byMobileId(Builder $query, string $id): Builder
    {
        return $query->where(Str::isUuid($id) ? 'id' : 'slug', $id);
    }

    /** @return array<int, string> */
    public static function routeRelations(): array
    {
        return ['parent.parent', 'parent.region', 'startPlace', 'endPlace', 'stops'];
    }

    public static function saved(Collection $routes, ?int $userId): array
    {
        if ($userId === null || $routes->isEmpty()) {
            return [];
        }

        return array_fill_keys(DB::table('travelgo_saved_routes')->where('user_id', $userId)
            ->whereIn('route_id', $routes->modelKeys())->pluck('route_id')->all(), true);
    }
}
