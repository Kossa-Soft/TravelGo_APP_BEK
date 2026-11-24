<?php

namespace App\Services;

use App\Exceptions\MobileApiException;
use App\Jobs\SendVerificationSms;
use App\Models\MobileSession;
use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use SensitiveParameter;

class MobileAuth
{
    public function startRegistration(string $phone, #[SensitiveParameter] string $password, string $language, string $ip): array
    {
        $this->limitSms($phone, $ip);

        if (User::query()->where('phone', $phone)->exists()) {
            throw $this->phoneAlreadyRegistered();
        }

        return $this->startChallenge($phone, 'register', $language, Hash::make($password));
    }

    public function startRecovery(string $phone, string $language, string $ip): array
    {
        $this->limitSms($phone, $ip);
        $userId = User::query()->where('phone', $phone)->value('id');

        return $this->startChallenge($phone, 'reset', $language, userId: $userId);
    }

    private function startChallenge(string $phone, string $purpose, string $language, ?string $passwordHash = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($phone, $purpose, $language, $passwordHash, $userId): array {
            $verification = new PhoneVerification;
            $verification->id = (string) Str::uuid();
            $verification->purpose = $purpose;
            $verification->phone = $phone;
            $verification->language = $language;
            $verification->pending_password_hash = $passwordHash;
            $verification->user_id = $userId;

            $this->issueCode($verification);

            return [
                'verificationId' => $verification->id,
                'expiresIn' => config('mobile_auth.code_seconds'),
                'resendIn' => config('mobile_auth.resend_seconds'),
                'codeLength' => 4,
            ];
        });
    }

    public function resend(string $id, string $ip): array
    {
        $verification = $this->challenge($id);
        $this->checkResendTime($verification);
        $this->limitSms($verification->phone, $ip);

        return DB::transaction(function () use ($id): array {
            $verification = $this->challenge($id, lock: true);
            $this->checkResendTime($verification);
            $this->issueCode($verification);

            return [
                'expiresIn' => config('mobile_auth.code_seconds'),
                'resendIn' => config('mobile_auth.resend_seconds'),
            ];
        });
    }

    private function checkResendTime(PhoneVerification $verification): void
    {
        $retryAfter = $verification->resend_at->timestamp - now()->timestamp;

        if ($retryAfter > 0) {
            throw new MobileApiException('RESEND_TOO_EARLY', 429, 'Wait before requesting another code.', details: ['retryAfter' => $retryAfter]);
        }
    }

    private function issueCode(PhoneVerification $verification): void
    {
        if (! config('mobile_auth.send_sms') && ! $this->isTestEnvironment()) {
            throw new MobileApiException('SMS_UNAVAILABLE', 503, 'Verification SMS is currently unavailable.');
        }

        $demoCode = $this->demoCode($verification->phone);
        do {
            $code = $demoCode ?? str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $hash = self::codeHash($verification->id, $code);
        } while ($demoCode === null && $hash === $verification->code_hash);

        $verification->code_hash = $hash;
        $verification->attempts = 0;
        $verification->expires_at = now()->addSeconds(config('mobile_auth.code_seconds'));
        $verification->resend_at = now()->addSeconds(config('mobile_auth.resend_seconds'));
        $verification->delivery_id = (string) Str::uuid();
        $verification->sms_claimed_at = null;
        $verification->save();

        if ($demoCode === null && config('mobile_auth.send_sms')) {
            SendVerificationSms::dispatch($verification->id, $verification->delivery_id, $code)
                ->onConnection('database')->onQueue('mobile-sms');
        }
    }

    private function demoCode(string $phone): ?string
    {
        $code = config('mobile_auth.demo_code');

        return $this->isTestEnvironment()
            && $phone === config('mobile_auth.demo_phone')
            && is_string($code)
            && preg_match('/\A[0-9]{4}\z/', $code)
            ? $code
            : null;
    }

    private function isTestEnvironment(): bool
    {
        return app()->environment('local', 'testing', 'staging');
    }

    private function challenge(string $id, ?string $purpose = null, bool $lock = false): PhoneVerification
    {
        $verification = PhoneVerification::query()->whereKey($id)
            ->whereNull('consumed_at')->where('created_at', '>', now()->subDay())
            ->when($purpose !== null, fn ($query) => $query->where('purpose', $purpose))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $verification ?? throw new MobileApiException('VERIFICATION_NOT_FOUND', 404, 'Verification not found.');
    }

    public function verify(string $id, #[SensitiveParameter] string $code, string $purpose): array
    {
        try {
            $result = DB::transaction(function () use ($id, $code, $purpose): array|MobileApiException {
                $verification = $this->challenge($id, $purpose, lock: true);

                if ($verification->expires_at->timestamp <= now()->timestamp) {
                    throw new MobileApiException('CODE_EXPIRED', 422, 'Verification code expired.', 'code');
                }

                $maxAttempts = config('mobile_auth.code_attempts');
                if ($verification->attempts >= $maxAttempts) {
                    throw new MobileApiException('TOO_MANY_ATTEMPTS', 429, 'Too many attempts for this code.', 'code');
                }

                if (! hash_equals($verification->code_hash, self::codeHash($id, $code))
                    || ($purpose === 'reset' && $verification->user_id === null)
                    || ($verification->sms_claimed_at === null && $this->demoCode($verification->phone) !== $code)) {
                    $verification->attempts++;
                    $verification->save();

                    return new MobileApiException('WRONG_CODE', 422, 'Incorrect verification code.', 'code', [
                        'attemptsLeft' => $maxAttempts - $verification->attempts,
                    ]);
                }

                $verification->consumed_at = now();

                if ($purpose === 'reset') {
                    $token = $this->newToken();
                    $verification->reset_token_hash = hash('sha256', $token);
                    $verification->reset_expires_at = now()->addSeconds(config('mobile_auth.reset_seconds'));
                    $verification->save();

                    return ['resetToken' => $token, 'expiresIn' => config('mobile_auth.reset_seconds')];
                }

                if (User::query()->where('phone', $verification->phone)->exists()) {
                    throw $this->phoneAlreadyRegistered();
                }

                $user = new User;
                $user->phone = $verification->phone;
                $user->language = $verification->language;
                $user->password = $verification->pending_password_hash;
                $user->save();

                $verification->pending_password_hash = null;
                $verification->save();

                return $this->issueSession($user);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($purpose === 'register' && User::query()->where('phone', PhoneVerification::query()->whereKey($id)->value('phone'))->exists()) {
                throw $this->phoneAlreadyRegistered();
            }

            throw $exception;
        }

        if ($result instanceof MobileApiException) {
            throw $result;
        }

        return $result;
    }

    public function login(string $phone, #[SensitiveParameter] string $password, string $ip): array
    {
        $this->limit('login:ip', $ip, config('mobile_auth.login_per_ip'), 900);
        $this->limit('login:phone', $phone, config('mobile_auth.login_per_phone'), 900);

        return (new Timebox)->call(function (Timebox $timebox) use ($phone, $password): array {
            $result = DB::transaction(function () use ($phone, $password): ?array {
                $user = User::query()->where('phone', $phone)->lockForUpdate()->first();

                if ($user === null || ! Hash::check($password, $user->password)) {
                    return null;
                }

                if (Hash::needsRehash($user->password)) {
                    $user->password = $password;
                    $user->save();
                }

                return $this->issueSession($user);
            });

            if ($result === null) {
                throw new MobileApiException('INVALID_CREDENTIALS', 401, 'Invalid phone number or password.');
            }

            $timebox->returnEarly();

            return $result;
        }, 300_000);
    }

    public function resetPassword(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        $tokenHash = hash('sha256', $token);

        DB::transaction(function () use ($tokenHash, $password): void {
            $userId = PhoneVerification::query()->where('reset_token_hash', $tokenHash)
                ->where('reset_expires_at', '>', now())->value('user_id');
            $user = $userId === null ? null : User::query()->lockForUpdate()->find($userId);

            if ($user === null || ! PhoneVerification::query()->where('user_id', $user->id)
                ->where('reset_token_hash', $tokenHash)->where('reset_expires_at', '>', now())->exists()) {
                throw new MobileApiException('RESET_TOKEN_INVALID', 401, 'Password reset token is invalid or expired.');
            }

            $user->password = $password;
            $user->save();

            PhoneVerification::query()->where('user_id', $user->id)->where('purpose', 'reset')->update([
                'consumed_at' => now(),
                'reset_token_hash' => null,
                'reset_expires_at' => null,
            ]);
            MobileSession::query()->where('user_id', $user->id)->delete();
        });
    }

    public function refresh(#[SensitiveParameter] string $token): array
    {
        return DB::transaction(function () use ($token): array {
            $session = MobileSession::query()->where('refresh_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($session === null) {
                throw new MobileApiException('UNAUTHORIZED', 401, 'Invalid refresh token.');
            }

            if ($session->refresh_expires_at->timestamp <= now()->timestamp) {
                throw new MobileApiException('TOKEN_EXPIRED', 401, 'Refresh token expired.');
            }

            return $this->rotateTokens($session);
        });
    }

    public function logout(#[SensitiveParameter] string $token): void
    {
        MobileSession::query()->where('refresh_token_hash', hash('sha256', $token))->delete();
    }

    public function me(#[SensitiveParameter] ?string $token): array
    {
        $session = $token !== null && preg_match('/\A[a-f0-9]{64}\z/', $token)
            ? MobileSession::query()->with('user')->where('access_token_hash', hash('sha256', $token))->first()
            : null;

        if ($session === null || $session->user === null) {
            throw new MobileApiException('UNAUTHORIZED', 401, 'A valid access token is required.');
        }

        if ($session->access_expires_at->timestamp <= now()->timestamp) {
            throw new MobileApiException('TOKEN_EXPIRED', 401, 'Access token expired.');
        }

        return $this->profile($session->user);
    }

    private function issueSession(User $user): array
    {
        $session = new MobileSession;
        $session->user_id = $user->id;

        return $this->rotateTokens($session) + ['user' => $this->profile($user)];
    }

    private function rotateTokens(MobileSession $session): array
    {
        $accessToken = $this->newToken();
        $refreshToken = $this->newToken();
        $session->access_token_hash = hash('sha256', $accessToken);
        $session->refresh_token_hash = hash('sha256', $refreshToken);
        $session->access_expires_at = now()->addMinutes(config('mobile_auth.access_minutes'));
        $session->refresh_expires_at = now()->addDays(config('mobile_auth.refresh_days'));
        $session->save();

        return ['accessToken' => $accessToken, 'refreshToken' => $refreshToken];
    }

    private function profile(User $user): array
    {
        return [
            'id' => $user->public_id,
            'phone' => $user->phone,
            'firstName' => null,
            'lastName' => null,
            'middleName' => null,
            'birthDate' => null,
            'avatarUrl' => null,
            'language' => $user->language,
            'plan' => 'free',
            'planUntil' => null,
            'createdAt' => $user->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function codeHash(string $id, #[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $id.':'.$code, config('app.key'));
    }

    private function limitSms(string $phone, string $ip): void
    {
        $this->limit('sms:ip', $ip, config('mobile_auth.sms_per_ip_hour'), 3600);
        $this->limit('sms:phone', $phone, config('mobile_auth.sms_per_phone_hour'), 3600);
    }

    private function limit(string $type, string $identity, int $maximum, int $seconds): void
    {
        $key = 'mobile-auth:'.$type.':'.hash_hmac('sha256', $identity, config('app.key'));
        if (RateLimiter::increment($key, $seconds) > $maximum) {
            throw new MobileApiException('RATE_LIMITED', 429, 'Too many requests.', details: [
                'retryAfter' => max(1, RateLimiter::availableIn($key)),
            ]);
        }
    }

    private function phoneAlreadyRegistered(): MobileApiException
    {
        return new MobileApiException('PHONE_ALREADY_REGISTERED', 409, 'Phone number is already registered.', 'phone');
    }
}
