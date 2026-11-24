<?php

namespace App\Http\Controllers;

use App\Exceptions\MobileApiException;
use App\Services\MobileAuth;
use App\Services\MobileProfile;
use App\Support\MobileInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountDeletionController extends Controller
{
    public function show(): View
    {
        return view('account.delete');
    }

    public function destroy(Request $request, MobileAuth $auth, MobileProfile $profile): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => MobileInput::PASSWORD,
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Підтвердьте, що хочете видалити акаунт.',
        ]);
        $ip = (string) $request->ip();

        try {
            $tokens = $auth->login(MobileInput::phone($data['phone']), $data['password'], $ip);

            try {
                $profile->deleteAccount($auth->authenticate($tokens['accessToken']), $data['password'], $ip);
            } finally {
                $auth->logout($tokens['refreshToken']);
            }
        } catch (MobileApiException $exception) {
            $message = match ($exception->errorCode) {
                'RATE_LIMITED' => 'Забагато спроб. Спробуйте пізніше.',
                'INVALID_PHONE' => 'Введіть номер телефону в міжнародному форматі, починаючи з +.',
                default => 'Перевірте номер телефону й пароль.',
            };

            throw ValidationException::withMessages(['phone' => $message]);
        }

        return to_route('account.delete')->with('account_deleted', true);
    }
}
