<?php

namespace Tests\Feature;

use App\Models\TravelGoContent;
use App\Models\User;
use App\Services\TravelGoImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_locate_city_uses_localized_names_and_validates_coordinates(): void
    {
        $this->importCatalog();
        $this->getJson('/v1/geo/locate?lat=50.4501&lng=30.5234', ['Accept-Language' => 'uk'])
            ->assertOk()->assertJsonPath('city.id', 'kyiv')->assertJsonPath('city.name', 'Київ')
            ->assertJsonPath('city.timeZone', 'Europe/Kyiv')->assertJsonPath('country.code', 'UA');
        $this->getJson('/v1/geo/locate?lat=0&lng=-30')->assertOk()
            ->assertJsonPath('city', null)->assertJsonPath('country', null);
        if (DB::getDriverName() === 'pgsql') {
            $this->getJson('/v1/geo/locate?lat=49.4&lng=26.5')->assertOk()
                ->assertJsonPath('city', null)->assertJsonPath('country.code', 'UA');
        }
        $this->getJson('/v1/geo/locate?lat=91&lng=30.5234')->assertBadRequest()
            ->assertJsonPath('error.code', 'INVALID_COORDINATES');
    }

    public function test_nearby_places_uses_stable_paging_and_hides_entries_in_unpublished_cities(): void
    {
        $this->importCatalog();
        $url = '/v1/places/nearby?lat=50.45&lng=30.52&limit=3';
        $response = $this->getJson($url)->assertOk()->assertJsonCount(3, 'items');
        $first = $response->json();
        $this->getJson($url, ['If-None-Match' => $response->headers->get('etag')])->assertNotModified();
        $second = $this->getJson($url.'&cursor='.rawurlencode($first['nextCursor']))->assertOk()->assertJsonCount(3, 'items')->json();

        $this->assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        $this->getJson(str_replace('50.45', '49', $url).'&cursor='.rawurlencode($first['nextCursor']))
            ->assertBadRequest()->assertJsonPath('error.code', 'INVALID_CURSOR');
        $this->getJson('/v1/places/in-area?minLat=50.40&minLng=30.40&maxLat=50.50&maxLng=30.60')
            ->assertOk()->assertJsonStructure([['id', 'name', 'category', 'latitude', 'longitude', 'priority']]);
        TravelGoContent::where('slug', 'kyiv')->update(['status' => 'hidden']);
        $this->getJson($url)->assertOk()->assertJsonPath('items', []);
        $this->getJson('/v1/places/kyiv-lavra')->assertNotFound();
    }

    public function test_routes_prioritize_local_city_then_nearby_and_return_the_remote_suggestion(): void
    {
        $this->importCatalog();
        $local = $this->getJson('/v1/routes/nearby?lat=50.45&lng=30.52&limit=2')->assertOk()
            ->assertJsonPath('city.id', 'kyiv')->assertJsonPath('items.0.scope', 'city')
            ->assertJsonPath('items.0.route.isOfficial', true)->assertJsonPath('suggestion', null)->json();
        $this->getJson('/v1/routes/nearby?lat=50.45&lng=30.52&limit=2&cursor='.rawurlencode($local['nextCursor']))
            ->assertOk()->assertJsonPath('items.0.scope', 'city');
        $this->getJson('/v1/routes/nearby?lat=50.45&lng=31.12')->assertOk()
            ->assertJsonPath('city', null)->assertJsonPath('items.0.scope', 'nearby');
        $this->getJson('/v1/routes/nearby?lat=0&lng=-30')->assertOk()->assertJsonPath('items', [])
            ->assertJsonStructure(['suggestion' => ['cities' => [['id', 'latitude', 'longitude', 'distanceKm', 'routesCount']]]]);
        $this->getJson('/v1/cities')->assertOk()->assertJsonCount(16);
    }

    public function test_route_and_place_details_use_mobile_slugs_and_hide_a_route_with_a_hidden_stop(): void
    {
        $this->importCatalog();
        $url = '/v1/routes/kyiv-golden-domes';
        $route = $this->getJson($url, ['Accept-Language' => 'uk'])->assertOk()
            ->assertJsonPath('id', 'kyiv-golden-domes')->assertJsonPath('path.type', 'LineString')
            ->assertJsonStructure(['coverCredit', 'end', 'stops' => [['place' => ['id', 'images']]]]);
        $this->getJson('/v1/places/kyiv-lavra')->assertOk()->assertJsonPath('distanceKm', null)
            ->assertJsonStructure(['address', 'history', 'images' => [['url', 'credit']]]);
        $this->getJson($url, ['Accept-Language' => 'uk', 'If-None-Match' => $route->headers->get('etag')])->assertNotModified();
        $uuid = TravelGoContent::where('slug', 'kyiv-golden-domes')->value('id');
        $this->getJson('/v1/routes/'.$uuid)->assertOk()->assertJsonPath('id', 'kyiv-golden-domes');

        $stop = $route->json('stops.0.place.id');
        TravelGoContent::where('slug', $stop)->update(['status' => 'hidden']);
        $this->getJson($url)->assertNotFound();
    }

    public function test_saved_routes_import_is_idempotent_private_and_keeps_hidden_route_identity(): void
    {
        $this->importCatalog();
        $path = '/v1/me/saved-routes';
        $url = '/v1/routes/kyiv-golden-domes';
        $this->getJson($path)->assertUnauthorized();
        User::factory()->create(['phone' => '+12025550147', 'password' => 'saved-route-password']);
        $access = $this->postJson('/v1/auth/login', [
            'phone' => '+12025550147', 'password' => 'saved-route-password',
        ])->assertOk()->json('accessToken');

        $this->withToken($access)->postJson($path.'/import', ['routeIds' => ['kyiv-golden-domes', 'no-such-route']])
            ->assertOk()->assertJsonPath('savedIds.0', 'kyiv-golden-domes')->assertJsonPath('unavailableIds.0', 'no-such-route');
        $this->putJson($path.'/kyiv-golden-domes')->assertNoContent();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.isSaved', true);
        $this->getJson($url)->assertOk()->assertJsonPath('isSaved', true);
        $this->withoutToken()->getJson($url)->assertOk()->assertJsonPath('isSaved', false);
        TravelGoContent::where('slug', 'kyiv-golden-domes')->update(['status' => 'hidden']);
        $this->withToken($access)->getJson($path)->assertJsonPath('items.0.status', 'hidden');
        $this->deleteJson($path.'/kyiv-golden-domes')->assertNoContent();
        $this->getJson($path)->assertJsonPath('items', []);
    }

    public function test_media_is_bound_to_a_visible_record_even_if_the_storage_id_is_known(): void
    {
        Storage::fake('local');
        $this->importCatalog();
        $place = TravelGoContent::where('slug', 'kyiv-lavra')->firstOrFail();
        $image = (string) Str::uuid();
        $url = route('admin.travelgo.media', ['places', $image, 1200]);
        $place->data = array_replace($place->data, ['images' => [['url' => $url, 'credit' => 'TravelGo']]]);
        $place->save();
        Storage::disk('local')->put(TravelGoImages::path('places', $image, 1200), 'image data');

        $publicUrl = $this->getJson('/v1/places/kyiv-lavra')->assertOk()->json('images.0.url');
        $this->get($publicUrl)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $other = TravelGoContent::where('slug', 'kyiv-sophia')->value('id');
        $this->get(str_replace($place->id, $other, $publicUrl))->assertNotFound();
        $place->status = 'hidden';
        $place->save();
        $this->get($publicUrl)->assertNotFound();
    }

    private function importCatalog(): void
    {
        $this->artisan('travelgo:import', ['--without-media' => true])->assertSuccessful();
    }
}
