<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Station::create(['code' => 'THB', 'name' => 'Tanah Abang', 'slug' => 'tanah-abang']);
        Station::create(['code' => 'SUD', 'name' => 'Sudirman', 'slug' => 'sudirman']);
        Station::create(['code' => 'SG', 'name' => 'Serang', 'slug' => 'serang', 'is_active' => false]);
    }

    public function test_user_can_register_and_cannot_choose_their_role(): void
    {
        $response = $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'name' => 'Budi',
            'email' => 'Budi@Example.com',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
            'role' => 'admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'budi@example.com')
            ->assertJsonPath('data.role', 'user')
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'budi@example.com')->firstOrFail();
        $this->assertFalse($user->isAdmin());
        $this->assertNotSame('Rahasia123', $user->password);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, $user->favoriteRoutes()->count(), 'favourite routes are chosen after signing in');
    }

    public function test_registration_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_user_can_login_with_remember_cookie_and_fetch_profile(): void
    {
        $user = User::factory()->create(['password' => 'Rahasia123']);

        $response = $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Rahasia123',
        ]);

        $response->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotNull($user->fresh()->remember_token, 'remember-me token should be set by default');

        $this->fromFrontend()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'Rahasia123']);

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_me_requires_authentication(): void
    {
        $this->fromFrontend()->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromFrontend()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope'])
                ->assertUnprocessable();
        }

        $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope'])
            ->assertTooManyRequests();
    }

    public function test_mobile_client_can_use_bearer_token(): void
    {
        $user = User::factory()->create(['password' => 'Rahasia123']);

        $token = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'Rahasia123',
            'device_name' => 'flutter-test',
        ])->assertCreated()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}
