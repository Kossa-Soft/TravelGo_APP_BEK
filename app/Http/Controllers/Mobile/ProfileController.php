<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\MobileApiException;
use App\Http\Controllers\Controller;
use App\Models\MobileSession;
use App\Models\User;
use App\Services\AvatarImages;
use App\Services\MobileAuth;
use App\Services\MobileProfile;
use App\Support\MobileInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function __construct(private readonly MobileAuth $auth, private readonly MobileProfile $profile) {}

    public function update(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $data = $request->validate([
            'firstName' => ['sometimes', 'nullable'],
            'lastName' => ['sometimes', 'nullable'],
            'middleName' => ['sometimes', 'nullable'],
            'birthDate' => ['sometimes', 'nullable'],
            'language' => ['sometimes', 'required', 'in:uk,en,de,es,fr'],
        ]);

        return $this->json($this->profile->update($session, $data));
    }

    public function verifyPassword(Request $request): Response
    {
        $session = $this->session($request);
        $data = $request->validate(['password' => MobileInput::PASSWORD]);
        $this->profile->verifyPassword($session, $data['password'], (string) $request->ip());

        return $this->noContent();
    }

    public function changePassword(Request $request): Response
    {
        $session = $this->session($request);
        $data = $request->validate([
            'currentPassword' => MobileInput::PASSWORD,
            'newPassword' => ['required', 'string'],
        ]);
        MobileInput::checkPassword($data['newPassword'], 'newPassword');
        $this->profile->changePassword($session, $data['currentPassword'], $data['newPassword'], (string) $request->ip());

        return $this->noContent();
    }

    public function startPhoneChange(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $data = $request->validate(['password' => MobileInput::PASSWORD, 'newPhone' => ['required', 'string']]);
        $phone = MobileInput::phone($data['newPhone'], 'newPhone');

        return $this->json($this->auth->startPhoneChange($session, $phone, $data['password'], (string) $request->ip()));
    }

    public function verifyPhoneChange(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $data = MobileInput::verificationFields($request);

        return $this->json($this->auth->verify($data['verificationId'], $data['code'], 'change_phone', $session));
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw new MobileApiException('INVALID_IMAGE', 422, 'Upload a JPEG, PNG or WebP image.', 'file');
        }

        return $this->json(['avatarUrl' => $this->profile->replaceAvatar($session, $file)]);
    }

    public function deleteAvatar(Request $request): Response
    {
        $this->profile->deleteAvatar($this->session($request));

        return $this->noContent();
    }

    public function avatar(string $user, string $avatar, string $size): StreamedResponse
    {
        abort_unless(User::query()->where('public_id', $user)->where('avatar_id', $avatar)->exists(), 404);

        $path = AvatarImages::path($user, $avatar, (int) $size);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, 'avatar.webp', [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request): Response
    {
        $session = $this->session($request);
        $data = $request->validate(['password' => MobileInput::PASSWORD]);
        $this->profile->deleteAccount($session, $data['password'], (string) $request->ip());

        return $this->noContent();
    }

    private function session(Request $request): MobileSession
    {
        return $this->auth->authenticate($request->bearerToken());
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    private function noContent(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
