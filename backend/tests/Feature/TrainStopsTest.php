<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Setting;
use App\Models\Station;
use App\Models\TrainStop;
use App\Models\User;
use App\Services\Kci\Contracts\KciClient;
use App\Services\ScheduleSyncService;
use App\Services\TrainStopSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeKciClient;
use Tests\TestCase;

class TrainStopsTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEDULES_URL = 'https://kci.example.test/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A00';

    private const STOPS_URL = 'https://kci.example.test/api/krl/train-schedule?trainid=5701C';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(KciClient::class, new FakeKciClient);
        Http::preventStrayRequests();

        foreach (['MRI' => 'Manggarai', 'SUD' => 'Sudirman', 'THB' => 'Tanah Abang', 'DU' => 'Duri', 'KPB' => 'Kampung Bandan', 'PLM' => 'Palmerah', 'SRP' => 'Serpong'] as $code => $name) {
            Station::create(['code' => $code, 'name' => $name, 'slug' => strtolower($code)]);
        }

        Setting::put(Setting::SCHEDULES_API_URL, self::SCHEDULES_URL);
        Setting::put(Setting::TRAIN_STOPS_API_URL, self::STOPS_URL);

        Http::fake([
            'kci.example.test/api/krl/schedules*' => Http::response(['status' => 200, 'data' => [
                ['train_id' => '5701C', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'MANGGARAI-KAMPUNGBANDAN', 'dest' => 'KAMPUNGBANDAN', 'time_est' => '04:27:00', 'color' => '#0084D8', 'dest_time' => '04:45:00'],
                ['train_id' => '1800A', 'ka_name' => 'COMMUTER LINE RANGKASBITUNG', 'route_name' => 'TANAHABANG-SERPONG', 'dest' => 'SERPONG', 'time_est' => '21:30:00', 'color' => '#93ca3e', 'dest_time' => '22:05:00'],
            ]]),
            'kci.example.test/api/krl/train-schedule?trainid=5701C' => Http::response(['status' => 200, 'data' => [
                ['train_id' => '5701C', 'station_id' => 'MRI', 'station_name' => 'MANGGARAI', 'time_est' => '04:15:00', 'transit_station' => true],
                ['train_id' => '5701C', 'station_id' => 'SUD', 'station_name' => 'SUDIRMAN', 'time_est' => '04:19:30', 'transit_station' => false],
                ['train_id' => '5701C', 'station_id' => 'THB', 'station_name' => 'TANAH ABANG', 'time_est' => '04:27:00', 'transit_station' => true],
                ['train_id' => '5701C', 'station_id' => 'DU', 'station_name' => 'DURI', 'time_est' => '04:33:00', 'transit_station' => true],
                ['train_id' => '5701C', 'station_id' => 'KPB', 'station_name' => 'KAMPUNG BANDAN', 'time_est' => '04:45:00', 'transit_station' => true],
            ]]),
            'kci.example.test/api/krl/train-schedule?trainid=1800A' => Http::response(['status' => 200, 'data' => [
                ['train_id' => '1800A', 'station_id' => 'THB', 'station_name' => 'TANAH ABANG', 'time_est' => '21:30:00', 'transit_station' => true],
                ['train_id' => '1800A', 'station_id' => 'PLM', 'station_name' => 'PALMERAH', 'time_est' => '21:36:00', 'transit_station' => false],
                ['train_id' => '1800A', 'station_id' => 'SRP', 'station_name' => 'SERPONG', 'time_est' => '22:05:00', 'transit_station' => false],
            ]]),
        ]);
    }

    private function sync(): \App\Models\SyncLog
    {
        $service = $this->app->make(ScheduleSyncService::class);

        return $service->run($service->createLog('console'), now(), 1, ['THB']);
    }

    public function test_schedule_sync_also_stores_train_stops(): void
    {
        $log = $this->sync();

        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame(['trains' => 2, 'fetched' => 2, 'skipped' => 0, 'stops' => 8, 'failed' => 0, 'failed_sample' => []], $log->meta['train_stops']);
        $this->assertSame(8, TrainStop::count());
        $this->assertSame('04:19:30', TrainStop::where('station_code', 'SUD')->value('time'));
    }

    public function test_resync_skips_trains_whose_stops_are_known(): void
    {
        $this->sync();
        $log = $this->sync();

        $this->assertSame(2, $log->meta['train_stops']['skipped']);
        Http::assertSentCount(1 + 2 + 1); // THB timetable per sync (x2), stops only once per train
    }

    public function test_to_station_returns_only_trains_stopping_there_later(): void
    {
        $this->sync();
        $date = now()->toDateString();

        $this->getJson("/api/v1/stations/THB/schedules?date={$date}&to=DU")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.train_number', '5701C')
            ->assertJsonPath('data.0.departure_time', '04:27')
            ->assertJsonPath('data.0.to_station_arrival_time', '04:33')
            ->assertJsonPath('data.0.stops_to_station', 1)
            ->assertJsonPath('meta.to.name', 'Duri')
            ->assertJsonPath('meta.stops_available', true);

        $this->getJson("/api/v1/stations/THB/schedules?date={$date}&to=srp")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.train_number', '1800A')
            ->assertJsonPath('data.0.to_station_arrival_time', '22:05');

        // Sudirman is before Tanah Abang on 5701C: not reachable from THB.
        $this->getJson("/api/v1/stations/THB/schedules?date={$date}&to=SUD")->assertOk()->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/stations/THB/schedules?date={$date}&to=XYZ")->assertStatus(422);
        $this->getJson("/api/v1/stations/THB/schedules?date={$date}&to=THB")->assertStatus(422);

        // Without ?to= nothing changes.
        $this->getJson("/api/v1/stations/THB/schedules?date={$date}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.to_station_arrival_time');
    }

    public function test_reachable_destinations(): void
    {
        $this->sync();

        $this->getJson('/api/v1/stations/THB/destinations?date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data', [
                ['code' => 'DU', 'name' => 'Duri', 'slug' => 'du', 'trains' => 1],
                ['code' => 'KPB', 'name' => 'Kampung Bandan', 'slug' => 'kpb', 'trains' => 1],
                ['code' => 'PLM', 'name' => 'Palmerah', 'slug' => 'plm', 'trains' => 1],
                ['code' => 'SRP', 'name' => 'Serpong', 'slug' => 'srp', 'trains' => 1],
            ]);
    }

    public function test_empty_stops_url_disables_stop_sync(): void
    {
        Setting::put(Setting::TRAIN_STOPS_API_URL, '');

        $log = $this->sync();

        $this->assertSame(0, $log->meta['train_stops']['fetched']);
        $this->assertSame(0, TrainStop::count());
    }

    public function test_url_for_train(): void
    {
        $this->assertSame('https://x.test/train-schedule?trainid=1800A&x=1', TrainStopSyncService::urlForTrain('https://x.test/train-schedule?trainid=5701C&x=1', '1800A'));
        $this->assertSame('https://x.test/t/1800A', TrainStopSyncService::urlForTrain('https://x.test/t/{train}', '1800A'));
    }

    public function test_admin_can_configure_and_test_stops_url(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->putJson('/api/v1/admin/settings/train-stops-api', ['url' => 'https://kci.example.test/api/krl/train-schedule'])
            ->assertUnprocessable();

        $this->fromFrontend()
            ->postJson('/api/v1/admin/settings/train-stops-api/test', ['url' => self::STOPS_URL, 'train' => '1800A'])
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.stops.2.station_code', 'SRP')
            ->assertJsonPath('data.stops.2.time', '22:05');
    }
}
