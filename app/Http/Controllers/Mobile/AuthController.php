<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\MobileAuth;
use App\Support\MobileInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly MobileAuth $auth) {}

    public function startRegistration(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'password' => ['required', 'string']]);
        $phone = MobileInput::phone($data['phone']);
        MobileInput::checkPassword($data['password'], 'password');

        return $this->json($this->auth->startRegistration($phone, $data['password'], $this->language($request), (string) $request->ip()));
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $data = MobileInput::verificationFields($request);

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
            'password' => MobileInput::PASSWORD,
        ]);

        return $this->json($this->auth->login(MobileInput::phone($data['phone']), $data['password'], (string) $request->ip()));
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

        return $this->json($this->auth->startRecovery(MobileInput::phone($data['phone']), $this->language($request), (string) $request->ip()));
    }

    public function verifyRecovery(Request $request): JsonResponse
    {
        $data = MobileInput::verificationFields($request);

        return $this->json($this->auth->verify($data['verificationId'], $data['code'], 'reset'));
    }

    public function resetPassword(Request $request): Response
    {
        $data = $request->validate([
            'resetToken' => ['required', 'string', 'max:128'],
            'newPassword' => ['required', 'string'],
        ]);
        MobileInput::checkPassword($data['newPassword'], 'newPassword');
        $this->auth->resetPassword($data['resetToken'], $data['newPassword']);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->json($this->auth->me($request->bearerToken()));
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
