<?php

namespace App\Support;

use App\Models\TravelGoContent;
use Illuminate\Validation\Rule;

class TravelGoFields
{
    public const array LANGUAGES = ['uk', 'en', 'de', 'es', 'fr'];

    public const array CATEGORIES = [
        'monument', 'museum', 'religion', 'palace', 'historic', 'architecture', 'bridge', 'square',
        'fountain', 'viewpoint', 'theatre', 'gallery', 'archaeology', 'unesco', 'park', 'waterfront',
        'beach', 'water', 'mountain', 'forest', 'nature_monument', 'botanical', 'restaurant', 'cafe',
        'bar', 'bakery', 'confectionery', 'streetfood', 'foodcourt', 'shop', 'mall', 'market',
        'boutique', 'souvenir', 'local_shop', 'hotel', 'hostel', 'apartment', 'camping', 'metro',
        'bus', 'railway', 'airport', 'ferry', 'bike', 'parking', 'ev', 'events', 'nightlife',
        'cinema', 'attractions', 'sport', 'pharmacy', 'hospital', 'atm', 'toilets', 'tourist_info',
        'police', 'embassy', 'drinking_water', 'playground', 'zoo', 'aquarium', 'amusement',
        'kids_museum', 'small_museum', 'streetart',
    ];

    public static function actions(): array
    {
        return ['view' => 'Просмотр', 'create' => 'Создание', 'edit' => 'Правка',
            'publish' => 'Публикация', 'archive' => 'Архив', 'delete' => 'Удаление'];
    }

    /** @return array<string, string> */
    public static function textFields(TravelGoType $type): array
    {
        return $type === TravelGoType::Route
            ? ['title' => 'Название', 'description' => 'Короткое описание']
            : ['name' => 'Название'] + ($type === TravelGoType::Place ? ['shortDescription' => 'Короткое описание'] : []);
    }

    /** @return array<string, string> */
    public static function paragraphFields(TravelGoType $type): array
    {
        return match ($type) {
            TravelGoType::Place => ['description' => 'Описание', 'history' => 'История', 'facts' => 'Факты'],
            TravelGoType::Route => ['about' => 'О маршруте'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public static function rules(TravelGoType $type, ?TravelGoContent $record): array
    {
        $new = $record === null;
        $rules = match ($type) {
            TravelGoType::Country => [
                'code' => $new ? ['required', 'regex:/\A[A-Z]{2}\z/', Rule::unique('travelgo_contents', 'country_code')] : ['prohibited'],
            ],
            TravelGoType::Region => ['countryCode' => [$new ? 'required' : 'sometimes', 'regex:/\A[A-Z]{2}\z/']],
            TravelGoType::City => [
                'countryCode' => [$new ? 'required' : 'sometimes', 'regex:/\A[A-Z]{2}\z/'],
                'regionId' => ['nullable', 'uuid'],
                'radiusKm' => ['nullable', 'numeric', 'min:0.1', 'max:200'],
                'timeZone' => ['nullable', 'timezone'],
                ...self::pointRules(),
                ...self::coverRules(),
            ],
            TravelGoType::Place => [
                'cityId' => [$new ? 'required' : 'sometimes', 'uuid'],
                'category' => ['nullable', Rule::in(self::CATEGORIES)],
                'address' => ['nullable', 'string', 'max:500'],
                'hours' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== 'always' && (! is_array($value) || ! isset($value['open'], $value['close'])
                        || array_diff(array_keys($value), ['open', 'close', 'closedOn']) !== [])
                    ) {
                        $fail('Укажите время открытия и закрытия либо режим «Круглосуточно».');
                    }
                }],
                'hours.open' => ['required_with:hours.close', 'date_format:H:i'],
                'hours.close' => ['required_with:hours.open', 'date_format:H:i'],
                'hours.closedOn' => ['sometimes', 'array', 'list', 'max:7'],
                'hours.closedOn.*' => ['integer', 'between:0,6', 'distinct'],
                'images' => ['sometimes', 'array', 'list', 'max:12'],
                'images.*' => ['required', 'array:url,credit'],
                'images.*.url' => ['required', 'url:https,http', 'max:2048'],
                'images.*.credit' => ['nullable', 'string', 'max:2000'],
                'visitMinutes' => ['nullable', 'integer', 'between:1,1440'],
                'website' => ['nullable', 'url:https,http', 'max:2048'],
                'wiki' => ['nullable', 'string', 'max:200'],
                'priority' => ['nullable', 'integer', 'between:1,5'],
                ...self::pointRules(),
            ],
            TravelGoType::Route => [
                'cityId' => [$new ? 'required' : 'sometimes', 'uuid'],
                'transport' => ['sometimes', Rule::in('walk')],
                'source' => ['sometimes', 'array:kind'],
                'source.kind' => ['required_with:source', Rule::in('travelgo')],
                'badge' => ['nullable', Rule::in('editorsChoice', 'popular', 'new', 'pro')],
                'isOfficial' => ['sometimes', 'boolean'],
                'isLoop' => ['sometimes', 'boolean'],
                'start' => ['nullable', 'array:placeId,name,latitude,longitude'],
                'end' => ['nullable', 'array:placeId,name,latitude,longitude'],
                'start.placeId' => ['nullable', 'uuid'],
                'end.placeId' => ['nullable', 'uuid'],
                ...self::pointRules('start.'),
                ...self::pointRules('end.'),
                'durationMinutes' => ['nullable', 'integer', 'between:1,15000'],
                ...self::coverRules(),
            ],
        };

        foreach (self::textFields($type) as $field => $label) {
            $rules += self::localizedRules($field, in_array($field, ['name', 'title'], true) ? 160 : 800);
        }
        foreach (self::paragraphFields($type) as $field => $label) {
            $rules[$field] = ['sometimes', 'array', 'list', 'max:'.($field === 'facts' ? 5 : 24)];
            $rules += self::localizedRules($field.'.*', 3000);
        }
        if ($type === TravelGoType::Route) {
            $rules += self::localizedRules('start.name', 160) + self::localizedRules('end.name', 160);
        }

        return ['sortOrder' => ['sometimes', 'integer', 'between:0,1000000'],
            'sortPriority' => ['sometimes', 'integer', 'between:0,1000000']] + $rules;
    }

    public static function stopRules(): array
    {
        return [
            'stops' => ['present', 'array', 'list', 'max:80'],
            'stops.*' => ['required', 'array:placeId,visitMinutes,note'],
            'stops.*.placeId' => ['required', 'uuid'],
            'stops.*.visitMinutes' => ['nullable', 'integer', 'between:1,1440'],
        ] + self::localizedRules('stops.*.note', 800);
    }

    private static function localizedRules(string $field, int $length): array
    {
        return [
            $field => ['sometimes', 'array:'.implode(',', self::LANGUAGES)],
            $field.'.*' => ['nullable', 'string', 'max:'.$length],
        ];
    }

    private static function pointRules(string $prefix = ''): array
    {
        return [
            $prefix.'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            $prefix.'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    private static function coverRules(): array
    {
        return [
            'coverImage' => ['nullable', 'url:https,http', 'max:2048'],
            'coverCredit' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
