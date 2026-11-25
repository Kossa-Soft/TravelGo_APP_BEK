<?php

namespace App\Services;

use App\Exceptions\ContentException;
use App\Models\AdminUser;
use App\Models\TravelGoContent;
use App\Support\ContentStatus;
use App\Support\TravelGoType;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TravelGoCatalog
{
    public function __construct(private TravelGoGeo $geo) {}

    private const array DATA_FIELDS = [
        'timeZone', 'radiusKm', 'coverImage', 'coverCredit', 'category', 'shortDescription', 'description', 'history', 'facts',
        'address', 'hours', 'images', 'visitMinutes', 'website', 'wiki', 'priority', 'about', 'transport',
        'source', 'badge', 'isOfficial', 'isLoop', 'start', 'end', 'sortPriority',
    ];

    public function find(TravelGoType $type, string $id): TravelGoContent
    {
        abort_unless($type === TravelGoType::Country ? preg_match('/\A[A-Z]{2}\z/', $id) : Str::isUuid($id), 404);

        return TravelGoContent::ofType($type)->where($type === TravelGoType::Country ? 'country_code' : 'id', $id)->firstOrFail();
    }

    public function save(TravelGoType $type, ?string $id, array $input, ?AdminUser $actor, bool $calculateGeometry = true): TravelGoContent
    {
        return $this->write(function () use ($type, $id, $input, $actor, $calculateGeometry): TravelGoContent {
            $record = $id === null ? new TravelGoContent : $this->find($type, $id);
            $record->type = $type;
            $this->applyFields($record, $input);
            $geometryFields = array_fill_keys(['start', 'end', 'isLoop', 'durationOverride'], true);
            $original = $record->getOriginal('data') ?? [];
            $recalculate = $calculateGeometry && $type === TravelGoType::Route && ($id === null || $record->isDirty('parent_id')
                || array_intersect_key($record->data, $geometryFields) != array_intersect_key($original, $geometryFields));
            $record->save();
            if ($recalculate && $this->recalculate($record, useProvider: true)) {
                $record->save();
            }
            if ($record->status === ContentStatus::Published && ($calculateGeometry || $type !== TravelGoType::Route)) {
                $this->checkPublishable($record);
            }
            $this->audit($record, $actor, $id === null ? 'create' : 'edit', ['fields' => array_keys($input)]);
            if ($type === TravelGoType::Place && $record->wasChanged(['latitude', 'longitude'])) {
                $this->usingPlace($record)->each(function (TravelGoContent $route) use ($actor): void {
                    $this->recalculate($route);
                    $route->save();
                    $this->audit($route, $actor, 'recalculate');
                });
            }

            return $record;
        });
    }

    public function attachImage(TravelGoType $type, string $id, string $url, ?string $credit, AdminUser $actor): TravelGoContent
    {
        return $this->write(function () use ($type, $id, $url, $credit, $actor): TravelGoContent {
            $record = $this->find($type, $id);
            if ($type === TravelGoType::Place) {
                $images = $record->data['images'] ?? [];
                if (count($images) >= 12) {
                    $this->invalid('file', 'Для места можно загрузить не больше 12 фото.');
                }

                return $this->save($type, $id, ['images' => [...$images, ['url' => $url, 'credit' => $credit]]], $actor);
            }

            return $this->save($type, $id, ['coverImage' => $url, 'coverCredit' => $credit], $actor);
        });
    }

    private function applyFields(TravelGoContent $record, array $input): void
    {
        $data = array_replace($this->defaults($record->type), $record->data);
        foreach (self::DATA_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        if (isset($input['name']) || isset($input['title'])) {
            $record->name = $input['name'] ?? $input['title'];
        }
        if (isset($input['code'])) {
            $record->country_code = $input['code'];
        }
        if (isset($input['sortOrder'])) {
            $record->sort_order = $input['sortOrder'];
        }
        if (isset($input['countryCode'])) {
            $record->parent_id = $this->reference(TravelGoType::Country, $input['countryCode'], 'countryCode')->id;
        }
        if (isset($input['cityId'])) {
            $record->parent_id = $this->reference(TravelGoType::City, $input['cityId'], 'cityId')->id;
        }
        if (array_key_exists('regionId', $input)) {
            $record->region_id = $input['regionId'] === null ? null : $this->reference(TravelGoType::Region, $input['regionId'], 'regionId')->id;
        }
        if ($record->region_id !== null && $record->region()->first()->parent_id !== $record->parent_id) {
            $this->invalid('regionId', 'Выберите регион этой страны.');
        }
        if ($record->exists && $record->isDirty('parent_id')
            && ($this->hasChildren($record) || ($record->type === TravelGoType::Place && $this->usingPlace($record)->isNotEmpty())
                || ($record->type === TravelGoType::Route && $record->stops()->exists()))
        ) {
            $this->invalid('parent', 'Сначала перенесите или отвяжите связанные объекты.');
        }

        foreach (['latitude', 'longitude'] as $coordinate) {
            if (array_key_exists($coordinate, $input)) {
                $record->$coordinate = $input[$coordinate];
            }
        }
        if (($record->latitude === null) !== ($record->longitude === null)) {
            $this->invalid('latitude', 'Укажите широту и долготу вместе.');
        }
        if ($record->type === TravelGoType::Route) {
            $data['isLoop'] = (bool) $data['isLoop'];
            $data['isOfficial'] = (bool) $data['isOfficial'];
            if ($data['isLoop']) {
                $data['end'] = null;
            }
            $record->start_place_id = $this->waypointPlace($record, $data['start'], 'start');
            $record->end_place_id = $this->waypointPlace($record, $data['end'], 'end');
            if (array_key_exists('durationMinutes', $input)) {
                $data['durationOverride'] = $input['durationMinutes'] === null ? null : (int) $input['durationMinutes'];
            }
        }
        $record->data = $data;
    }

    private function defaults(TravelGoType $type): array
    {
        return match ($type) {
            TravelGoType::City => ['radiusKm' => 15],
            TravelGoType::Place => ['priority' => 3, 'visitMinutes' => 20, 'images' => []],
            TravelGoType::Route => ['transport' => 'walk', 'source' => ['kind' => 'travelgo'], 'isOfficial' => true, 'isLoop' => false,
                'sortPriority' => 0, 'start' => null, 'end' => null],
            default => [],
        };
    }

    private function waypointPlace(TravelGoContent $route, ?array $point, string $field): ?string
    {
        if ($point === null) {
            return null;
        }
        if (isset($point['placeId'])) {
            if (isset($point['latitude']) || isset($point['longitude'])) {
                $this->invalid($field, 'Выберите место или укажите координаты.');
            }
            $place = $this->reference(TravelGoType::Place, $point['placeId'], $field.'.placeId');
            if ($place->parent_id !== $route->parent_id) {
                $this->invalid($field, 'Место должно находиться в городе маршрута.');
            }

            return $place->id;
        }
        if (! isset($point['latitude'], $point['longitude'])) {
            $this->invalid($field, 'Выберите место или укажите широту и долготу.');
        }

        return null;
    }

    public function replaceStops(string $id, array $stops, ?AdminUser $admin, bool $useProvider = true): TravelGoContent
    {
        return $this->write(function () use ($id, $stops, $admin, $useProvider): TravelGoContent {
            $route = $this->find(TravelGoType::Route, $id);
            $places = TravelGoContent::ofType(TravelGoType::Place)->whereIn('id', array_column($stops, 'placeId'))->get()->keyBy('id');
            $rows = [];
            foreach ($stops as $position => $stop) {
                $place = $places->get($stop['placeId']);
                if ($place === null || $place->parent_id !== $route->parent_id) {
                    $this->invalid('stops.'.$position.'.placeId', 'Выберите место в городе маршрута.');
                }
                $rows[] = ['route_id' => $route->id, 'place_id' => $place->id, 'position' => $position + 1,
                    'visit_minutes' => (int) ($stop['visitMinutes'] ?? $place->data['visitMinutes'] ?? 20),
                    'note' => isset($stop['note']) ? json_encode($stop['note'], JSON_THROW_ON_ERROR) : null];
            }
            DB::table('travelgo_route_stops')->where('route_id', $route->id)->delete();
            if ($rows !== []) {
                DB::table('travelgo_route_stops')->insert($rows);
            }
            $this->recalculate($route, useProvider: $useProvider);
            $route->save();
            if ($route->status === ContentStatus::Published) {
                $this->checkPublishable($route);
            }
            $this->audit($route, $admin, 'stops', ['count' => count($rows)]);

            return $route;
        });
    }

    public function recalculateRoute(string $id, AdminUser $admin): TravelGoContent
    {
        return $this->write(function () use ($id, $admin): TravelGoContent {
            $route = $this->find(TravelGoType::Route, $id);
            $this->recalculate($route, useProvider: true);
            $route->save();
            if ($route->status === ContentStatus::Published) {
                $this->checkPublishable($route);
            }
            $this->audit($route, $admin, 'recalculate');

            return $route;
        });
    }

    private function recalculate(TravelGoContent $route, bool $useProvider = false): bool
    {
        $stops = $route->stops()->get();
        $waypoints = TravelGoContent::query()
            ->whereIn('id', array_filter([$route->start_place_id, $route->end_place_id]))->get()->keyBy('id');
        $data = $route->data;
        $start = $this->point($data['start'], $waypoints, $stops->first());
        $end = $data['isLoop'] ? $start : $this->point($data['end'], $waypoints, $stops->last());
        $points = [$start, ...$stops->map($this->coordinates(...))->all(), $end];
        $complete = count($stops) >= 2 && ! in_array(null, $points, true);
        $coordinates = [];
        $distance = 0.0;
        if ($complete) {
            foreach ($points as $point) {
                $prev = $coordinates === [] ? null : $coordinates[array_key_last($coordinates)];
                if ($prev === $point) {
                    continue;
                }
                if ($prev !== null) {
                    $distance += $this->distance($prev, $point) * 1.3;
                }
                $coordinates[] = $point;
            }
        }

        $route->longitude = $start[0] ?? null;
        $route->latitude = $start[1] ?? null;
        $road = $useProvider && count($coordinates) >= 2 ? $this->geo->walkingRoute($coordinates) : null;
        $data['path'] = count($coordinates) >= 2 ? ['type' => 'LineString', 'coordinates' => $road['coordinates'] ?? $coordinates] : null;
        $data['pathSource'] = $data['path'] === null ? null : ($road === null ? 'estimated' : 'routing');
        $data['placesCount'] = $stops->count();
        $data['distanceKm'] = $data['path'] === null ? null : ($road['distanceKm'] ?? round($distance, 2));
        $data['durationMinutes'] = $data['durationOverride'] ?? ($data['path'] === null ? null
            : ($road['minutes'] ?? (int) round($distance * 12)) + $stops->sum(fn (TravelGoContent $stop): int => (int) $stop->pivot->visit_minutes));
        $route->data = $data;

        return $route->isDirty();
    }

    private function point(?array $point, Collection $waypoints, ?TravelGoContent $fallback): ?array
    {
        if (isset($point['placeId'])) {
            return $this->coordinates($waypoints->get($point['placeId']));
        }
        if ($point === null) {
            return $this->coordinates($fallback);
        }

        return [(float) $point['longitude'], (float) $point['latitude']];
    }

    private function coordinates(?TravelGoContent $record): ?array
    {
        return $record?->latitude === null || $record->longitude === null ? null : [$record->longitude, $record->latitude];
    }

    private function distance(array $from, array $to): float
    {
        [$lon1, $lat1] = array_map(deg2rad(...), $from);
        [$lon2, $lat2] = array_map(deg2rad(...), $to);
        $delta = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lon2 - $lon1) / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($delta)));
    }

    public function transition(TravelGoType $type, string $id, ContentStatus $target, ?string $reason, ?string $time, bool $force, AdminUser $admin): TravelGoContent
    {
        return $this->write(function () use ($type, $id, $target, $reason, $time, $force, $admin): TravelGoContent {
            $record = $this->find($type, $id);
            $this->changeStatus($record, $target, $reason, $time, $force, $admin);

            return $record;
        });
    }

    public function publishBatch(array $items, AdminUser $admin): array
    {
        return $this->write(function () use ($items, $admin): array {
            $published = [];
            $order = array_flip(array_column(TravelGoType::cases(), 'value'));
            usort($items, fn (array $left, array $right): int => $order[$left['type']] <=> $order[$right['type']]);
            foreach ($items as $item) {
                $record = $this->find(TravelGoType::from($item['type']), $item['id']);
                if ($record->status !== ContentStatus::Published) {
                    $this->changeStatus($record, ContentStatus::Published, null, null, false, $admin);
                }
                $published[] = $record;
            }

            return $published;
        });
    }

    public function publishImported(TravelGoContent $record): void
    {
        $this->changeStatus($record, ContentStatus::Published, null, null, false, null);
    }

    private function changeStatus(TravelGoContent $record, ContentStatus $target, ?string $reason, ?string $time, bool $force, ?AdminUser $admin): void
    {
        $from = $record->status;
        if ($admin !== null) {
            abort_unless($admin->hasPermission($record->type->permission($from->action($target))), 403);
            if (! in_array($target, $from->editorialTransitions(), true)) {
                throw new ContentException('INVALID_STATUS_TRANSITION', 409, 'Этот переход между статусами недоступен.');
            }
        }
        if (in_array($target, [ContentStatus::Hidden, ContentStatus::Rejected], true) && blank($reason)) {
            $this->invalid('reason', 'Укажите причину смены статуса.');
        }
        if ($target === ContentStatus::Scheduled && ($time === null || now()->parse($time)->lessThanOrEqualTo(now()))) {
            $this->invalid('publishAt', 'Укажите дату и время публикации в будущем.');
        }
        if (in_array($target, [ContentStatus::Published, ContentStatus::Scheduled], true)) {
            $this->checkPublishable($record);
        }

        if ($record->type === TravelGoType::Place && in_array($target, [ContentStatus::Hidden, ContentStatus::Archived], true)) {
            $routes = $this->usingPlace($record, publishedOnly: true);
            if ($routes->isNotEmpty() && ! $force) {
                if ($admin !== null && ! $admin->hasPermission(TravelGoType::Route->permission('view'))) {
                    throw new ContentException('PLACE_IN_PUBLISHED_ROUTES', 409, 'Место входит в опубликованные маршруты.');
                }
                throw new ContentException('PLACE_IN_PUBLISHED_ROUTES', 409, 'Место входит в опубликованные маршруты.', [
                    'routes' => $routes->map(fn (TravelGoContent $route): array => ['id' => $route->id, 'title' => $route->title()])->all(),
                    'problems' => $routes->pluck('name')->map(fn (array $name): string => $name['uk'] ?? $name['en'] ?? 'Маршрут')->all(),
                ]);
            }
            foreach ($routes as $route) {
                $this->changeStatus($route, ContentStatus::Hidden, 'place_unavailable', null, false, $admin);
            }
        }

        $record->status = $target;
        $record->publish_at = $target === ContentStatus::Scheduled ? $time : null;
        if ($target === ContentStatus::Published && $record->published_at === null) {
            $record->published_at = now();
        }
        $record->status_reason = $reason;
        $record->status_changed_at = now();
        $record->status_changed_by = $admin?->id;
        $record->save();
        $this->audit($record, $admin, 'status', ['from' => $from->value, 'to' => $target->value, 'reason' => $reason]);
    }

    public function publishDue(): int
    {
        $processed = 0;
        $due = TravelGoContent::query()->where('status', ContentStatus::Scheduled->value)
            ->where('publish_at', '<=', now())->orderBy('publish_at')->limit(100)->pluck('id');

        foreach ($due as $id) {
            $this->write(function () use ($id, &$processed): void {
                $record = TravelGoContent::find($id);
                if ($record?->status !== ContentStatus::Scheduled || $record->publish_at->isFuture()) {
                    return;
                }
                try {
                    $this->changeStatus($record, ContentStatus::Published, null, null, false, null);
                } catch (ContentException $error) {
                    $this->changeStatus($record, ContentStatus::Draft, 'publish_check_failed', null, false, null);
                    $this->audit($record, null, 'publication_failed', $error->details);
                }
                $processed++;
            });
        }

        return $processed;
    }

    private function checkPublishable(TravelGoContent $record): void
    {
        $data = $record->data;
        $type = $record->type;
        $problems = [];
        foreach (['uk', 'en'] as $language) {
            if (blank($record->name[$language] ?? null)) {
                $problems[] = 'Заполните название на '.strtoupper($language).'.';
            }
            if ($type === TravelGoType::Place && blank($data['shortDescription'][$language] ?? null)
                || $type === TravelGoType::Route && (blank($data['description'][$language] ?? null) || ! collect($data['about'] ?? [])->contains(fn (array $text): bool => filled($text[$language] ?? null)))
            ) {
                $problems[] = 'Заполните описание на '.strtoupper($language).'.';
            }
        }
        if ($record->parent_id !== null && ! $this->isVisible($record->parent()->first())
            || $record->region_id !== null && ! $this->isVisible($record->region()->first())
        ) {
            $problems[] = 'Опубликуйте страну, регион или город, к которому относится объект.';
        }
        if (in_array($type, [TravelGoType::City, TravelGoType::Place], true) && $record->latitude === null) {
            $problems[] = 'Укажите координаты.';
        }
        if ($type === TravelGoType::City && (blank($data['timeZone'] ?? null) || blank($data['radiusKm'] ?? null))) {
            $problems[] = 'Укажите часовой пояс и радиус города.';
        }
        if (in_array($type, [TravelGoType::City, TravelGoType::Route], true) && blank($data['coverImage'] ?? null)) {
            $problems[] = 'Добавьте обложку.';
        }
        if ($type === TravelGoType::Place) {
            if (blank($data['category'] ?? null) || empty($data['images'])) {
                $problems[] = 'Выберите категорию и добавьте хотя бы одно фото.';
            }
            $city = $record->parent()->first();
            $cityPoint = $this->coordinates($city);
            $placePoint = $this->coordinates($record);
            if ($placePoint !== null && $cityPoint !== null && $this->distance($cityPoint, $placePoint) > ($city->data['radiusKm'] ?? 15) * 3) {
                $problems[] = 'Место находится слишком далеко от центра города.';
            }
        }
        if ($type === TravelGoType::Route) {
            if (($data['placesCount'] ?? 0) < 2 || empty($data['path'])) {
                $problems[] = 'Добавьте хотя бы две остановки с координатами и рассчитайте линию маршрута.';
            }
            if ($this->routePlaces($record)->contains(fn (TravelGoContent $place): bool => ! $this->isVisible($place))) {
                $problems[] = 'Опубликуйте все остановки и места старта и финиша.';
            }
        }
        if ($problems !== []) {
            throw new ContentException('PUBLISH_CHECK_FAILED', 422, 'Проверьте условия публикации.', ['problems' => $problems]);
        }
    }

    public function publicationWarnings(TravelGoContent $record): array
    {
        $data = $record->data;
        $warnings = [];
        $missing = array_filter(['de', 'es', 'fr'], fn (string $language): bool => blank($record->name[$language] ?? null));
        if ($missing !== []) {
            $warnings[] = 'Нет перевода названия: '.strtoupper(implode(', ', $missing)).'.';
        }
        if ($record->type === TravelGoType::Place) {
            if (empty($data['history'])) {
                $warnings[] = 'У места нет истории.';
            }
            if (empty($data['hours'])) {
                $warnings[] = 'У места нет часов работы.';
            }
            if (collect($data['images'] ?? [])->contains(fn (array $image): bool => blank($image['credit'] ?? null))) {
                $warnings[] = 'У одного или нескольких фото нет автора или лицензии.';
            }
        }

        return $warnings;
    }

    public function isVisible(?TravelGoContent $record): bool
    {
        return $record !== null && $record->status === ContentStatus::Published
            && ($record->parent_id === null || $this->isVisible($record->parent()->first()))
            && ($record->region_id === null || $this->isVisible($record->region()->first()))
            && ($record->type !== TravelGoType::Route || $this->routePlaces($record)->every($this->isVisible(...)));
    }

    private function routePlaces(TravelGoContent $route): Collection
    {
        $ids = DB::table('travelgo_route_stops')->where('route_id', $route->id)->pluck('place_id')->all();

        return TravelGoContent::ofType(TravelGoType::Place)
            ->whereIn('id', array_filter([...$ids, $route->start_place_id, $route->end_place_id]))->get();
    }

    public function usingPlace(TravelGoContent $place, bool $publishedOnly = false): Collection
    {
        return TravelGoContent::ofType(TravelGoType::Route)
            ->when($publishedOnly, fn ($query) => $query->where('status', ContentStatus::Published->value))
            ->where(fn ($query) => $query->where('start_place_id', $place->id)->orWhere('end_place_id', $place->id)
                ->orWhereIn('id', DB::table('travelgo_route_stops')->select('route_id')->where('place_id', $place->id)))
            ->orderBy('id')->get();
    }

    public function duplicateRoute(string $id, AdminUser $admin): TravelGoContent
    {
        return $this->write(function () use ($id, $admin): TravelGoContent {
            $route = $this->find(TravelGoType::Route, $id);
            $copy = $route->replicate(['location', 'country_boundary', 'slug', 'import_hash', 'status', 'publish_at', 'published_at', 'status_reason', 'status_changed_at', 'status_changed_by']);
            $copy->status = ContentStatus::Draft;
            $copy->save();
            $rows = DB::table('travelgo_route_stops')->where('route_id', $route->id)->get()
                ->map(fn (object $row): array => ['route_id' => $copy->id] + (array) $row)->all();
            if ($rows !== []) {
                DB::table('travelgo_route_stops')->insert($rows);
            }
            $this->audit($copy, $admin, 'duplicate', ['source' => $route->id]);

            return $copy;
        });
    }

    public function delete(TravelGoType $type, string $id, AdminUser $admin): TravelGoContent
    {
        return $this->write(function () use ($type, $id, $admin): TravelGoContent {
            $record = $this->find($type, $id);
            if ($record->status !== ContentStatus::Draft || $record->published_at !== null || $this->hasChildren($record)
                || ($type === TravelGoType::Place && $this->usingPlace($record)->isNotEmpty())
            ) {
                throw new ContentException('CONTENT_CANNOT_BE_DELETED', 409, 'Удалить можно только черновик без публикаций и связанных объектов.');
            }
            $this->audit($record, $admin, 'delete');
            $record->delete();

            return $record;
        });
    }

    private function hasChildren(TravelGoContent $record): bool
    {
        return TravelGoContent::query()->where('parent_id', $record->id)->orWhere('region_id', $record->id)->exists();
    }

    public function audit(TravelGoContent $record, ?AdminUser $actor, string $action, array $details = []): void
    {
        DB::table('travelgo_audit_events')->insert([
            'content_id' => $record->id, 'admin_id' => $actor?->id, 'actor' => $actor?->email,
            'type' => $record->type->value, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    private function reference(TravelGoType $type, string $id, string $field): TravelGoContent
    {
        $result = TravelGoContent::ofType($type)->where($type === TravelGoType::Country ? 'country_code' : 'id', $id)->first();
        if ($result === null) {
            $this->invalid($field, 'Связанный объект не найден.');
        }

        return $result;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function write(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [87923, 1]);
            }

            return $callback();
        }, 3);
    }
}
