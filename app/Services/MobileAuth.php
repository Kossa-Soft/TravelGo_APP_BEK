<?php

namespace App\Services;

use App\Exceptions\MobileApiException;
use App\Jobs\SendPhoneChangedSms;
use App\Jobs\SendVerificationSms;
use App\Models\MobileSession;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
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

        return DB::transaction(function () use ($phone, $language): array {
            $userId = User::query()->where('phone', $phone)->whereNull('blocked_at')->lockForUpdate()->value('id');

            return $this->startChallenge($phone, 'reset', $language, userId: $userId);
        });
    }

    public function startPhoneChange(MobileSession $session, string $phone, #[SensitiveParameter] string $password, string $ip): array
    {
        $this->limitPasswordAttempts($session->user->phone, $ip);

        return DB::transaction(function () use ($session, $phone, $password, $ip): array {
            $user = $this->lockUser($session);
            $this->requirePassword($user, $password, 'password');

            if ($phone === $user->phone) {
                throw new MobileApiException('SAME_PHONE', 422, 'Enter a different phone number.', 'newPhone');
            }

            if (User::query()->where('phone', $phone)->exists()) {
                throw $this->phoneAlreadyRegistered('newPhone');
            }

            $this->limitSms($phone, $ip);

            return $this->startChallenge($phone, 'change_phone', $user->language, userId: $user->id);
        });
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
        $verification->sms_accepted_at = null;
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

    private function challenge(string $id, ?string $purpose = null, bool $lock = false, ?int $owner = null): PhoneVerification
    {
        $verification = PhoneVerification::query()->whereKey($id)
            ->whereNull('consumed_at')->where('created_at', '>', now()->subDay())
            ->when($purpose !== null, fn ($query) => $query->where('purpose', $purpose))
            ->when($owner !== null, fn ($query) => $query->where('user_id', $owner))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $verification ?? throw new MobileApiException('VERIFICATION_NOT_FOUND', 404, 'Verification not found.');
    }

    public function verify(string $id, #[SensitiveParameter] string $code, string $purpose, ?MobileSession $session = null): array
    {
        try {
            $result = DB::transaction(function () use ($id, $code, $purpose, $session): array|MobileApiException {
                $owner = $purpose === 'change_phone'
                    ? $this->lockUser($session ?? throw new MobileApiException('UNAUTHORIZED', 401, 'A valid access token is required.'))
                    : null;
                $verification = $this->challenge($id, $purpose, lock: true, owner: $owner?->id);

                if ($verification->expires_at->timestamp <= now()->timestamp) {
                    throw new MobileApiException('CODE_EXPIRED', 422, 'Verification code expired.', 'code');
                }

                $maxAttempts = config('mobile_auth.code_attempts');
                if ($verification->attempts >= $maxAttempts) {
                    throw new MobileApiException('TOO_MANY_ATTEMPTS', 429, 'Too many attempts for this code.', 'code');
                }

                if (! hash_equals($verification->code_hash, self::codeHash($id, $code))
                    || ($purpose === 'reset' && $verification->user_id === null)
                    || ($verification->sms_accepted_at === null && $this->demoCode($verification->phone) !== $code)) {
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

                if ($owner !== null) {
                    return $this->completePhoneChange($owner, $verification->phone, $session);
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
            if (in_array($purpose, ['register', 'change_phone'], true) && User::query()->where('phone', PhoneVerification::query()->whereKey($id)->value('phone'))->exists()) {
                throw $this->phoneAlreadyRegistered($purpose === 'change_phone' ? 'newPhone' : 'phone');
            }

            throw $exception;
        }

        if ($result instanceof MobileApiException) {
            throw $result;
        }

        return $result;
    }

    private function completePhoneChange(User $user, string $phone, MobileSession $session): array
    {
        if (User::query()->where('phone', $phone)->exists()) {
            throw $this->phoneAlreadyRegistered('newPhone');
        }

        $previousPhone = $user->phone;
        $user->phone = $phone;
        $user->save();

        $this->clearAccountChallenges($user);
        $this->revokeOtherSessions($session);
        $user->notify(new AccountSecurityNotification('phone_changed'));

        if ($previousPhone !== null && config('mobile_auth.send_sms')) {
            SendPhoneChangedSms::dispatch($previousPhone, $user->language)
                ->onConnection('database')->onQueue('mobile-sms');
        }

        return $this->profile($user);
    }

    public function requirePassword(User $user, #[SensitiveParameter] string $password, string $field): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new MobileApiException('WRONG_PASSWORD', 401, 'The current password is incorrect.', $field);
        }
    }

    public function limitPasswordAttempts(string $phone, string $ip): void
    {
        $this->limit('login:ip', $ip, config('mobile_auth.login_per_ip'), 900);
        $this->limit('login:phone', $phone, config('mobile_auth.login_per_phone'), 900);
    }

    public function clearAccountChallenges(User $user): void
    {
        PhoneVerification::query()->where('user_id', $user->id)->delete();
    }

    public function revokeOtherSessions(MobileSession $session): void
    {
        MobileSession::query()->where('user_id', $session->user_id)->whereKeyNot($session->id)->delete();
    }

    public function login(string $phone, #[SensitiveParameter] string $password, string $ip): array
    {
        $this->limitPasswordAttempts($phone, $ip);

        return (new Timebox)->call(function (Timebox $timebox) use ($phone, $password): array {
            $result = DB::transaction(function () use ($phone, $password): ?array {
                $user = User::query()->where('phone', $phone)->lockForUpdate()->first();

                if ($user === null || ! Hash::check($password, $user->password)) {
                    return null;
                }

                if ($user->isBlocked()) {
                    throw new MobileApiException('ACCOUNT_BLOCKED', 403, 'This account is blocked.');
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

            if ($user === null || $user->isBlocked() || ! PhoneVerification::query()->where('user_id', $user->id)
                ->where('reset_token_hash', $tokenHash)->where('reset_expires_at', '>', now())->exists()) {
                throw new MobileApiException('RESET_TOKEN_INVALID', 401, 'Password reset token is invalid or expired.');
            }

            $user->password = $password;
            $user->save();

            $this->clearAccountChallenges($user);
            MobileSession::query()->where('user_id', $user->id)->delete();
        });
    }

    public function refresh(#[SensitiveParameter] string $token): array
    {
        return DB::transaction(function () use ($token): array {
            $hash = hash('sha256', $token);
            $userId = MobileSession::query()->where('refresh_token_hash', $hash)->value('user_id');
            $user = $userId === null ? null : User::query()->lockForUpdate()->find($userId);
            $session = $user?->isBlocked() === false
                ? MobileSession::query()->where('refresh_token_hash', $hash)->where('user_id', $user->id)->lockForUpdate()->first()
                : null;
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
        return $this->profile($this->authenticate($token)->user);
    }

    public function authenticate(#[SensitiveParameter] ?string $token): MobileSession
    {
        $session = $token !== null && preg_match('/\A[a-f0-9]{64}\z/', $token)
            ? MobileSession::query()->with('user')->where('access_token_hash', hash('sha256', $token))->first()
            : null;

        if ($session === null || $session->user === null || $session->user->isBlocked()) {
            throw new MobileApiException('UNAUTHORIZED', 401, 'A valid access token is required.');
        }

        if ($session->access_expires_at->timestamp <= now()->timestamp) {
            throw new MobileApiException('TOKEN_EXPIRED', 401, 'Access token expired.');
        }

        return $session;
    }

    public function lockUser(MobileSession $session): User
    {
        $user = User::query()->lockForUpdate()->find($session->user_id);

        if ($user === null || $user->isBlocked() || ! MobileSession::query()->whereKey($session->id)
            ->where('access_token_hash', $session->access_token_hash)
            ->where('access_expires_at', '>', now())->exists()) {
            throw new MobileApiException('UNAUTHORIZED', 401, 'A valid access token is required.');
        }

        return $user;
    }

    private function issueSession(User $user): array
    {
        $user->last_login_at = now();
        $user->save();
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

    public function profile(User $user): array
    {
        $plan = $user->effectivePlan();

        return [
            'id' => $user->public_id,
            'phone' => $user->phone,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'middleName' => $user->middle_name,
            'birthDate' => $user->birth_date?->format('Y-m-d'),
            'avatarUrl' => $user->avatar_id === null ? null : AvatarImages::url($user->public_id, $user->avatar_id),
            'language' => $user->language,
            'plan' => $plan,
            'planUntil' => $plan === 'pro' ? $user->plan_until?->utc()->format('Y-m-d\TH:i:s\Z') : null,
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

    private function phoneAlreadyRegistered(string $field = 'phone'): MobileApiException
    {
        return new MobileApiException('PHONE_ALREADY_REGISTERED', 409, 'Phone number is already registered.', $field);
    }
}
