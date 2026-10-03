<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Kci\Contracts\KciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeKciClient;
use Tests\TestCase;

class StationsApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://stations.example.test/api/krl/stations';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(KciClient::class, new FakeKciClient);
        Http::preventStrayRequests();
    }

    public function test_default_url_comes_from_config_and_admin_can_change_and_reset_it(): void
    {
        config(['kci.stations_api_url' => 'https://www.kci.id/api/krl/stations']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->getJson('/api/v1/admin/settings/stations-api')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://www.kci.id/api/krl/stations')
            ->assertJsonPath('data.is_default', true);

        $this->fromFrontend()->putJson('/api/v1/admin/settings/stations-api', ['url' => self::URL])
            ->assertOk()
            ->assertJsonPath('data.url', self::URL)
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.updated_by', $admin->name);

        $this->fromFrontend()->putJson('/api/v1/admin/settings/stations-api', ['url' => 'ftp://nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->fromFrontend()->deleteJson('/api/v1/admin/settings/stations-api')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://www.kci.id/api/krl/stations');
    }

    public function test_station_sync_reads_the_configured_url(): void
    {
        Setting::put(Setting::STATIONS_API_URL, self::URL);
        Http::fake([self::URL => Http::response(['status' => 200, 'data' => [
            ['sta_id' => 'THB', 'sta_name' => 'TANAH ABANG', 'fg_enable' => 1, 'group_wil' => 0],
            ['sta_id' => 'SUD', 'sta_name' => 'SUDIRMAN', 'fg_enable' => 1, 'group_wil' => 0],
        ]])]);

        $this->artisan('kci:sync-stations')->assertSuccessful();

        $log = SyncLog::latest('id')->first();
        $this->assertSame('http', $log->source);
        $this->assertSame(self::URL, $log->meta['url']);
        $this->assertSame(2, $log->records_processed);
        $this->assertSame(['THB', 'SUD'], Station::orderByDesc('code')->pluck('code')->all());
        Http::assertSent(fn ($request) => $request->url() === self::URL && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_area_header_rows_become_area_names_not_stations(): void
    {
        Setting::put(Setting::STATIONS_API_URL, self::URL);
        Station::create(['code' => 'WIL0', 'name' => 'Area Jabodetabek', 'slug' => 'area-jabodetabek']); // saved by an older sync
        Http::fake([self::URL => Http::response(['status' => 200, 'message' => 'Success', 'data' => [
            ['sta_id' => 'WIL0', 'sta_name' => 'AREA JABODETABEK', 'group_wil' => 0, 'fg_enable' => 0],
            ['sta_id' => 'AC', 'sta_name' => 'ANCOL', 'group_wil' => 0, 'fg_enable' => 1],
            ['sta_id' => 'WIL6', 'sta_name' => 'AREA YOGYAKARTA', 'group_wil' => 6, 'fg_enable' => 0],
            ['sta_id' => 'YK', 'sta_name' => 'YOGYAKARTA', 'group_wil' => 6, 'fg_enable' => 1],
        ]])]);

        $this->artisan('kci:sync-stations')->assertSuccessful();

        $this->assertSame(['AC', 'YK'], Station::orderBy('code')->pluck('code')->all());
        $this->assertSame([0 => 'Jabodetabek', 6 => 'Yogyakarta'], Setting::operationalAreas());
        $this->assertSame(['WIL0'], SyncLog::latest('id')->first()->meta['removed_area_rows']);

        $admin = User::factory()->admin()->create();
        $yk = Station::where('code', 'YK')->first();
        $this->actingAs($admin, 'admin')->fromFrontend()
            ->getJson("/api/v1/admin/stations/{$yk->id}")
            ->assertJsonPath('data.operational_area', 6)
            ->assertJsonPath('data.operational_area_name', 'Yogyakarta');
    }

    public function test_alternative_json_shapes_are_understood(): void
    {
        Setting::put(Setting::STATIONS_API_URL, self::URL);
        Http::fake([self::URL => Http::response([
            ['code' => 'bks', 'name' => 'Bekasi', 'active' => true],
            ['code' => 'CKR', 'name' => 'Cikarang', 'active' => false],
        ])]);

        $this->artisan('kci:sync-stations')->assertSuccessful();

        $this->assertSame('Bekasi', Station::where('code', 'BKS')->value('name'));
        $this->assertFalse(Station::where('code', 'CKR')->value('kci_enabled'));
    }

    public function test_blocked_url_fails_cleanly_and_keeps_existing_stations(): void
    {
        Station::create(['code' => 'THB', 'name' => 'Tanah Abang', 'slug' => 'tanah-abang']);
        Setting::put(Setting::STATIONS_API_URL, self::URL);
        Http::fake([self::URL => Http::response('<html>Attention Required! | Cloudflare</html>', 403, ['Server' => 'cloudflare'])]);

        $this->artisan('kci:sync-stations')->assertFailed();

        $log = SyncLog::latest('id')->first();
        $this->assertSame(SyncStatus::Failed, $log->status);
        $this->assertStringContainsString('HTTP 403', $log->error_message);
        $this->assertStringContainsString('Cloudflare', $log->error_message);
        $this->assertSame(1, Station::count());
    }

    public function test_html_response_is_rejected(): void
    {
        Setting::put(Setting::STATIONS_API_URL, self::URL);
        Http::fake([self::URL => Http::response('<html>hello</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('kci:sync-stations')->assertFailed();

        $this->assertStringContainsString('did not return JSON', SyncLog::latest('id')->value('error_message'));
    }

    public function test_empty_url_falls_back_to_the_kci_client(): void
    {
        Setting::put(Setting::STATIONS_API_URL, '');

        $this->artisan('kci:sync-stations')->assertSuccessful();

        $this->assertSame('fake', SyncLog::latest('id')->value('source'));
        $this->assertSame(3, Station::count());
        Http::assertNothingSent();
    }

    public function test_admin_can_test_a_url_without_saving(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([self::URL => Http::response(['data' => [['sta_id' => 'MRI', 'sta_name' => 'MANGGARAI']]])]);

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->postJson('/api/v1/admin/settings/stations-api/test', ['url' => self::URL])
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.sample.0.name', 'MANGGARAI');

        $this->assertSame(0, Station::count());
        $this->assertNull(Setting::find(Setting::STATIONS_API_URL));
    }

    public function test_only_admins_can_manage_the_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromFrontend()
            ->putJson('/api/v1/admin/settings/stations-api', ['url' => self::URL])
            ->assertUnauthorized();
    }
}
