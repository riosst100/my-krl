<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_login_to_admin(): void
    {
        $user = User::factory()->create(['password' => 'Rahasia123']);

        $this->fromFrontend()->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'Rahasia123',
        ])->assertUnprocessable();

        $this->assertGuest('admin');
    }

    public function test_admin_can_login_and_view_dashboard(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'Admin12345']);

        $this->fromFrontend()->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Admin12345',
        ])->assertOk()->assertJsonPath('data.role', 'admin');

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web'); // admin login does not sign in to the public site

        $this->fromFrontend()->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total_users', 'total_stations', 'total_schedules', 'last_sync']]);
    }

    public function test_guests_and_website_sessions_cannot_access_admin_api(): void
    {
        $this->fromFrontend()->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web')->fromFrontend()->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_demoted_admin_loses_access_immediately(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->forceFill(['role' => UserRole::User])->save();

        $this->actingAs($admin, 'admin')->fromFrontend()->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_admin_can_change_another_users_role_but_not_their_own(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('data.role', 'admin');

        $this->fromFrontend()
            ->patchJson("/api/v1/admin/users/{$admin->id}/role", ['role' => 'user'])
            ->assertForbidden();

        $this->fromFrontend()
            ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'superuser'])
            ->assertUnprocessable();

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_admin_can_search_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Siti Rahma', 'email' => 'siti@example.com']);
        User::factory()->count(3)->create();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->getJson('/api/v1/admin/users?search=siti')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'siti@example.com');
    }

    public function test_disabled_station_is_hidden_from_public_api(): void
    {
        $admin = User::factory()->admin()->create();
        $station = Station::create(['code' => 'BKS', 'name' => 'Bekasi', 'slug' => 'bekasi']);

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->patchJson("/api/v1/admin/stations/{$station->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/stations/BKS')->assertNotFound();
        $this->getJson('/api/v1/stations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_sync_from_kci_remains_of_the_old_sync_endpoints(): void
    {
        $admin = User::factory()->admin()->create();

        // Manual sync is POST /admin/sync/kci; the old endpoints and the push to prod are gone.
        $this->actingAs($admin, 'admin')->fromFrontend();
        $this->postJson('/api/v1/admin/sync')->assertNotFound();
        $this->postJson('/api/v1/admin/stations/sync')->assertNotFound();
        $this->postJson('/api/v1/admin/sync/prod')->assertNotFound();
        $this->postJson('/api/v1/ingest/start')->assertNotFound();
    }
}
