<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationSetting;
use App\Models\PhoneVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsDeliveryRetryTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = 'https://api.alphasms.ua/api/json.php';

    private const string PHONE = '+12025550145';

    public function test_a_temporary_failure_retries_the_same_code_and_clears_the_claim(): void
    {
        $this->configureProvider();
        Http::fake([self::URL => Http::sequence()
            ->pushStatus(503)
            ->push(['success' => true, 'data' => [['success' => false, 'error' => 'Unknown message']]])
            ->push(['success' => true, 'data' => [['success' => true]]])]);
        $id = $this->start();

        $this->runWorker();
        $this->assertDatabaseHas('jobs', ['queue' => 'mobile-sms', 'attempts' => 1]);
        $this->assertDatabaseHas('phone_verifications', ['id' => $id, 'sms_claimed_at' => null, 'sms_accepted_at' => null]);
        $this->assertDatabaseHas('integration_settings', ['provider' => 'alphasms', 'last_sms_error' => 'unreachable']);

        $this->travel(6)->seconds();
        $this->runWorker();

        $this->assertNotNull(PhoneVerification::findOrFail($id)->sms_accepted_at);
        $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]['data'][0])->all();
        $this->assertSame(['sms', 'status', 'sms'], array_column($requests, 'type'));
        $this->assertSame([$requests[0]['id'], $requests[0]['id'], $requests[0]['id']], array_column($requests, 'id'));
        $this->assertSame($requests[0]['sms_message'], $requests[2]['sms_message']);
        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseEmpty('failed_jobs');

        $this->postJson('/v1/auth/register/verify', [
            'verificationId' => $id, 'code' => substr($requests[2]['sms_message'], -4),
        ])->assertCreated();
    }

    public function test_a_temporary_failure_stops_after_three_send_attempts(): void
    {
        $this->configureProvider();
        Http::fake([self::URL => Http::response(status: 503)]);
        $id = $this->start();

        $this->runWorker();
        $this->travel(6)->seconds();
        $this->runWorker();
        $this->travel(21)->seconds();
        $this->runWorker();

        $types = Http::recorded()->map(fn (array $pair): string => $pair[0]['data'][0]['type'])->all();
        $this->assertSame(['sms', 'status', 'sms', 'status', 'sms'], $types);
        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('phone_verifications', ['id' => $id, 'sms_claimed_at' => null, 'sms_accepted_at' => null]);
    }

    public function test_a_provider_status_on_retry_avoids_a_second_send(): void
    {
        $this->configureProvider();
        Http::fake([self::URL => Http::sequence()->pushStatus(503)->push([
            'success' => true, 'data' => [['success' => true, 'data' => ['status' => 'DELIVERED']]],
        ])]);
        $id = $this->start();
        $this->runWorker();

        $this->travel(6)->seconds();
        $this->runWorker();

        Http::assertSentCount(2);
        $this->assertNotNull(PhoneVerification::findOrFail($id)->sms_accepted_at);
        $this->assertDatabaseHas('integration_settings', ['provider' => 'alphasms', 'last_sms_delivery_status' => 'DELIVERED']);
        $this->assertDatabaseEmpty('jobs');
    }

    public function test_a_rejected_sender_is_logged_once_without_provider_payload_or_codes(): void
    {
        $this->configureProvider();
        Http::fake([self::URL => Http::response([
            'success' => true, 'data' => [['success' => false, 'error' => 'Sender not allowed, secret-fake-provider-key']],
        ])]);
        $id = $this->start();

        $this->runWorker();

        Http::assertSentCount(1);
        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseHas('phone_verifications', ['id' => $id, 'sms_accepted_at' => null]);
        $this->assertDatabaseHas('integration_settings', ['provider' => 'alphasms', 'last_sms_error' => 'sender_rejected']);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertStringNotContainsString('secret-fake-provider-key', DB::table('failed_jobs')->value('exception'));
    }

    public function test_balance_and_delivery_status_are_visible_without_exposing_the_key(): void
    {
        $this->configureProvider();
        $setting = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();
        $setting->last_sms_accepted_at = now();
        $setting->last_sms_request_id = 123;
        $setting->last_sms_error = 'unreachable';
        $setting->save();
        Http::fake([self::URL => Http::sequence()
            ->push(['success' => true, 'data' => [['success' => true, 'data' => ['amount' => 99.316, 'currency' => 'UAH']]]])
            ->push(['success' => true, 'data' => [['success' => true, 'data' => ['status' => 'DELIVERED']]]])]);

        $this->actingAs(AdminUser::factory()->superadmin()->create(), 'admin')
            ->followingRedirects()->post(route('admin.integrations.check'))->assertOk()
            ->assertSee('99.316 UAH')->assertSee('Доставлено')->assertSee('AlphaSMS временно недоступен')
            ->assertDontSee('secret-fake-provider-key');
    }

    private function configureProvider(): void
    {
        config(['mobile_auth.send_sms' => true]);
        Http::preventStrayRequests();
        $settings = IntegrationSetting::query()->where('provider', 'alphasms')->firstOrFail();
        $settings->api_key = 'secret-fake-provider-key';
        $settings->save();
    }

    private function start(): string
    {
        return $this->postJson('/v1/auth/register/start', ['phone' => self::PHONE, 'password' => 'sms-test-password'])
            ->assertOk()->json('verificationId');
    }

    private function runWorker(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mobile-sms', '--once' => true])->run();
    }
}
