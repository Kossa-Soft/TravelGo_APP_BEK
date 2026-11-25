<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\MobileApiException;
use App\Http\Controllers\Controller;
use App\Models\TravelGoContent;
use App\Services\MobileAuth;
use App\Services\MobileCatalog;
use App\Services\TravelGoImages;
use App\Services\TravelGoMedia;
use App\Support\TravelGoFields;
use App\Support\TravelGoType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogController extends Controller
{
    public function __construct(private readonly MobileCatalog $catalog, private readonly MobileAuth $auth) {}

    public function locate(Request $request): JsonResponse
    {
        return $this->json($request, $this->catalog->locate($this->point($request), self::language($request)), 120);
    }

    public function nearbyPlaces(Request $request): JsonResponse
    {
        $point = $this->point($request);
        $input = $this->page($request);
        $places = $this->catalog->nearbyPlaces($point, (int) ($input['maxKm'] ?? 50), $this->categories($request),
            $input['cursor'] ?? null, (int) ($input['limit'] ?? 20), self::language($request));

        return $this->json($request, $places, 120);
    }

    public function mapPlaces(Request $request): JsonResponse
    {
        $box = $this->coordinates($request, ['minLat' => 90, 'minLng' => 180, 'maxLat' => 90, 'maxLng' => 180]);
        if ($box[0] >= $box[2] || $box[1] === $box[3]) {
            throw new MobileApiException('INVALID_COORDINATES', 400, 'The map bounds must enclose an area.');
        }

        return $this->json($request, $this->catalog->mapPlaces($box, $this->categories($request), self::language($request)), 120);
    }

    public function place(Request $request, string $id): JsonResponse
    {
        $point = $request->query->has('lat') || $request->query->has('lng') ? $this->point($request) : null;

        return $this->json($request, $this->catalog->place($id, $point, self::language($request), $this->userId($request)), $point === null ? 300 : 120);
    }

    public function nearbyRoutes(Request $request): JsonResponse
    {
        $point = $this->point($request);
        $input = $this->page($request);
        $routes = $this->catalog->nearbyRoutes($point, (int) ($input['maxKm'] ?? 150), $input['transport'] ?? null,
            $input['cursor'] ?? null, (int) ($input['limit'] ?? 20), self::language($request), $this->userId($request));

        return $this->json($request, $routes, 120);
    }

    public function route(Request $request, string $id): JsonResponse
    {
        return $this->json($request, $this->catalog->route($id, self::language($request), $this->userId($request)), 300);
    }

    public function cities(Request $request): JsonResponse
    {
        return $this->json($request, $this->catalog->cities(self::language($request)), 300);
    }

    public function media(Request $request, string $id, string $type, string $image, int $size, TravelGoMedia $media): StreamedResponse
    {
        $owner = TravelGoContent::query()->findOrFail($id);
        abort_unless(in_array($owner->type, [TravelGoType::Place, TravelGoType::Route, TravelGoType::City], true)
            && TravelGoContent::visible($owner->type)->whereKey($owner->id)->exists() && $media->owns($owner, $type, $image), 404);
        $disk = Storage::disk('local');
        $path = TravelGoImages::path($type, $image, $size);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, 'catalog.webp', ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function language(Request $request): string
    {
        return $request->getPreferredLanguage(['en', 'uk', 'de', 'es', 'fr']) ?? 'en';
    }

    private function json(Request $request, array $data, int $seconds): JsonResponse
    {
        $cache = $request->bearerToken() === null ? 'private, max-age='.$seconds : 'private, no-cache';
        $response = response()->json($data)->header('Cache-Control', $cache);
        $response->setVary(['Accept-Language', 'Authorization']);
        $response->setEtag(hash('sha256', $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }

    private function userId(Request $request): ?int
    {
        return ($token = $request->bearerToken()) === null ? null : $this->auth->authenticate($token)->user_id;
    }

    private function point(Request $request): array
    {
        return $this->coordinates($request, ['lat' => 90, 'lng' => 180]);
    }

    private function coordinates(Request $request, array $bounds): array
    {
        $values = [];
        foreach ($bounds as $field => $max) {
            $value = $request->query($field);
            if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > $max) {
                throw new MobileApiException('INVALID_COORDINATES', 400, 'Enter valid latitude and longitude.', $field);
            }
            $values[] = round((float) $value, 3);
        }

        return $values;
    }

    private function page(Request $request): array
    {
        return validator($request->query(), [
            'maxKm' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:1536'],
            'transport' => ['sometimes', Rule::in(['walk', 'car'])],
        ])->validate();
    }

    private function categories(Request $request): array
    {
        $input = validator($request->query(), ['category' => ['nullable', 'string', 'max:1000']])->validate();
        if (blank($input['category'] ?? null)) {
            return [];
        }
        $categories = array_unique(array_map(trim(...), explode(',', $input['category'])));
        if (array_diff($categories, TravelGoFields::CATEGORIES) !== []) {
            throw new MobileApiException('VALIDATION_ERROR', 400, 'Unknown place category.', 'category');
        }
        sort($categories);

        return $categories;
    }
}
