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

#[Hidden(['location'])]
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

    public function publicKey(): string
    {
        return $this->country_code ?? $this->id;
    }
}
