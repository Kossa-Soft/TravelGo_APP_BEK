<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\TravelGoContent;
use App\Services\TravelGoCatalog;
use App\Services\TravelGoImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TravelGoContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_scoped_to_each_content_section_and_action(): void
    {
        $staff = AdminUser::factory()->create(['permissions' => ['travelgo.countries.view', 'travelgo.countries.create']]);
        $this->getJson('/admin/v1/countries')->assertUnauthorized();
        $this->actingAs($staff, 'admin');

        $this->get('/admin/travelgo')->assertOk()->assertSee('Страны')->assertDontSee('Администраторы');
        $this->get('/admin/travelgo/countries/create')->assertOk()->assertSee('Создать черновик');
        $this->postJson('/admin/v1/countries', ['code' => 'UA', 'name' => ['uk' => 'Україна', 'en' => 'Ukraine'], 'status' => 'published'])
            ->assertCreated()->assertJsonPath('status', 'draft');
        $this->getJson('/admin/v1/countries?q=Україна')->assertOk()->assertJsonPath('total', 1);
        $this->get('/admin/travelgo/countries/UA')->assertOk()->assertSee('Україна');
        $this->patchJson('/admin/v1/countries/UA', ['name' => ['uk' => 'Інша назва']])->assertForbidden();
        $this->postJson('/admin/v1/countries/UA/status', ['to' => 'published'])->assertForbidden();
        $this->getJson('/admin/v1/cities')->assertForbidden();
        $this->get('/admin/staff')->assertForbidden();
    }

    public function test_batch_publication_is_atomic_and_parent_visibility_is_inherited(): void
    {
        $this->signInAsSuperadmin();
        $city = $this->hierarchy();
        $place = $this->place($city, 'Музей', 50.45, 30.52, images: []);
        $items = [
            ['type' => 'places', 'id' => $place],
            ['type' => 'cities', 'id' => $city],
            ['type' => 'countries', 'id' => 'UA'],
        ];

        $this->postJson('/admin/v1/publish', ['items' => $items])->assertUnprocessable()
            ->assertJsonPath('error.code', 'PUBLISH_CHECK_FAILED');
        $this->getJson('/admin/v1/countries/UA')->assertJsonPath('status', 'draft');
        $this->assertDatabaseMissing('travelgo_audit_events', ['action' => 'status']);

        $this->patchJson('/admin/v1/places/'.$place, ['images' => [['url' => 'https://example.test/photo.webp', 'credit' => 'TravelGo']]])->assertOk();
        $this->postJson('/admin/v1/publish', ['items' => $items])->assertOk();
        $catalog = app(TravelGoCatalog::class);
        $this->assertTrue($catalog->isVisible(TravelGoContent::find($place)));

        $this->postJson('/admin/v1/countries/UA/status', ['to' => 'hidden', 'reason' => 'Сезон закрыт'])->assertOk();
        $this->assertFalse($catalog->isVisible(TravelGoContent::find($place)));
        $this->getJson('/admin/v1/places/'.$place)->assertJsonPath('status', 'published');
        $this->postJson('/admin/v1/countries/UA/status', ['to' => 'published'])->assertOk();
        $this->assertTrue($catalog->isVisible(TravelGoContent::find($place)));
        $this->get('/admin/travelgo/publication')->assertOk();
        $this->get('/admin/travelgo/audit')->assertOk()->assertSee('Сезон закрыт');
    }

    public function test_route_reorders_stops_and_requires_confirming_a_place_dependency_before_hiding(): void
    {
        $this->signInAsSuperadmin();
        $city = $this->hierarchy();
        $first = $this->place($city, 'Музей', 50.45, 30.52);
        $second = $this->place($city, 'Парк', 50.46, 30.53);
        $route = $this->postJson('/admin/v1/routes', [
            'cityId' => $city, 'title' => ['uk' => 'Київ пішки', 'en' => 'Kyiv on foot'],
            'description' => ['uk' => 'Прогулянка', 'en' => 'A walk'],
            'about' => [['uk' => 'Про маршрут', 'en' => 'About the route']],
            'coverImage' => 'https://example.test/cover.webp', 'coverCredit' => 'TravelGo',
        ])->assertCreated()->assertJsonPath('transport', 'walk')->assertJsonPath('isOfficial', true)->assertJsonPath('coverCredit', 'TravelGo')->json('id');

        $this->putJson('/admin/v1/routes/'.$route.'/stops', [
            ['placeId' => $second, 'visitMinutes' => 10, 'note' => ['uk' => 'Запасний вхід']], ['placeId' => $first],
        ])->assertOk()->assertJsonPath('stops.0.placeId', $second)->assertJsonPath('stops.0.note.uk', 'Запасний вхід')
            ->assertJsonPath('path.type', 'LineString')->assertJsonPath('path.coordinates.0', [30.53, 50.46])
            ->assertJsonPath('placesCount', 2);
        $this->get('/admin/travelgo/routes/'.$route)->assertOk()->assertSee('Остановки')->assertSee('Запасний вхід');
        $this->postJson('/admin/v1/publish', ['items' => [
            ['type' => 'routes', 'id' => $route], ['type' => 'places', 'id' => $first],
            ['type' => 'countries', 'id' => 'UA'], ['type' => 'places', 'id' => $second], ['type' => 'cities', 'id' => $city],
        ]])->assertOk();
        $this->postJson('/admin/v1/places/'.$second.'/status', ['to' => 'hidden', 'reason' => 'Ремонт'])
            ->assertConflict()->assertJsonPath('error.code', 'PLACE_IN_PUBLISHED_ROUTES')->assertJsonPath('error.details.routes.0.id', $route);
        $staff = AdminUser::factory()->create(['permissions' => ['travelgo.places.publish']]);
        $this->actingAs($staff, 'admin')->postJson('/admin/v1/places/'.$second.'/status?force=true', ['to' => 'hidden', 'reason' => 'Ремонт', 'force' => true])
            ->assertForbidden();
        $this->signInAsSuperadmin();
        $this->postJson('/admin/v1/places/'.$second.'/status?force=true', ['to' => 'hidden', 'reason' => 'Ремонт'])->assertOk();
        $this->getJson('/admin/v1/routes/'.$route)->assertJsonPath('status', 'hidden')->assertJsonPath('statusReason', 'place_unavailable');
        $this->postJson('/admin/v1/routes/'.$route.'/duplicate')->assertCreated()->assertJsonPath('status', 'draft')
            ->assertJsonPath('stops.0.placeId', $second);
        $this->deleteJson('/admin/v1/routes/'.$route)->assertConflict()->assertJsonPath('error.code', 'CONTENT_CANNOT_BE_DELETED');
    }

    public function test_scheduled_publication_rechecks_the_parent_and_records_a_failure(): void
    {
        $this->signInAsSuperadmin();
        $this->freezeTime();
        $city = $this->hierarchy();
        $this->postJson('/admin/v1/countries/UA/status', ['to' => 'published'])->assertOk();
        $this->postJson('/admin/v1/cities/'.$city.'/status', ['to' => 'scheduled', 'publishAt' => now()->addMinutes(5)->toISOString()])
            ->assertOk()->assertJsonPath('status', 'scheduled');
        $this->postJson('/admin/v1/countries/UA/status', ['to' => 'hidden', 'reason' => 'Проверка'])->assertOk();

        $this->travel(6)->minutes();
        $this->artisan('travelgo:publish-due')->assertSuccessful();

        $this->getJson('/admin/v1/cities/'.$city)->assertJsonPath('status', 'draft')->assertJsonPath('statusReason', 'publish_check_failed');
        $this->assertDatabaseHas('travelgo_audit_events', ['content_id' => $city, 'action' => 'publication_failed']);
    }

    public function test_content_upload_stores_two_derivatives_and_requires_the_correct_section_permission(): void
    {
        Storage::fake('local');
        $this->signInAsSuperadmin();
        $city = $this->hierarchy();
        $place = $this->place($city, 'Музей', 50.45, 30.52, images: []);

        $this->post('/admin/v1/places/'.$place.'/images', ['file' => UploadedFile::fake()->create('bad.png', 1, 'image/png')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $response = $this->post('/admin/v1/places/'.$place.'/images', [
            'file' => UploadedFile::fake()->image('museum.png', 800, 600), 'credit' => 'TravelGo',
        ], ['Accept' => 'application/json'])->assertCreated();
        $photo = $response->json();
        $id = Str::beforeLast(Str::after($photo['fileUrl'], '/media/places/'), '/');
        Storage::disk('local')->assertExists([TravelGoImages::path('places', $id, 400), TravelGoImages::path('places', $id, 1200)]);
        $this->get($photo['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->getJson('/admin/v1/places/'.$place)->assertJsonPath('images.0.credit', 'TravelGo');

        $viewer = AdminUser::factory()->create(['permissions' => ['travelgo.routes.view']]);
        $this->actingAs($viewer, 'admin')->get($photo['fileUrl'])->assertForbidden();
        $this->get(str_replace('/media/places/', '/media/routes/', $photo['fileUrl']))->assertNotFound();
    }

    public function test_staff_permission_changes_require_superadmin_reauthentication(): void
    {
        $this->signInAsSuperadmin();
        $staff = AdminUser::factory()->create();
        $route = route('admin.staff.update', $staff);

        $this->get(route('admin.staff.edit', $staff))->assertOk()->assertSee('Полный доступ к TravelGo');
        $this->patch($route, ['currentPassword' => 'wrong', 'permissions' => ['travelgo.*']])
            ->assertSessionHasErrors('currentPassword')->assertSessionMissing('_old_input.currentPassword');
        $this->patch($route, ['currentPassword' => 'factory-password', 'permissions' => ['staff.manage']])
            ->assertSessionHasErrors('permissions.0');
        $this->patch($route, ['currentPassword' => 'factory-password', 'permissions' => ['travelgo.*']])->assertRedirect();
        $this->assertDatabaseHas('travelgo_audit_events', ['action' => 'staff_permissions']);

        $this->actingAs($staff->fresh(), 'admin')->getJson('/admin/v1/places')->assertOk();
        $this->get('/admin/integrations')->assertForbidden();
        $this->patch($route, ['currentPassword' => 'factory-password', 'permissions' => []])->assertForbidden();
    }

    public function test_staff_creation_returns_totp_setup_without_revealing_it_in_the_directory(): void
    {
        $this->signInAsSuperadmin();
        $response = $this->post(route('admin.staff.store'), [
            'email' => 'new-editor@example.test', 'password' => 'temporary-password',
            'password_confirmation' => 'temporary-password', 'currentPassword' => 'factory-password',
            'is_superadmin' => true, 'permissions' => ['travelgo.*'],
        ]);

        $admin = AdminUser::query()->where('email', 'new-editor@example.test')->firstOrFail();
        $this->assertFalse($admin->is_superadmin);
        $this->assertSame([], $admin->permissions);
        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertSee($admin->totp_secret);
        $this->get(route('admin.staff.index'))->assertOk()->assertSee($admin->email)->assertDontSee($admin->totp_secret);
    }

    public function test_city_form_checks_region_and_handles_map_fields(): void
    {
        $this->signInAsSuperadmin();
        $city = $this->hierarchy();
        $this->postJson('/admin/v1/countries', ['code' => 'FR', 'name' => ['uk' => 'Франція', 'en' => 'France']])->assertCreated();
        $foreignRegion = $this->postJson('/admin/v1/regions', ['countryCode' => 'FR', 'name' => ['uk' => 'Регіон', 'en' => 'Region']])->assertCreated()->json('id');

        $this->get('/admin/travelgo/cities/'.$city)->assertOk()->assertSee('Радиус города')->assertSee('data-content-map', escape: false);
        $this->patchJson('/admin/v1/cities/'.$city, ['regionId' => $foreignRegion])->assertUnprocessable()->assertJsonValidationErrors('regionId');
        $this->getJson('/admin/v1/countries/UA/cities')->assertOk()->assertJsonPath('items.0.id', $city);
    }

    public function test_admin_geo_helpers_validate_and_forward_only_the_editor_query(): void
    {
        $staff = AdminUser::factory()->create(['permissions' => ['travelgo.cities.create']]);
        $this->actingAs($staff, 'admin');
        Http::preventStrayRequests();

        $this->getJson('/admin/v1/geo/search?q=Kyiv')->assertServiceUnavailable();
        config(['services.travelgo.geocoder_url' => 'https://geo.example.test/search', 'services.travelgo.geonames_username' => 'test']);
        Http::fake([
            'https://geo.example.test/search*' => Http::response([['display_name' => 'Kyiv', 'lat' => '50.45', 'lon' => '30.52']]),
            'https://secure.geonames.org/timezoneJSON*' => Http::response(['timezoneId' => 'Europe/Kyiv']),
        ]);

        $this->getJson('/admin/v1/geo/search?q=Kyiv')->assertOk()->assertJsonPath('items.0.latitude', 50.45);
        $this->getJson('/admin/v1/geo/timezone?lat=200&lng=30.52')->assertUnprocessable();
        $this->getJson('/admin/v1/geo/timezone?lat=50.45&lng=30.52')->assertOk()->assertJsonPath('timeZone', 'Europe/Kyiv');
        Http::assertSentCount(2);
    }

    public function test_configured_walking_router_supplies_line_distance_and_time(): void
    {
        $this->signInAsSuperadmin();
        $city = $this->hierarchy();
        $first = $this->place($city, 'Музей', 50.45, 30.52);
        $second = $this->place($city, 'Парк', 50.46, 30.53);
        $route = $this->postJson('/admin/v1/routes', ['cityId' => $city])->assertCreated()->json('id');
        config(['services.travelgo.osrm_url' => 'https://routes.example.test']);
        Http::preventStrayRequests();
        Http::fake(['https://routes.example.test/route/v1/foot/*' => Http::response(['routes' => [[
            'geometry' => ['type' => 'LineString', 'coordinates' => [[30.52, 50.45], [30.55, 50.45], [30.53, 50.46]]],
            'distance' => 2100, 'duration' => 1800,
        ]]])]);

        $this->putJson('/admin/v1/routes/'.$route.'/stops', [['placeId' => $first], ['placeId' => $second]])
            ->assertOk()->assertJsonPath('pathSource', 'routing')->assertJsonPath('distanceKm', 2.1)
            ->assertJsonPath('durationMinutes', 70)->assertJsonPath('path.coordinates.1', [30.55, 50.45]);
        $this->patchJson('/admin/v1/routes/'.$route, ['title' => ['en' => 'New title']])
            ->assertOk()->assertJsonPath('pathSource', 'routing');
        Http::assertSentCount(1);
    }

    private function signInAsSuperadmin(): void
    {
        $this->actingAs(AdminUser::factory()->superadmin()->create(), 'admin');
    }

    private function hierarchy(): string
    {
        $this->postJson('/admin/v1/countries', ['code' => 'UA', 'name' => ['uk' => 'Україна', 'en' => 'Ukraine']])->assertCreated();

        return $this->postJson('/admin/v1/cities', [
            'countryCode' => 'UA', 'name' => ['uk' => 'Київ', 'en' => 'Kyiv'], 'latitude' => 50.45, 'longitude' => 30.52,
            'timeZone' => 'Europe/Kyiv', 'coverImage' => 'https://example.test/kyiv.webp',
        ])->assertCreated()->json('id');
    }

    private function place(string $city, string $name, float $latitude, float $longitude, ?array $images = null): string
    {
        return $this->postJson('/admin/v1/places', [
            'cityId' => $city, 'name' => ['uk' => $name, 'en' => $name], 'category' => 'museum',
            'latitude' => $latitude, 'longitude' => $longitude, 'shortDescription' => ['uk' => 'Опис', 'en' => 'Description'],
            'images' => $images ?? [['url' => 'https://example.test/place.webp', 'credit' => 'TravelGo']],
        ])->assertCreated()->json('id');
    }
}
