<?php

namespace App\Services;

use App\Models\TravelGoContent;
use App\Support\TravelGoType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class TravelGoMedia
{
    private const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly TravelGoImages $images) {}

    public function mirror(string $url, TravelGoType $type): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ! in_array($parts['host'] ?? '', ['upload.wikimedia.org', 'thumb.wikimedia.org'], true)
            || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || ! str_starts_with($parts['path'] ?? '', '/wikipedia/')
        ) {
            throw new RuntimeException('The catalog contains an unapproved image source.');
        }

        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, $url)->toString();
        $disk = Storage::disk('local');
        if (! $disk->exists(TravelGoImages::path($type->value, $id, 400))
            || ! $disk->exists(TravelGoImages::path($type->value, $id, 1200))
        ) {
            $this->download($url, $type, $id);
        }

        return route('admin.travelgo.media', [$type, $id, 1200]);
    }

    private function download(string $url, TravelGoType $type, string $id): void
    {
        $response = Http::withUserAgent('TravelGoCatalog/1.0 (+'.rtrim((string) config('app.url'), '/').')')
            ->withOptions(['stream' => true])->withoutRedirecting()->connectTimeout(3)->timeout(14)->get($url);
        $stream = $response->toPsrResponse()->getBody();
        $file = tmpfile();
        try {
            if (! $file || ! $response->successful() || (int) $response->header('Content-Length') > self::MAX_BYTES) {
                throw new RuntimeException('Catalog image download failed (HTTP '.$response->status().').');
            }
            $size = 0;
            while (! $stream->eof()) {
                $chunk = $stream->read(32768);
                $size += strlen($chunk);
                if ($chunk === '' || $size > self::MAX_BYTES || fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Catalog image size exceeded the limit or download was interrupted.');
                }
            }
            $this->images->store(new UploadedFile(stream_get_meta_data($file)['uri'], 'catalog-image', test: true), $type->value, $id);
        } finally {
            $stream->close();
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    public function mobileUrl(TravelGoContent $owner, ?string $url, int $size): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $local = $this->localImage($url);
        if ($local === null) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').route('mobile.catalog.media', [$owner->id, $local['type'], $local['id'], $size], absolute: false);
    }

    public function owns(TravelGoContent $owner, string $type, string $image): bool
    {
        $urls = [$owner->data['coverImage'] ?? null, ...array_column($owner->data['images'] ?? [], 'url')];
        foreach (array_filter($urls, is_string(...)) as $url) {
            if ($this->localImage($url) === ['type' => $type, 'id' => $image]) {
                return true;
            }
        }

        return false;
    }

    /** @return array{type: string, id: string}|null */
    private function localImage(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! preg_match('~\A/admin/travelgo/media/(cities|places|routes)/([a-f0-9-]{36})/(400|1200)\.webp\z~', $path, $parts)) {
            return null;
        }

        return ['type' => $parts[1], 'id' => $parts[2]];
    }
}
