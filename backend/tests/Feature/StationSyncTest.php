<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Kci\Contracts\KciClient;
use App\Services\ScheduleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeKciClient;
use Tests\TestCase;

class StationSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeKciClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeKciClient;
        $this->client->stations[0]['group_wil'] = 2;
        $this->app->instance(KciClient::class, $this->client);
    }

    public function test_station_sync_command_imports_stations(): void
    {
        $this->artisan('kci:sync-stations')->assertSuccessful();

        $log = SyncLog::latest('id')->first();
        $this->assertSame(SyncLog::TYPE_KCI_STATIONS, $log->type);
        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame(3, $log->meta['created']);

        $bekasi = Station::where('code', 'BKS')->first();
        $this->assertSame('Bekasi', $bekasi->name);
        $this->assertSame(2, $bekasi->operational_area);
        $this->assertTrue($bekasi->kci_enabled);
        $this->assertNotNull($bekasi->synced_at);
        $this->assertSame(0, Schedule::count(), 'station sync must not touch schedules');
    }

    public function test_resync_updates_names_keeps_admin_status_and_reports_missing(): void
    {
        $this->artisan('kci:sync-stations');
        Station::where('code', 'BKS')->update(['is_active' => false]);
        Station::create(['code' => 'OLD', 'name' => 'Closed Station', 'slug' => 'closed-station']);

        $this->client->stations[1]['sta_name'] = 'MANGGARAI BARU';
        $this->artisan('kci:sync-stations');

        $log = SyncLog::latest('id')->first();
        $this->assertSame(0, $log->meta['created']);
        $this->assertSame(1, $log->meta['updated']);
        $this->assertSame(['OLD'], $log->meta['missing_from_kci']);
        $this->assertSame('Manggarai Baru', Station::where('code', 'MRI')->value('name'));
        $this->assertFalse(Station::where('code', 'BKS')->value('is_active'));
        $this->assertTrue(Station::where('code', 'OLD')->exists(), 'missing stations are never deleted automatically');
    }

    public function test_daily_schedule_sync_does_not_refetch_station_list(): void
    {
        $this->artisan('kci:sync-stations');
        $this->assertSame(1, $this->client->stationFetches);

        $service = $this->app->make(ScheduleSyncService::class);
        $log = $service->run($service->createLog('console'), now(), 1);

        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame(1, $this->client->stationFetches);
        $this->assertSame(4, Schedule::count());
    }

    public function test_admin_can_view_station_detail_and_set_coordinates(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->app->make(ScheduleSyncService::class);
        $service->run($service->createLog('console'), now(), 2);
        $station = Station::where('code', 'MRI')->first();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->getJson("/api/v1/admin/stations/{$station->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'MRI')
            ->assertJsonPath('data.lines.0.name', 'COMMUTER LINE BOGOR')
            ->assertJsonPath('data.lines.0.color', '#E30A16')
            ->assertJsonCount(2, 'data.schedules_by_date')
            ->assertJsonPath('data.schedules_by_date.0.trains', 2)
            ->assertJsonPath('data.schedules_by_date.0.first_departure', '04:16')
            ->assertJsonPath('data.last_schedule_update', fn (string $value) => str_ends_with($value, '+07:00'));

        $this->fromFrontend()
            ->patchJson("/api/v1/admin/stations/{$station->id}", ['latitude' => -6.2099, 'longitude' => 106.8502])
            ->assertOk()
            ->assertJsonPath('data.latitude', -6.2099);

        $this->fromFrontend()
            ->patchJson("/api/v1/admin/stations/{$station->id}", ['latitude' => 120])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);
    }

    public function test_admin_can_trigger_station_sync(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->postJson('/api/v1/admin/stations/sync')
            ->assertAccepted()
            ->assertJsonPath('data.type', SyncLog::TYPE_KCI_STATIONS);

        $this->assertSame(3, Station::count());
        $this->assertSame('success', SyncLog::latest('id')->first()->status->value);

        $this->fromFrontend()->getJson('/api/v1/admin/stations')
            ->assertOk()
            ->assertJsonPath('meta.last_station_sync.status', 'success')
            ->assertJsonStructure(['meta' => ['next_station_sync', 'station_sync_in_progress']]);
    }

    public function test_normal_users_cannot_trigger_station_sync(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromFrontend()->postJson('/api/v1/admin/stations/sync')->assertUnauthorized();
    }
}
