<?php

namespace App\Services;

use App\Exceptions\MobileApiException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AvatarImages
{
    private const array SIZES = [256, 512];

    public static function url(string $user, string $avatar): string
    {
        return rtrim(config('app.url'), '/').'/v1/avatars/'.$user.'/'.$avatar.'/512.webp';
    }

    public static function path(string $user, string $avatar, int $size): string
    {
        return 'avatars/'.$user.'/'.$avatar.'/'.$size.'.webp';
    }

    public function store(UploadedFile $file, string $user, string $avatar): void
    {
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
            || ($file->isValid() && $file->getSize() > 5 * 1024 * 1024)) {
            throw new MobileApiException('FILE_TOO_LARGE', 413, 'Avatar size must not exceed 5 MB.', 'file');
        }

        $info = $file->isValid() ? @getimagesize($file->getPathname()) : false;
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16_000_000) {
            throw $this->invalidImage();
        }

        $contents = file_get_contents($file->getPathname());
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        if (! $source) {
            throw $this->invalidImage();
        }

        $metadata = $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')
            ? @exif_read_data($file->getPathname(), 'IFD0', true)
            : [];
        $orientation = (int) ($metadata['IFD0']['Orientation'] ?? 1);
        $side = min($info[0], $info[1]);
        $x = intdiv($info[0] - $side, 2);
        $y = intdiv($info[1] - $side, 2);

        try {
            foreach (self::SIZES as $size) {
                $square = imagecreatetruecolor($size, $size);
                if (! $square) {
                    throw new RuntimeException('Could not allocate avatar image.');
                }

                imagealphablending($square, false);
                imagesavealpha($square, true);
                imagecopyresampled($square, $source, 0, 0, $x, $y, $size, $size, $side, $side);
                $square = $this->orient($square, $orientation);

                ob_start();
                try {
                    $encoded = imagewebp($square, null, 85);
                    $webp = ob_get_contents();
                } finally {
                    ob_end_clean();
                }

                if (! $encoded || ! is_string($webp) || $webp === ''
                    || ! Storage::disk('local')->put(self::path($user, $avatar, $size), $webp)) {
                    throw new RuntimeException('Could not store the avatar.');
                }
            }
        } catch (Throwable $error) {
            $this->delete($user, $avatar);

            throw $error;
        }
    }

    public function delete(string $user, string $avatar): bool
    {
        return Storage::disk('local')->deleteDirectory('avatars/'.$user.'/'.$avatar);
    }

    private function orient(GdImage $square, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($square, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($square, IMG_FLIP_VERTICAL);
        }

        $degrees = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };

        if ($degrees === 0) {
            return $square;
        }

        $rotated = imagerotate($square, $degrees, 0);
        if (! $rotated) {
            throw new RuntimeException('Could not rotate the avatar.');
        }
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function invalidImage(): MobileApiException
    {
        return new MobileApiException('INVALID_IMAGE', 422, 'Upload a valid JPEG, PNG or WebP image.', 'file');
    }
}
