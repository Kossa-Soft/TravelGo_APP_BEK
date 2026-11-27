<?php

namespace Tests\Feature;

use App\Jobs\DeleteAvatarFiles;
use App\Models\AdminUser;
use App\Models\TravelGoContent;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use App\Support\TravelGoType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMobileUsersTest extends TestCase
{
    use RefreshDatabase;

    private const string PHONE = '+12025550142';

    private const string PASSWORD = 'mobile-test-password';

    public function test_staff_can_search_and_view_only_with_separate_permissions(): void
    {
        $first = $this->mobileUser(['first_name' => 'Олена', 'last_name' => 'Волкова', 'created_at' => now()->subDay()]);
        $newer = $this->mobileUser(['phone' => '+12025550143', 'first_name' => 'Іван']);
        User::factory()->create(['phone' => null]);
        $this->getJson('/admin/v1/users')->assertUnauthorized();
        $editor = AdminUser::factory()->create(['permissions' => ['travelgo.*']]);
        $this->actingAs($editor, 'admin')->getJson('/admin/v1/users')->assertForbidden();

        $reader = AdminUser::factory()->create(['permissions' => ['users.view']]);
        $this->actingAs($reader, 'admin')->getJson('/admin/v1/users')->assertOk()->assertJsonPath('items.0.id', $newer->public_id)
            ->assertJsonPath('total', 2);
        $this->getJson('/admin/v1/users?sort=oldest')->assertJsonPath('items.0.id', $first->public_id);
        $this->getJson('/admin/v1/users?q='.urlencode('Олена Волкова'))->assertJsonPath('items.0.phone', self::PHONE);
        $this->getJson('/admin/v1/users?q='.urlencode('+1 (202) 555-0142'))->assertJsonPath('items.0.id', $first->public_id);
        $this->get('/admin/users/'.$first->public_id)->assertOk()->assertSee('Последний вход')->assertDontSee('Сохранить план');
        $this->getJson('/admin/v1/users/'.$first->public_id)->assertOk()->assertJsonMissingPath('password')->assertJsonMissingPath('code');
        $this->patchJson('/admin/v1/users/'.$first->public_id, ['plan' => 'pro'])->assertForbidden();
        $this->patchJson('/admin/v1/users/'.$first->public_id, ['blocked' => true])->assertForbidden();
        $this->deleteJson('/admin/v1/users/'.$first->public_id)->assertForbidden();
    }

    public function test_granted_pro_is_returned_by_the_mobile_profile_and_expires_automatically(): void
    {
        $this->freezeTime();
        $user = $this->mobileUser();
        $access = $this->signIn()['accessToken'];
        $admin = AdminUser::factory()->create(['permissions' => ['users.view', 'users.plan']]);
        $path = '/admin/v1/users/'.$user->public_id;
        $until = now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z');

        $this->actingAs($admin, 'admin')->patchJson($path, ['plan' => 'pro', 'planUntil' => $until])
            ->assertOk()->assertJsonPath('effectivePlan', 'pro');
        $this->withToken($access)->getJson('/v1/me')->assertJsonPath('plan', 'pro')->assertJsonPath('planUntil', $until);
        $this->travel(2)->days();
        $this->signIn();
        $this->getJson('/admin/v1/users/'.$user->public_id)->assertJsonPath('effectivePlan', 'free');
        $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertJsonPath('user.plan', 'free')->assertJsonPath('user.planUntil', null);
        $this->assertDatabaseHas('travelgo_audit_events', ['type' => 'users', 'content_id' => $user->public_id, 'action' => 'plan_changed']);
    }

    public function test_blocking_revokes_sessions_and_unblocking_records_a_new_login(): void
    {
        $user = $this->mobileUser();
        $tokens = $this->signIn();
        $this->signIn();
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->postJson('/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $admin = AdminUser::factory()->create(['permissions' => ['users.view', 'users.block']]);
        $path = '/admin/v1/users/'.$user->public_id;

        $this->actingAs($admin, 'admin')->patchJson($path, ['blocked' => true])->assertOk()->assertJsonPath('blocked', true);

        $this->assertDatabaseMissing('mobile_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('phone_verifications', ['user_id' => $user->id]);
        $this->withToken($tokens['accessToken'])->getJson('/v1/me')->assertUnauthorized();
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $tokens['refreshToken']])->assertUnauthorized();
        $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => 'bad-password'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');
        $this->patchJson($path, ['blocked' => false])->assertOk()->assertJsonPath('blocked', false);
        $this->signIn();
        $this->getJson('/admin/v1/users/audit?user='.$user->public_id)->assertOk()
            ->assertJsonPath('data.0.action', 'unblocked')->assertJsonPath('data.1.action', 'blocked');
    }

    public function test_admin_deletion_requires_confirmation_and_cleans_the_same_account_data_as_mobile(): void
    {
        Queue::fake();
        $user = $this->mobileUser(['avatar_id' => (string) Str::uuid()]);
        $access = $this->signIn()['accessToken'];
        $route = TravelGoContent::factory()->create(['type' => TravelGoType::Route, 'country_code' => null]);
        DB::table('travelgo_saved_routes')->insert(['user_id' => $user->id, 'route_id' => $route->id, 'created_at' => now()]);
        $user->notify(new AccountSecurityNotification('password_changed'));
        $this->postJson('/v1/auth/password/forgot', ['phone' => self::PHONE])->assertOk();
        $staff = AdminUser::factory()->create(['permissions' => ['users.view', 'users.delete']]);
        $path = '/admin/v1/users/'.$user->public_id;

        $this->actingAs($staff, 'admin')->deleteJson($path, ['currentPassword' => 'factory-password'])->assertUnprocessable();
        $this->deleteJson($path, ['confirm' => true, 'currentPassword' => 'bad-password'])->assertUnprocessable();
        $this->assertModelExists($user);

        $this->deleteJson($path, ['confirm' => true, 'currentPassword' => 'factory-password'])->assertNoContent();

        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('mobile_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('phone_verifications', ['phone' => self::PHONE]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $user->id]);
        $this->assertDatabaseMissing('travelgo_saved_routes', ['user_id' => $user->id]);
        $this->withToken($access)->getJson('/v1/me')->assertUnauthorized();
        Queue::assertPushed(DeleteAvatarFiles::class);
        $this->getJson('/admin/v1/users/audit?user='.$user->public_id)->assertOk()
            ->assertJsonPath('data.0.action', 'deleted')->assertDontSee(self::PHONE);
    }

    private function mobileUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['phone' => self::PHONE, 'password' => self::PASSWORD]);
    }

    private function signIn(): array
    {
        return $this->postJson('/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])->assertOk()->json();
    }
}
