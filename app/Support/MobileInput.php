<?php

namespace App\Support;

use App\Exceptions\MobileApiException;
use Illuminate\Http\Request;
use SensitiveParameter;

class MobileInput
{
    public const array PASSWORD = ['required', 'string', 'regex:/\A[^\x00]{1,72}\z/'];

    public static function phone(string $phone, string $field = 'phone'): string
    {
        if (! preg_match('/\A\+[1-9][0-9]{6,14}\z/', $phone)) {
            throw new MobileApiException('INVALID_PHONE', 400, 'Enter a valid phone number in E.164 format.', $field);
        }

        return $phone;
    }

    public static function checkPassword(#[SensitiveParameter] string $password, string $field): void
    {
        if (mb_strlen($password) < 8 || ! preg_match('/\A[^\x00]{1,72}\z/', $password)) {
            throw new MobileApiException('WEAK_PASSWORD', 422, 'Use at least 8 characters and at most 72 bytes.', $field);
        }
    }

    public static function verificationFields(Request $request): array
    {
        return $request->validate([
            'verificationId' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{4}\z/'],
        ]);
    }
}
