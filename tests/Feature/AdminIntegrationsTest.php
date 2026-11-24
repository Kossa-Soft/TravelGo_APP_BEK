<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_is_created_with_a_hashed_password_and_encrypted_totp_secret(): void
    {
        $this->artisan('admin:create-superadmin', ['email' => 'owner@example.com'])
            ->expectsQuestion('Password (at least 14 characters, up to 72 bytes)', 'example-long-password')
            ->expectsQuestion('Confirm password', 'example-long-password')
            ->assertSuccessful();

        $admin = AdminUser::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue($admin->is_superadmin);
        $this->assertTrue(password_verify('example-long-password', $admin->password));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $admin->totp_secret);
        $this->assertNotSame($admin->totp_secret, $admin->getRawOriginal('totp_secret'));
    }

    public function test_public_and_mobile_accounts_cannot_access_integrations(): void
    {
        $this->get(route('admin.integrations.show'))->assertRedirectToRoute('admin.login');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.integrations.show'))->assertRedirectToRoute('admin.login');
    }

    public function test_local_admin_can_sign_in_without_a_code_when_totp_is_disabled(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['auth.admin_require_totp' => false]);

        $admin = AdminUser::factory()->superadmin()->create();

        $this->get(route('admin.login'))->assertOk()->assertDontSee('name="code"', escape: false);

        $this->post(route('admin.login.store'), [
            '_token' => session()->token(),
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('admin');

        $this->post(route('admin.login.store'), [
            '_token' => session()->token(),
            'email' => $admin->email,
            'password' => 'factory-password',
        ])->assertRedirectToRoute('admin.integrations.show');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_login_requires_an_authenticator_code_that_cannot_be_reused(): void
    {
        config(['auth.admin_require_totp' => false]);
        $this->travelTo(Carbon::createFromTimestampUTC(59));

        // The SHA-1 test vector from RFC 6238, reduced to a six-digit code.
        $admin = AdminUser::factory()->superadmin()->create([
            'totp_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        ]);
        $credentials = ['email' => $admin->email, 'password' => 'factory-password'];

        $this->get(route('admin.login'))->assertOk()->assertSee('name="code"', escape: false);
        $this->post(route('admin.login.store'), $credentials)->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->post(route('admin.login.store'), $credentials + ['code' => '000000'])
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('_old_input.code');
        $this->assertGuest('admin');

        $this->post(route('admin.login.store'), $credentials + ['code' => '287082'])
            ->assertRedirectToRoute('admin.integrations.show');
        $this->assertAuthenticatedAs($admin, 'admin');

        $this->post(route('admin.logout'))->assertRedirectToRoute('admin.login');
        $this->assertGuest('admin');

        $this->post(route('admin.login.store'), $credentials + ['code' => '287082'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_staff_permissions_limit_integration_access(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin')
            ->get(route('admin.integrations.show'))->assertForbidden();

        $reader = AdminUser::factory()->create(['permissions' => ['integrations.alphasms.view']]);
        $this->actingAs($reader, 'admin')->get(route('admin.integrations.show'))
            ->assertOk()->assertSee('Site-Code')->assertDontSee('name="api_key"', escape: false);

        $this->patch(route('admin.integrations.update'), ['sender' => 'TravelGo'])->assertForbidden();
        $this->post(route('admin.integrations.check'))->assertForbidden();
    }

    public function test_saving_settings_requires_the_current_password_and_does_not_flash_secrets(): void
    {
        $this->actingAs(AdminUser::factory()->superadmin()->create(), 'admin')
            ->fromRoute('admin.integrations.show')
            ->patch(route('admin.integrations.update'), [
                'sender' => 'Site-Code',
                'api_key' => 'test-alpha-token',
                'admin_password' => 'wrong-password',
            ])
            ->assertRedirectToRoute('admin.integrations.show')
            ->assertSessionHasErrors('admin_password')
            ->assertSessionMissing('_old_input.api_key')
            ->assertSessionMissing('_old_input.admin_password');

        $this->assertDatabaseHas('integration_settings', ['provider' => 'alphasms', 'api_key' => null]);
        $this->assertDatabaseEmpty('integration_audit_events');
    }

    public function test_superadmin_can_save_and_keep_an_encrypted_key_without_revealing_it(): void
    {
        $admin = AdminUser::factory()->superadmin()->create();
        $this->actingAs($admin, 'admin')->patch(route('admin.integrations.update'), [
            'sender' => 'Site-Code',
            'api_key' => 'test-alpha-token',
            'admin_password' => 'factory-password',
        ])->assertRedirectToRoute('admin.integrations.show')->assertSessionHasNoErrors();

        $setting = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();
        $encryptedKey = $setting->getRawOriginal('api_key');
        $this->assertNotEquals('test-alpha-token', $encryptedKey);
        $this->assertSame('test-alpha-token', $setting->api_key);
        $this->assertArrayNotHasKey('api_key', $setting->toArray());

        $this->get(route('admin.integrations.show'))->assertOk()
            ->assertSee('Ключ сохранён')->assertDontSee('test-alpha-token');

        $this->patch(route('admin.integrations.update'), [
            'sender' => 'TravelGo',
            'api_key' => '',
            'admin_password' => 'factory-password',
        ])->assertRedirectToRoute('admin.integrations.show')->assertSessionHasNoErrors();

        $this->assertSame($encryptedKey, $setting->refresh()->getRawOriginal('api_key'));
        $this->assertSame('TravelGo', $setting->sender);
        $this->assertDatabaseCount('integration_audit_events', 2);
        $firstChange = DB::table('integration_audit_events')
            ->where('admin_user_id', $admin->id)
            ->where('integration_setting_id', $setting->id)
            ->orderBy('id')->value('changed_fields');
        $this->assertSame(['api_key'], json_decode((string) $firstChange, true));

        $this->assertStringNotContainsString('test-alpha-token', DB::table('integration_audit_events')->pluck('changed_fields')->implode(' '));
    }

    public function test_connection_check_uses_a_balance_request_and_shows_no_provider_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.alphasms.ua/api/json.php' => Http::sequence()
            ->push(['success' => true, 'data' => [['success' => true, 'data' => ['amount' => 0]]]])
            ->push(['success' => false, 'error' => 'test-alpha-token'])]);

        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();
        $settings->api_key = 'test-alpha-token';
        $settings->save();

        $this->actingAs(AdminUser::factory()->superadmin()->create(), 'admin')
            ->post(route('admin.integrations.check'))
            ->assertRedirectToRoute('admin.integrations.show')
            ->assertSessionHas('status');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['auth'] === 'test-alpha-token'
            && $request['data'] === [['type' => 'balance']]);

        $this->followingRedirects()->post(route('admin.integrations.check'))
            ->assertOk()
            ->assertSee('AlphaSMS не подтвердил подключение')
            ->assertDontSee('test-alpha-token');
    }
}
