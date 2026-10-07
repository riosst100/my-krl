<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Services\KciService;
use App\Services\ScheduleSyncService;
use App\Services\TrainStopSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainStopsTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEDULES = ['status' => 200, 'data' => [
        ['train_id' => '5701C', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'MANGGARAI-KAMPUNGBANDAN', 'dest' => 'KAMPUNGBANDAN', 'time_est' => '04:27:00', 'color' => '#0084D8', 'dest_time' => '04:45:00'],
        ['train_id' => '1800A', 'ka_name' => 'COMMUTER LINE RANGKASBITUNG', 'route_name' => 'TANAHABANG-SERPONG', 'dest' => 'SERPONG', 'time_est' => '21:30:00', 'color' => '#93ca3e', 'dest_time' => '22:05:00'],
    ]];

    private const STOPS = [
        '5701C' => ['status' => 200, 'data' => [
            ['train_id' => '5701C', 'station_id' => 'MRI', 'time_est' => '04:15:00', 'transit_station' => true],
            ['train_id' => '5701C', 'station_id' => 'SUD', 'time_est' => '04:19:30', 'transit_station' => false],
            ['train_id' => '5701C', 'station_id' => 'THB', 'time_est' => '04:27:00', 'transit_station' => true],
            ['train_id' => '5701C', 'station_id' => 'DU', 'time_est' => '04:33:00', 'transit_station' => true],
            ['train_id' => '5701C', 'station_id' => 'KPB', 'time_est' => '04:45:00', 'transit_station' => true],
        ]],
        '1800A' => ['status' => 200, 'data' => [
            ['train_id' => '1800A', 'station_id' => 'THB', 'time_est' => '21:30:00', 'transit_station' => true],
            ['train_id' => '1800A', 'station_id' => 'PLM', 'time_est' => '21:36:00', 'transit_station' => false],
            ['train_id' => '1800A', 'station_id' => 'SRP', 'time_est' => '22:05:00', 'transit_station' => false],
        ]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['MRI' => 'Manggarai', 'SUD' => 'Sudirman', 'THB' => 'Tanah Abang', 'DU' => 'Duri', 'KPB' => 'Kampung Bandan', 'PLM' => 'Palmerah', 'SRP' => 'Serpong'] as $code => $name) {
            Station::create(['code' => $code, 'name' => $name, 'slug' => strtolower($code)]);
        }
    }

    /** THB's timetable and its trains' stops for today, stored as krl-sync delivers them. */
    private function store(): void
    {
        $today = now();
        $this->app->make(ScheduleSyncService::class)->persist(
            Station::where('code', 'THB')->first(),
            $today,
            $this->app->make(KciService::class)->parseSchedules(self::SCHEDULES),
        );

        $stops = $this->app->make(TrainStopSyncService::class);
        foreach (self::STOPS as $train => $payload) {
            $stops->store($today->toDateString(), $train, $stops->parse($payload, $train), Station::pluck('id', 'code'));
        }
    }

    public function test_to_station_returns_only_trains_stopping_there_later(): void
    {
        $this->store();
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
        $this->store();

        $this->getJson('/api/v1/stations/THB/destinations?date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data', [
                ['code' => 'DU', 'name' => 'Duri', 'slug' => 'du', 'trains' => 1],
                ['code' => 'KPB', 'name' => 'Kampung Bandan', 'slug' => 'kpb', 'trains' => 1],
                ['code' => 'PLM', 'name' => 'Palmerah', 'slug' => 'plm', 'trains' => 1],
                ['code' => 'SRP', 'name' => 'Serpong', 'slug' => 'srp', 'trains' => 1],
            ]);
    }
}
