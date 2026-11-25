<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TravelGoImages
{
    public function store(UploadedFile $file, string $type): string
    {
        $info = @getimagesize($file->getPathname());
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > 20_000_000
        ) {
            throw ValidationException::withMessages(['file' => 'Загрузите JPEG, PNG или WebP размером до 20 мегапикселей.']);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getPathname()) ?: '');
        if ($source === false) {
            throw ValidationException::withMessages(['file' => 'Изображение не удалось прочитать.']);
        }

        $id = (string) Str::uuid();
        $disk = Storage::disk('local');
        try {
            foreach ([400, 1200] as $size) {
                $factor = min(1, $size / max($info[0], $info[1]));
                $width = max(1, (int) round($info[0] * $factor));
                $height = max(1, (int) round($info[1] * $factor));
                $scaled = imagecreatetruecolor($width, $height);
                if ($scaled === false) {
                    throw new RuntimeException('Could not allocate content image.');
                }
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagecopyresampled($scaled, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);

                ob_start();
                try {
                    $ok = imagewebp($scaled, null, 85);
                    $contents = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (! $ok || ! $contents || ! $disk->put(self::path($type, $id, $size), $contents)) {
                    throw new RuntimeException('Could not store content image.');
                }
            }

            return $id;
        } catch (Throwable $error) {
            $this->remove($type, $id);

            throw $error;
        }
    }

    public static function path(string $type, string $id, int $size): string
    {
        return 'travelgo/'.$type.'/'.$id.'/'.$size.'.webp';
    }

    public function remove(string $type, string $id): void
    {
        Storage::disk('local')->deleteDirectory('travelgo/'.$type.'/'.$id);
    }
}
