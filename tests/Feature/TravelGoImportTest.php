<?php

namespace Tests\Feature;

use App\Models\TravelGoContent;
use App\Services\TravelGoMedia;
use App\Support\TravelGoType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TravelGoImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pinned_catalog_import_is_atomic_and_repeated_import_keeps_uuids_and_attribution(): void
    {
        Http::preventStrayRequests();
        $this->artisan('travelgo:import', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseEmpty('travelgo_contents');
        $this->artisan('travelgo:import', ['--without-media' => true])->assertSuccessful();
        $snapshot = TravelGoContent::query()->orderBy('id')->pluck('id')->all();
        $this->assertCount(276, $snapshot);
        $route = TravelGoContent::ofType(TravelGoType::Route)->where('slug', 'kyiv-golden-domes')->firstOrFail();
        $cover = TravelGoContent::ofType(TravelGoType::Place)->where('slug', 'kyiv-sophia')->firstOrFail()->data['images'][0];
        $this->assertSame($cover['url'], $route->data['coverImage']);
        $this->assertSame($cover['credit'], $route->data['coverCredit']);
        $this->assertSame('LineString', $route->data['path']['type']);

        $this->artisan('travelgo:import', ['--without-media' => true])->assertSuccessful();

        $this->assertSame($snapshot, TravelGoContent::query()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('travelgo_audit_events', 880);
        Http::assertNothingSent();
    }

    public function test_mirroring_writes_both_resolutions_and_reuses_the_existing_asset(): void
    {
        $disk = Storage::fake('local');
        $image = UploadedFile::fake()->image('source.png', 1350, 900);
        $url = 'https://thumb.wikimedia.org/wikipedia/commons/thumb/1/12/Test.png/1920px-Test.png';
        Http::preventStrayRequests();
        Http::fake([$url => Http::response(file_get_contents($image->getPathname()), 200, ['Content-Type' => 'image/png'])]);
        $media = app(TravelGoMedia::class);

        $mirrored = $media->mirror($url, TravelGoType::Place);

        $this->assertSame($mirrored, $media->mirror($url, TravelGoType::Place));
        Http::assertSentCount(1);
        $files = $disk->allFiles('travelgo/places');
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            [$width, $height] = getimagesize($disk->path($file));
            $this->assertSame((int) basename($file, '.webp'), $width);
            $this->assertSame((int) round($width * 2 / 3), $height);
        }
    }
}
