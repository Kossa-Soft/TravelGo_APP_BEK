<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\MobileApiException;
use App\Http\Controllers\Controller;
use App\Services\MobileAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SensitiveParameter;

class AuthController extends Controller
{
    public function __construct(private readonly MobileAuth $auth) {}

    public function startRegistration(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'password' => ['required', 'string']]);
        $phone = $this->phone($data['phone']);
        $this->checkPassword($data['password'], 'password');

        return $this->json($this->auth->startRegistration($phone, $data['password'], $this->language($request), (string) $request->ip()));
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $data = $this->verificationFields($request);

        return $this->json($this->auth->verify($data['verificationId'], $data['code'], 'register'), 201);
    }

    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['verificationId' => ['required', 'uuid']]);

        return $this->json($this->auth->resend($data['verificationId'], (string) $request->ip()));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string', 'regex:/\A[^\x00]{1,72}\z/'],
        ]);

        return $this->json($this->auth->login($this->phone($data['phone']), $data['password'], (string) $request->ip()));
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refreshToken' => ['required', 'string', 'max:128']]);

        return $this->json($this->auth->refresh($data['refreshToken']));
    }

    public function logout(Request $request): Response
    {
        $data = $request->validate(['refreshToken' => ['required', 'string', 'max:128']]);
        $this->auth->logout($data['refreshToken']);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function startRecovery(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string']]);

        return $this->json($this->auth->startRecovery($this->phone($data['phone']), $this->language($request), (string) $request->ip()));
    }

    public function verifyRecovery(Request $request): JsonResponse
    {
        $data = $this->verificationFields($request);

        return $this->json($this->auth->verify($data['verificationId'], $data['code'], 'reset'));
    }

    public function resetPassword(Request $request): Response
    {
        $data = $request->validate([
            'resetToken' => ['required', 'string', 'max:128'],
            'newPassword' => ['required', 'string'],
        ]);
        $this->checkPassword($data['newPassword'], 'newPassword');
        $this->auth->resetPassword($data['resetToken'], $data['newPassword']);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->json($this->auth->me($request->bearerToken()));
    }

    private function verificationFields(Request $request): array
    {
        return $request->validate([
            'verificationId' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{4}\z/'],
        ]);
    }

    private function phone(string $phone): string
    {
        if (! preg_match('/\A\+[1-9][0-9]{6,14}\z/', $phone)) {
            throw new MobileApiException('INVALID_PHONE', 400, 'Enter a valid phone number in E.164 format.', 'phone');
        }

        return $phone;
    }

    private function checkPassword(#[SensitiveParameter] string $password, string $field): void
    {
        if (mb_strlen($password) < 8 || ! preg_match('/\A[^\x00]{1,72}\z/', $password)) {
            throw new MobileApiException('WEAK_PASSWORD', 422, 'Use at least 8 characters and at most 72 bytes.', $field);
        }
    }

    private function language(Request $request): string
    {
        return $request->getPreferredLanguage(['uk', 'en', 'de', 'es', 'fr']) ?? 'uk';
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }
}
