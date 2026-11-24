<?php

namespace App\Providers;

use App\Models\AdminUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('integrations.alphasms.view', fn (AdminUser $admin): bool => $admin->hasPermission('integrations.alphasms.view'));
        Gate::define('integrations.alphasms.update', fn (AdminUser $admin): bool => $admin->hasPermission('integrations.alphasms.update'));

        RateLimiter::for('admin-login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower(Str::limit($email, 255, '')) : '';

            return [
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.hash('sha256', $email)),
            ];
        });
    }
}
