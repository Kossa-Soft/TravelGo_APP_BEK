<?php

namespace App\Models;

use App\Support\ContentStatus;
use App\Support\TravelGoType;
use Database\Factories\TravelGoContentFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Hidden(['location', 'country_boundary', 'import_hash'])]
class TravelGoContent extends Model
{
    /** @use HasFactory<TravelGoContentFactory> */
    use HasFactory, HasUuids;

    protected $table = 'travelgo_contents';

    protected $attributes = ['status' => 'draft', 'sort_order' => 0, 'name' => '{}', 'data' => '{}'];

    protected function casts(): array
    {
        return [
            'type' => TravelGoType::class,
            'status' => ContentStatus::class,
            'name' => 'array',
            'data' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'publish_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
        ];
    }

    public function title(): string
    {
        return $this->name['uk'] ?? $this->name['en'] ?? array_values($this->name)[0] ?? $this->country_code ?? $this->id;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(self::class, 'region_id');
    }

    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'travelgo_route_stops', 'route_id', 'place_id')
            ->withPivot(['position', 'visit_minutes', 'note'])->orderByPivot('position');
    }

    /** @param Builder<self> $query */
    public function scopeOfType(Builder $query, TravelGoType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function startPlace(): BelongsTo
    {
        return $this->belongsTo(self::class, 'start_place_id');
    }

    public function endPlace(): BelongsTo
    {
        return $this->belongsTo(self::class, 'end_place_id');
    }

    /** @param Builder<self> $query */
    public function scopeVisible(Builder $query, TravelGoType $type): Builder
    {
        $query->ofType($type)->where('status', ContentStatus::Published->value);
        if (($parent = $type->parentType()) !== null) {
            $query->whereHas('parent', fn (Builder $query) => $query->visible($parent));
        }
        if ($type === TravelGoType::City) {
            $query->where(fn (Builder $query) => $query->whereNull('region_id')
                ->orWhereHas('region', fn (Builder $region) => $region->visible(TravelGoType::Region)));
        }
        if ($type === TravelGoType::Route) {
            $query->whereDoesntHave('stops', fn (Builder $stops) => $stops->whereNot(
                fn (Builder $place) => $place->visible(TravelGoType::Place),
            ));
            foreach (['startPlace' => 'start_place_id', 'endPlace' => 'end_place_id'] as $relation => $column) {
                $query->where(fn (Builder $route) => $route->whereNull($column)
                    ->orWhereHas($relation, fn (Builder $place) => $place->visible(TravelGoType::Place)));
            }
        }

        return $query;
    }

    public function mobileKey(): string
    {
        return $this->slug ?? $this->publicKey();
    }

    public function publicKey(): string
    {
        return $this->country_code ?? $this->id;
    }
}
