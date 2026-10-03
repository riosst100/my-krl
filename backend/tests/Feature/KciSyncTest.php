<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Station;
use App\Models\SyncLog;
use App\Services\Kci\Clients\MockKciClient;
use App\Services\Kci\Contracts\KciClient;
use App\Services\ScheduleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeKciClient;
use Tests\TestCase;

class KciSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeKciClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeKciClient;
        $this->app->instance(KciClient::class, $this->client);
    }

    private function sync(int $days = 2): SyncLog
    {
        $service = $this->app->make(ScheduleSyncService::class);

        return $service->run($service->createLog('console'), now(), $days);
    }

    public function test_sync_imports_stations_and_schedules(): void
    {
        $log = $this->sync(days: 2);

        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame(3, Station::count());
        $this->assertSame(8, Schedule::count()); // 4 trains x 2 days
        $this->assertSame(8, $log->records_processed);
        $this->assertNotNull($log->finished_at);

        $schedule = Schedule::where('train_number', '1002')->first();
        $this->assertSame('Jakarta Kota', $schedule->destination); // "JAKARTAKOTA" mapped to station name
        $this->assertSame('Manggarai', Station::where('code', 'MRI')->value('name'));
    }

    public function test_sync_is_idempotent_and_removes_cancelled_trains(): void
    {
        $this->sync();
        $this->client->schedules['BKS'] = array_slice($this->client->schedules['BKS'], 0, 1);
        $this->sync();

        $this->assertSame(6, Schedule::count());
        $this->assertSame(0, Schedule::where('train_number', '5003')->count());
    }

    public function test_sync_keeps_admin_station_status(): void
    {
        $this->sync();
        Station::where('code', 'BKS')->update(['is_active' => false]);

        $this->sync();

        $this->assertFalse(Station::where('code', 'BKS')->value('is_active'));
    }

    public function test_unavailable_api_is_logged_as_failed(): void
    {
        $this->client->failStations = true;

        $log = $this->sync();

        $this->assertSame(SyncStatus::Failed, $log->status);
        $this->assertStringContainsString('unavailable', $log->error_message);
        $this->assertSame(0, Schedule::count());
    }

    public function test_partial_failures_are_reported(): void
    {
        $this->client->failingStations = ['MRI'];

        $log = $this->sync(days: 1);

        $this->assertSame(SyncStatus::Partial, $log->status);
        $this->assertArrayHasKey('MRI', $log->meta['failed_stations']);
        $this->assertSame(2, Schedule::count());
    }

    public function test_invalid_rows_are_skipped(): void
    {
        $this->client->schedules['BKS'][] = ['train_id' => '9999', 'ka_name' => 'X', 'dest' => 'Y', 'time_est' => '25:99'];

        $this->sync(days: 1);

        $this->assertSame(0, Schedule::where('train_number', '9999')->count());
        $this->assertSame(4, Schedule::count());
    }

    public function test_sync_can_be_limited_to_selected_stations(): void
    {
        $service = $this->app->make(ScheduleSyncService::class);
        $log = $service->run($service->createLog('console'), now(), 1, ['mri']);

        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame(1, $log->stations_processed);
        $this->assertSame(['MRI'], $log->meta['stations']);
        $this->assertSame(3, Station::count()); // the station list itself is still refreshed
        $this->assertSame(0, Schedule::whereHas('station', fn ($q) => $q->where('code', '!=', 'MRI'))->count());
        $this->assertSame(2, Schedule::count());
    }

    public function test_artisan_command_runs_with_mock_source(): void
    {
        $this->app->instance(KciClient::class, new MockKciClient);

        $this->artisan('kci:sync-schedules', ['--days' => 1])->assertSuccessful();

        $this->assertGreaterThan(50, Station::count());
        $this->assertGreaterThan(1000, Schedule::count());
        $this->assertSame('success', SyncLog::latest('id')->first()->status->value);
    }
}
