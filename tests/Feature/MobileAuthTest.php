<?php

namespace Tests\Feature;

use App\Jobs\SendVerificationSms;
use App\Models\IntegrationSetting;
use App\Models\MobileSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private const string PHONE = '+12025550147';

    private const string PASSWORD = 'original-password';

    private const string CODE = '0136';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mobile_auth.demo_phone' => self::PHONE,
            'mobile_auth.demo_code' => self::CODE,
            'mobile_auth.send_sms' => false,
        ]);
    }

    public function test_registration_creates_an_account_only_after_a_valid_code(): void
    {
        Queue::fake();
        config(['mobile_auth.send_sms' => true]);

        $challenge = $this->startRegistration();
        $this->assertDatabaseMissing('users', ['phone' => self::PHONE]);

        $payload = ['verificationId' => $challenge, 'code' => self::CODE];
        $session = $this->postJson('/v1/auth/register/verify', $payload)->assertCreated()
            ->assertJsonPath('user.phone', self::PHONE)->assertJsonPath('user.language', 'fr')->json();

        $this->assertTrue(Str::isUuid($session['user']['id']));
        $this->assertDatabaseHas('mobile_sessions', ['access_token_hash' => hash('sha256', $session['accessToken'])]);
        $this->assertDatabaseMissing('mobile_sessions', ['refresh_token_hash' => $session['refreshToken']]);
        $this->assertDatabaseHas('phone_verifications', ['id' => $challenge, 'pending_password_hash' => null]);

        $this->withToken($session['accessToken'])->getJson('/v1/me')->assertOk()->assertExactJson($session['user']);
        $this->postJson('/v1/auth/register/verify', $payload)->assertNotFound()->assertJsonPath('error.code', 'VERIFICATION_NOT_FOUND');
        $this->postJson('/v1/auth/register/start', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertConflict()->assertJsonPath('error.code', 'PHONE_ALREADY_REGISTERED');

        Queue::assertNothingPushed();
    }

    public function test_verification_attempts_and_resend_follow_the_test_limits(): void
    {
        $this->freezeTime();
        config(['mobile_auth.code_attempts' => 2]);
        $verificationId = $this->startRegistration();
        $payload = ['verificationId' => $verificationId, 'code' => '0000'];

        $this->postJson('/v1/auth/code/resend', ['verificationId' => $verificationId])
            ->assertTooManyRequests()->assertHeader('Retry-After', '2')
            ->assertJsonPath('error.code', 'RESEND_TOO_EARLY');

        $this->postJson('/v1/auth/register/verify', $payload)->assertUnprocessable()->assertJsonPath('error.details.attemptsLeft', 1);
        $this->postJson('/v1/auth/register/verify', $payload)->assertUnprocessable()->assertJsonPath('error.details.attemptsLeft', 0);
        $payload['code'] = self::CODE;
        $this->postJson('/v1/auth/register/verify', $payload)->assertTooManyRequests()->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');

        $this->travel(2)->seconds();
        $this->postJson('/v1/auth/code/resend', ['verificationId' => $verificationId])->assertOk()->assertJsonPath('resendIn', 2);
        $this->travel(600)->seconds();
        $this->postJson('/v1/auth/register/verify', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'CODE_EXPIRED');
    }

    public function test_login_masks_unknown_accounts_and_rate_limits_a_single_phone(): void
    {
        $this->createAccount();
        config(['mobile_auth.login_per_phone' => 1]);

        $wrong = $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => 'incorrect-password'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $unknown = $this->postJson('/v1/auth/login', ['phone' => '+12025550148', 'password' => 'incorrect-password'])
            ->assertUnauthorized();
        $this->assertSame($wrong->json(), $unknown->json());

        $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonStructure(['error' => ['details' => ['retryAfter']]]);
    }

    public function test_tokens_expire_rotate_and_logout_revokes_the_current_session(): void
    {
        $this->freezeTime();
        $session = $this->createAccount();

        $this->travel(15)->minutes();
        $this->withToken($session['accessToken'])->getJson('/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'TOKEN_EXPIRED');
        $next = $this->postJson('/v1/auth/refresh', ['refreshToken' => $session['refreshToken']])->assertOk()->json();

        $this->assertNotSame($session['accessToken'], $next['accessToken']);
        $this->assertNotSame($session['refreshToken'], $next['refreshToken']);
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $session['refreshToken']])->assertUnauthorized();
        $this->withToken($next['accessToken'])->getJson('/v1/me')->assertOk();
        $this->postJson('/v1/auth/logout', ['refreshToken' => $next['refreshToken']])->assertNoContent();
        $this->getJson('/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHORIZED');
        $this->assertDatabaseEmpty('mobile_sessions');
    }

    public function test_recovery_is_uniform_and_reset_revokes_every_session(): void
    {
        $session = $this->createAccount();
        $another = $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])->assertOk()->json();

        $known = $this->postJson('/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk()->json();
        $unknown = $this->postJson('/v1/auth/password/forgot', ['phone' => '+12025550148'])->assertOk()->json();
        $this->assertTrue(Str::isUuid($unknown['verificationId']));
        $this->assertSame(array_diff_key($known, ['verificationId' => true]), array_diff_key($unknown, ['verificationId' => true]));

        $this->postJson('/v1/auth/password/verify', ['verificationId' => $unknown['verificationId'], 'code' => self::CODE])
            ->assertUnprocessable()->assertJsonPath('error.code', 'WRONG_CODE');

        $token = $this->postJson('/v1/auth/password/verify', ['verificationId' => $known['verificationId'], 'code' => self::CODE])
            ->assertOk()->assertJsonPath('expiresIn', 900)->json('resetToken');

        $payload = ['resetToken' => $token, 'newPassword' => ' new-private-password '];
        $this->postJson('/v1/auth/password/reset', $payload)->assertNoContent();
        $this->postJson('/v1/auth/password/reset', $payload)->assertUnauthorized()->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
        $this->assertSame(0, MobileSession::query()->count());
        $this->withToken($session['accessToken'])->getJson('/v1/me')->assertUnauthorized();
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $another['refreshToken']])->assertUnauthorized();
        $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => $payload['newPassword']])->assertOk();
    }

    public function test_real_sms_is_queued_encrypted_and_sent_only_once_with_provider_settings(): void
    {
        config(['mobile_auth.send_sms' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.alphasms.ua/api/json.php' => Http::response(['success' => true, 'data' => [['success' => true]]])]);

        $setting = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();
        $setting->api_key = 'fake-provider-key';
        $setting->save();

        $phone = '+12025550148';
        $this->postJson('/v1/auth/password/forgot', ['phone' => $phone])->assertOk();
        $this->postJson('/v1/auth/register/start', ['phone' => $phone, 'password' => self::PASSWORD])->assertOk();
        Http::assertNothingSent();

        $jobs = DB::table('jobs')->where('queue', 'mobile-sms')->orderBy('id')->pluck('payload');
        $this->assertCount(2, $jobs);
        $this->assertStringNotContainsString($phone, $jobs->implode(''));
        foreach ($jobs as $payload) {
            $job = unserialize(decrypt(json_decode($payload, true)['data']['command']));
            $this->assertInstanceOf(SendVerificationSms::class, $job);
            $job->handle();
            $job->handle();
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['auth'] === 'fake-provider-key'
            && $request['data'][0]['type'] === 'sms'
            && $request['data'][0]['phone'] === 12025550148
            && $request['data'][0]['sms_signature'] === 'Site-Code');
    }

    public function test_production_returns_unavailable_when_sms_delivery_is_disabled(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->postJson('/v1/auth/register/start', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'SMS_UNAVAILABLE');
        $this->postJson('/v1/auth/password/forgot', ['phone' => self::PHONE])
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'SMS_UNAVAILABLE');
        $this->assertDatabaseEmpty('phone_verifications');
    }

    public function test_invalid_requests_use_the_documented_error_envelope(): void
    {
        $this->postJson('/v1/auth/login')->assertBadRequest()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson('/v1/auth/register/start', ['phone' => 'local-format', 'password' => self::PASSWORD])
            ->assertBadRequest()->assertJsonPath('error.code', 'INVALID_PHONE');
        $this->postJson('/v1/auth/register/start', ['phone' => self::PHONE, 'password' => 'short'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'WEAK_PASSWORD');
        $this->get('/v1/unknown')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    private function startRegistration(): string
    {
        return $this->postJson('/v1/auth/register/start', ['phone' => self::PHONE, 'password' => self::PASSWORD], ['Accept-Language' => 'fr'])
            ->assertOk()->assertJsonPath('expiresIn', 600)->assertJsonPath('codeLength', 4)->assertJsonPath('resendIn', 2)
            ->json('verificationId');
    }

    private function createAccount(): array
    {
        $verificationId = $this->startRegistration();

        return $this->postJson('/v1/auth/register/verify', ['verificationId' => $verificationId, 'code' => self::CODE])
            ->assertCreated()->json();
    }
}
