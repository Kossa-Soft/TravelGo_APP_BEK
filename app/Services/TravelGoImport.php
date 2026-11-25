<?php

namespace App\Services;

use App\Models\TravelGoContent;
use App\Support\ContentStatus;
use App\Support\TravelGoFields;
use App\Support\TravelGoType;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TravelGoImport
{
    public function __construct(private readonly TravelGoCatalog $catalog, private readonly TravelGoMedia $media) {}

    /** @return array<string, array{created:int, updated:int, unchanged:int}> */
    public function run(string $file, bool $mirror, bool $dryRun): array
    {
        $contents = is_readable($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            throw new RuntimeException('Catalog file cannot be read.');
        }
        $catalog = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        foreach (TravelGoType::cases() as $type) {
            if (! is_array($catalog[$type->value] ?? null) || ! array_is_list($catalog[$type->value])) {
                throw new RuntimeException('Missing catalog list: '.$type->value);
            }
        }
        $this->prepareImages($catalog, $mirror && ! $dryRun);

        if ($dryRun) {
            DB::beginTransaction();
        }
        try {
            return $this->catalog->write(fn (): array => $this->persist($catalog));
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }
    }

    private function prepareImages(array &$catalog, bool $mirror): void
    {
        $places = [];
        foreach ($catalog['cities'] as &$city) {
            $cover = $city['coverImage'] ?? null;
            if (is_array($cover)) {
                $city['coverImage'] = $mirror ? $this->media->mirror($cover['url'], TravelGoType::City) : $cover['url'];
                $city['coverCredit'] = $cover['credit'] ?? null;
            }
        }
        unset($city);
        foreach ($catalog['places'] as &$place) {
            foreach ($place['images'] ?? [] as $index => $image) {
                if ($mirror) {
                    try {
                        $place['images'][$index]['url'] = $this->media->mirror($image['url'], TravelGoType::Place);
                    } catch (Throwable $error) {
                        throw new RuntimeException('Failed to prepare photo for '.$place['id'].': '.$error->getMessage(), previous: $error);
                    }
                }
            }
            $places[$place['id']] = $place['images'][0] ?? [];
        }
        unset($place);
        foreach ($catalog['routes'] as &$route) {
            if (isset($route['coverPlaceId'])) {
                $cover = $places[$route['coverPlaceId']] ?? [];
                $route['coverImage'] = $cover['url'] ?? null;
                $route['coverCredit'] = $cover['credit'] ?? null;
            }
        }
    }

    private function persist(array $catalog): array
    {
        $ids = [];
        $counts = [];
        foreach (TravelGoType::cases() as $type) {
            $counts[$type->value] = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($catalog[$type->value] as $row) {
                $key = $row[$type === TravelGoType::Country ? 'code' : 'id'] ?? '';
                if (isset($ids[$type->value][$key]) || ! is_string($key)
                    || ($type !== TravelGoType::Country && ! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $key))
                    || strlen($key) > 160
                ) {
                    throw new RuntimeException('Duplicate or invalid catalog identifier in '.$type->value.'.');
                }
                try {
                    [$record, $result] = $this->persistRecord($type, $key, $row, $ids, $catalog['boundaries'][$key] ?? null);
                } catch (Throwable $error) {
                    throw new RuntimeException($type->value.'/'.$key.': '.$error->getMessage(), previous: $error);
                }
                $ids[$type->value][$key] = $record->id;
                $counts[$type->value][$result]++;
            }
        }

        return $counts;
    }

    private function persistRecord(TravelGoType $type, string $key, array $row, array $ids, ?array $boundary): array
    {
        $record = TravelGoContent::ofType($type)->where($type === TravelGoType::Country ? 'country_code' : 'slug', $key)->first();
        $previouslyImported = $record?->import_hash !== null;
        $hash = hash('sha256', json_encode([$row, $boundary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($record?->import_hash === $hash) {
            return [$record, 'unchanged'];
        }

        $input = $this->resolveReferences($row, $ids);
        if ($type === TravelGoType::Country && $record !== null) {
            unset($input['code']);
        }
        $validated = validator($input, TravelGoFields::rules($type, $record))->validate();
        $isNew = $record === null;
        $record = $this->catalog->save($type, $record?->publicKey(), $validated, null, calculateGeometry: false);
        if ($type === TravelGoType::Route) {
            $stops = validator(['stops' => $input['stops'] ?? null], TravelGoFields::stopRules())->validate()['stops'];
            $record = $this->catalog->replaceStops($record->id, $stops, null, useProvider: false);
        }
        if ($type === TravelGoType::Country && $boundary !== null && DB::getDriverName() === 'pgsql') {
            DB::update(
                'UPDATE travelgo_contents SET country_boundary = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3)) WHERE id = ?',
                [json_encode($boundary, JSON_THROW_ON_ERROR), $record->id],
            );
        }

        $record->slug = $type === TravelGoType::Country ? null : $key;
        $record->import_hash = $hash;
        $record->save();
        if (! $previouslyImported && ($row['status'] ?? null) === 'published' && $record->status === ContentStatus::Draft) {
            $this->catalog->publishImported($record);
        }
        $this->catalog->audit($record, null, 'import', ['sourceId' => $key]);

        return [$record, $isNew ? 'created' : 'updated'];
    }

    private function resolveReferences(array $row, array $ids): array
    {
        foreach (['regionId' => 'regions', 'cityId' => 'cities'] as $field => $type) {
            if (isset($row[$field])) {
                $row[$field] = $this->reference($ids, $type, $row[$field]);
            }
        }
        foreach (['start', 'end'] as $point) {
            if (isset($row[$point]['placeId'])) {
                $row[$point]['placeId'] = $this->reference($ids, 'places', $row[$point]['placeId']);
            }
        }
        foreach ($row['stops'] ?? [] as $index => $stop) {
            $row['stops'][$index]['placeId'] = $this->reference($ids, 'places', $stop['placeId']);
        }

        return $row;
    }

    private function reference(array $ids, string $type, string $slug): string
    {
        return $ids[$type][$slug] ?? throw new RuntimeException('Catalog reference does not exist: '.$type.'/'.$slug);
    }
}
