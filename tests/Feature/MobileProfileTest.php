<?php

namespace Tests\Feature;

use App\Jobs\DeleteAvatarFiles;
use App\Jobs\SendPhoneChangedSms;
use App\Models\MobileSession;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\AvatarImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileProfileTest extends TestCase
{
    use RefreshDatabase;

    private const string PHONE = '+12025550147';

    private const string NEW_PHONE = '+12025550149';

    private const string PASSWORD = 'original-password';

    public function test_profile_updates_are_partial_and_invalid_input_leaves_the_profile_intact(): void
    {
        $this->account();
        $this->patchJson('/v1/me', ['firstName' => 'Someone'])->assertUnauthorized();
        $this->useSession($this->signIn());

        $this->patchJson('/v1/me', [
            'firstName' => 'Олена',
            'lastName' => 'Де-Ла Мар',
            'middleName' => ' ',
            'birthDate' => '2000-02-29',
            'language' => 'uk',
            'plan' => 'pro',
        ])->assertOk()->assertJsonPath('firstName', 'Олена')->assertJsonPath('middleName', null)
            ->assertJsonPath('birthDate', '2000-02-29')->assertJsonPath('plan', 'free');

        $this->patchJson('/v1/me', ['firstName' => 'Changed', 'birthDate' => '2001-02-29'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_DATE');
        $this->patchJson('/v1/me', ['lastName' => ['invalid']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_NAME');
        $this->patchJson('/v1/me', ['lastName' => 'O’Neill', 'birthDate' => null])->assertOk();
        $this->getJson('/v1/me')->assertOk()->assertJsonPath('firstName', 'Олена')
            ->assertJsonPath('lastName', 'O’Neill')->assertJsonPath('birthDate', null);
    }

    public function test_phone_change_is_bound_to_the_owner_and_keeps_the_current_session(): void
    {
        Queue::fake();
        config([
            'mobile_auth.demo_phone' => self::NEW_PHONE,
            'mobile_auth.demo_code' => '0123',
            'mobile_auth.send_sms' => true,
        ]);
        $this->freezeTime();
        $owner = $this->account();
        $outsider = $this->account('+12025550148');
        $current = $this->signIn();
        $otherDevice = $this->signIn();
        $otherAccount = $this->signIn($outsider->phone);
        $this->useSession($current);

        $this->postJson('/v1/me/phone/start', ['password' => 'incorrect', 'newPhone' => self::NEW_PHONE])
            ->assertUnauthorized()->assertJsonPath('error.code', 'WRONG_PASSWORD');
        $id = $this->postJson('/v1/me/phone/start', ['password' => self::PASSWORD, 'newPhone' => self::NEW_PHONE])
            ->assertOk()->assertJsonPath('codeLength', 4)->json('verificationId');

        $this->travel(2)->seconds();
        $this->withoutToken()->postJson('/v1/auth/code/resend', ['verificationId' => $id])->assertOk();

        $this->useSession($otherAccount)->postJson('/v1/me/phone/verify', ['verificationId' => $id, 'code' => '0123'])
            ->assertNotFound()->assertJsonPath('error.code', 'VERIFICATION_NOT_FOUND');
        $this->assertDatabaseHas('phone_verifications', ['id' => $id, 'attempts' => 0]);

        $this->useSession($current)->postJson('/v1/me/phone/verify', ['verificationId' => $id, 'code' => '0123'])
            ->assertOk()->assertJsonPath('phone', self::NEW_PHONE);
        $this->getJson('/v1/me')->assertOk()->assertJsonPath('phone', self::NEW_PHONE);
        $this->postJson('/v1/me/phone/verify', ['verificationId' => $id, 'code' => '0123'])->assertNotFound();
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $otherDevice['refreshToken']])->assertUnauthorized();
        $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])->assertUnauthorized();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id, 'type' => 'phone_changed']);
        Queue::assertPushed(SendPhoneChangedSms::class);
    }

    public function test_password_change_revokes_other_sessions_and_pending_challenges(): void
    {
        config(['mobile_auth.send_sms' => false]);
        $user = $this->account();
        $current = $this->signIn();
        $otherDevice = $this->signIn();
        $this->useSession($current);

        $this->postJson('/v1/me/password/verify', ['password' => 'incorrect'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'WRONG_PASSWORD');
        $this->postJson('/v1/me/password/verify', ['password' => self::PASSWORD])->assertNoContent();
        $this->postJson('/v1/me/password', ['currentPassword' => self::PASSWORD, 'newPassword' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SAME_PASSWORD');

        $this->postJson('/v1/me/phone/start', ['password' => self::PASSWORD, 'newPhone' => self::NEW_PHONE])->assertOk();
        $this->assertDatabaseHas('phone_verifications', ['user_id' => $user->id, 'purpose' => 'change_phone']);
        $newPassword = ' new-private-password ';
        $this->postJson('/v1/me/password', ['currentPassword' => self::PASSWORD, 'newPassword' => $newPassword])->assertNoContent();

        $this->assertDatabaseMissing('phone_verifications', ['user_id' => $user->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'type' => 'password_changed']);
        $this->getJson('/v1/me')->assertOk();
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $otherDevice['refreshToken']])->assertUnauthorized();
        $this->signIn(password: $newPassword);
    }

    public function test_avatar_upload_checks_content_and_size_and_invalidates_replaced_images(): void
    {
        $disk = Storage::fake('local');
        Queue::fake();
        $user = $this->account();
        $this->useSession($this->signIn());

        $this->post('/v1/me/avatar', ['file' => UploadedFile::fake()->create('fake.jpg', 4, 'image/jpeg')])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_IMAGE');
        $this->post('/v1/me/avatar', ['file' => UploadedFile::fake()->image('large.jpg')->size(5121)])
            ->assertStatus(413)->assertJsonPath('error.code', 'FILE_TOO_LARGE');

        $url = $this->post('/v1/me/avatar', ['file' => UploadedFile::fake()->image('avatar.jpg', 900, 600)])
            ->assertOk()->json('avatarUrl');
        $id = $user->refresh()->avatar_id;

        foreach ([256, 512] as $size) {
            $image = $disk->get(AvatarImages::path($user->public_id, $id, $size));
            $dimensions = getimagesizefromstring($image);
            $this->assertSame([$size, $size, 'image/webp'], [$dimensions[0], $dimensions[1], $dimensions['mime']]);
            $this->assertStringNotContainsString('EXIF', $image);
        }

        $this->getJson('/v1/me')->assertOk()->assertJsonPath('avatarUrl', $url);
        $this->withoutToken()->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->useSession($this->signIn());

        $replacement = $this->post('/v1/me/avatar', ['file' => UploadedFile::fake()->image('avatar.png')])
            ->assertOk()->json('avatarUrl');
        $this->assertNotSame($url, $replacement);
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        $this->deleteJson('/v1/me/avatar')->assertNoContent();
        $this->get(parse_url($replacement, PHP_URL_PATH))->assertNotFound();
        $this->getJson('/v1/me')->assertJsonPath('avatarUrl', null);
        Queue::assertPushedTimes(DeleteAvatarFiles::class, 2);

        foreach (Queue::pushed(DeleteAvatarFiles::class) as $job) {
            $job->handle(app(AvatarImages::class));
        }

        $this->assertSame([], $disk->allFiles('avatars'));
    }

    public function test_account_deletion_removes_profile_sessions_challenges_and_photos(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['mobile_auth.send_sms' => false]);
        $user = $this->account();
        $session = $this->signIn();
        $this->signIn();
        $this->useSession($session);
        $this->postJson('/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $avatar = $this->post('/v1/me/avatar', ['file' => UploadedFile::fake()->image('avatar.png')])->assertOk()->json('avatarUrl');

        $this->deleteJson('/v1/me', ['password' => 'incorrect'])->assertUnauthorized();
        $this->deleteJson('/v1/me', ['password' => self::PASSWORD])->assertNoContent();

        $this->assertModelMissing($user);
        $this->assertSame(0, MobileSession::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, PhoneVerification::query()->where('phone', self::PHONE)->count());
        $this->getJson('/v1/me')->assertUnauthorized();
        $this->get(parse_url($avatar, PHP_URL_PATH))->assertNotFound();
        Queue::assertPushed(DeleteAvatarFiles::class);
    }

    public function test_web_deletion_requires_explicit_confirmation_and_password(): void
    {
        $user = $this->account();
        $this->get('/account/delete')->assertOk();
        $this->post('/account/delete', ['phone' => self::PHONE, 'password' => self::PASSWORD])->assertSessionHasErrors('confirm');
        $this->post('/account/delete', ['phone' => self::PHONE, 'password' => 'incorrect', 'confirm' => '1'])->assertSessionHasErrors('phone');
        $this->assertModelExists($user);
        $this->post('/account/delete', ['phone' => self::PHONE, 'password' => self::PASSWORD, 'confirm' => '1'])
            ->assertRedirect('/account/delete')->assertSessionHas('account_deleted');
        $this->assertModelMissing($user);
        $this->assertDatabaseEmpty('mobile_sessions');
    }

    private function account(string $phone = self::PHONE): User
    {
        return User::factory()->create(['phone' => $phone, 'password' => self::PASSWORD]);
    }

    private function signIn(string $phone = self::PHONE, string $password = self::PASSWORD): array
    {
        return $this->postJson('/v1/auth/login', ['phone' => $phone, 'password' => $password])->assertOk()->json();
    }

    private function useSession(array $session): static
    {
        return $this->withToken($session['accessToken'])->withHeader('Accept', 'application/json');
    }
}
