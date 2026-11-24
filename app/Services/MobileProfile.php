<?php

namespace App\Services;

use App\Exceptions\MobileApiException;
use App\Jobs\DeleteAvatarFiles;
use App\Models\MobileSession;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

class MobileProfile
{
    private const array NAMES = [
        'firstName' => 'first_name',
        'lastName' => 'last_name',
        'middleName' => 'middle_name',
    ];

    public function __construct(private readonly MobileAuth $auth, private readonly AvatarImages $images) {}

    public function update(MobileSession $session, array $data): array
    {
        return DB::transaction(function () use ($session, $data): array {
            $user = $this->auth->lockUser($session);

            foreach (self::NAMES as $field => $attribute) {
                if (array_key_exists($field, $data)) {
                    $user->$attribute = $this->name($data[$field], $field);
                }
            }

            if (array_key_exists('birthDate', $data)) {
                $user->birth_date = $this->birthDate($data['birthDate']);
            }
            if (array_key_exists('language', $data)) {
                $user->language = $data['language'];
            }

            $user->save();

            return $this->auth->profile($user);
        });
    }

    public function verifyPassword(MobileSession $session, #[SensitiveParameter] string $password, string $ip): void
    {
        $this->auth->limitPasswordAttempts($session->user->phone, $ip);

        DB::transaction(function () use ($session, $password): void {
            $this->auth->requirePassword($this->auth->lockUser($session), $password, 'password');
        });
    }

    public function changePassword(MobileSession $session, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new, string $ip): void
    {
        $this->auth->limitPasswordAttempts($session->user->phone, $ip);

        DB::transaction(function () use ($session, $current, $new): void {
            $user = $this->auth->lockUser($session);
            $this->auth->requirePassword($user, $current, 'currentPassword');

            if (hash_equals($current, $new)) {
                throw new MobileApiException('SAME_PASSWORD', 422, 'Use a different password.', 'newPassword');
            }

            $user->password = $new;
            $user->save();
            $this->auth->clearAccountChallenges($user);
            $this->auth->revokeOtherSessions($session);
            $user->notify(new AccountSecurityNotification('password_changed'));
        });
    }

    public function replaceAvatar(MobileSession $session, UploadedFile $file): string
    {
        $userId = $session->user->public_id;
        $avatar = (string) Str::uuid();
        $this->images->store($file, $userId, $avatar);

        try {
            DB::transaction(function () use ($session, $avatar): void {
                $user = $this->auth->lockUser($session);
                $this->queueAvatarDeletion($user);
                $user->avatar_id = $avatar;
                $user->save();
            });
        } catch (Throwable $error) {
            $this->images->delete($userId, $avatar);

            throw $error;
        }

        return AvatarImages::url($userId, $avatar);
    }

    public function deleteAvatar(MobileSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $user = $this->auth->lockUser($session);
            $this->queueAvatarDeletion($user);
            $user->avatar_id = null;
            $user->save();
        });
    }

    public function deleteAccount(MobileSession $session, #[SensitiveParameter] string $password, string $ip): void
    {
        $this->auth->limitPasswordAttempts($session->user->phone, $ip);

        DB::transaction(function () use ($session, $password): void {
            $user = $this->auth->lockUser($session);
            $this->auth->requirePassword($user, $password, 'password');

            $this->queueAvatarDeletion($user);
            $user->notifications()->delete();
            PhoneVerification::query()->where('user_id', $user->id)->orWhere('phone', $user->phone)->delete();
            $user->delete();
        });
    }

    private function queueAvatarDeletion(User $user): void
    {
        if ($user->avatar_id !== null) {
            DeleteAvatarFiles::dispatch($user->public_id, $user->avatar_id)->onConnection('database')->onQueue('default');
        }
    }

    private function name(mixed $value, string $field): ?string
    {
        if ($field === 'middleName' && $value === null) {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > 50
            || ! preg_match("/\A[\p{L}\p{M} '\x{2019}-]+\z/u", $value)
            || ! preg_match('/\p{L}/u', $value)) {
            throw new MobileApiException('INVALID_NAME', 422, 'Enter a name with 1 to 50 characters.', $field);
        }

        return $value;
    }

    private function birthDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts)
            || (int) $parts[1] < 1900 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || $value > now()->format('Y-m-d')) {
            throw new MobileApiException('INVALID_DATE', 422, 'Enter a valid birth date.', 'birthDate');
        }

        return $value;
    }
}
