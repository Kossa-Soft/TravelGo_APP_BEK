<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Support\AdminTotp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('admin.login', ['requiresTotp' => $this->requiresTotp()]);
    }

    public function store(Request $request, AdminTotp $totp): RedirectResponse
    {
        $requiresTotp = $this->requiresTotp();
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'regex:/\A[^\x00]{1,72}\z/'],
            'code' => $requiresTotp ? ['required', 'string', 'regex:/\A[0-9]{6}\z/'] : ['exclude'],
        ]);

        $guard = Auth::guard('admin');
        $signedIn = $guard->attemptWhen([
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
        ], function (AdminUser $admin) use ($totp, $data, $requiresTotp): bool {
            if (! $requiresTotp) {
                return true;
            }

            $step = $totp->matchingStep($admin->totp_secret, $data['code'], now()->timestamp);

            if ($step === null) {
                return false;
            }

            return AdminUser::query()->whereKey($admin->getKey())
                ->where(fn ($query) => $query->whereNull('last_totp_step')->orWhere('last_totp_step', '<', $step))
                ->update(['last_totp_step' => $step]) === 1;
        });

        if (! $signedIn) {
            throw ValidationException::withMessages([
                'email' => $requiresTotp
                    ? 'Проверьте почту, пароль и код из приложения-аутентификатора.'
                    : 'Проверьте почту и пароль.',
            ]);
        }

        $request->session()->regenerate();

        return $guard->user()->hasPermission('integrations.alphasms.view')
            ? to_route('admin.integrations.show')
            : to_route('admin.travelgo.home');
    }

    private function requiresTotp(): bool
    {
        return ! app()->isLocal() || (bool) config('auth.admin_require_totp', true);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('admin.login');
    }
}
