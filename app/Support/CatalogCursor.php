<?php

namespace App\Support;

use App\Exceptions\MobileApiException;
use Illuminate\Support\Str;
use JsonException;

class CatalogCursor
{
    public static function encode(string $scope, array $ranks, string $id): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([$scope, $ranks, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.self::signature($payload);
    }

    /** @return array{array<int, int>, string}|null */
    public static function decode(?string $cursor, string $scope, int $rankCount): ?array
    {
        if ($cursor === null) {
            return null;
        }
        [$payload, $signature] = array_pad(explode('.', $cursor, 2), 2, '');
        if (hash_equals(self::signature($payload), $signature)) {
            $json = base64_decode(strtr($payload, '-_', '+/'), true);
            try {
                $decoded = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded) && count($decoded) === 3 && $decoded[0] === $scope
                    && is_array($decoded[1]) && array_is_list($decoded[1]) && count($decoded[1]) === $rankCount
                    && count(array_filter($decoded[1], is_int(...))) === $rankCount
                    && is_string($decoded[2]) && Str::isUuid($decoded[2])
                ) {
                    return [$decoded[1], $decoded[2]];
                }
            } catch (JsonException) {
            }
        }

        throw new MobileApiException('INVALID_CURSOR', 400, 'The cursor does not match this request.', 'cursor');
    }

    private static function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
