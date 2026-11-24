<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Support\AdminTotp;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateSuperadmin extends Command
{
    protected $signature = 'admin:create-superadmin {email}';

    protected $description = 'Create a superadministrator with a password and authenticator code';

    public function handle(AdminTotp $totp): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }

        if (AdminUser::query()->where('email', $email)->exists()) {
            $this->error('An administrator with this email already exists.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 14 characters, up to 72 bytes)', fallback: false);

        if (! is_string($password) || mb_strlen($password) < 14 || ! preg_match('/\A[^\x00]{1,72}\z/', $password)) {
            $this->error('Choose a password that meets the length requirements.');

            return self::FAILURE;
        }

        if ($this->secret('Confirm password', fallback: false) !== $password) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $secret = $totp->generateSecret();
        $admin = new AdminUser;
        $admin->email = $email;
        $admin->password = $password;
        $admin->totp_secret = $secret;
        $admin->is_superadmin = true;

        try {
            $admin->save();
        } catch (UniqueConstraintViolationException) {
            $this->error('An administrator with this email already exists.');

            return self::FAILURE;
        }

        $this->info('Superadministrator created. Add a time-based account in your authenticator app.');
        $this->line('Account: TravelGo / '.$email);
        $this->line('Setup key: '.$secret);
        $this->line('Use 6 digits and a 30 second time period.');

        return self::SUCCESS;
    }
}
