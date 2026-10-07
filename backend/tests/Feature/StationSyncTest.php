<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Station;
use App\Models\User;
use App\Services\Kci\Contracts\KciClient;
use App\Services\Kci\Data\KciSchedule;
use App\Services\KciService;
use App\Services\ScheduleCarryForwardService;
use App\Services\ScheduleSyncService;
use App\Services\StationSyncService;
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

    public function test_admin_can_view_station_detail_and_set_coordinates(): void
    {
        $admin = User::factory()->admin()->create();
        // Stations and two days of MRI's timetable, as the ingest API / an import stores them.
        $kci = $this->app->make(KciService::class);
        $this->app->make(StationSyncService::class)->import($kci->getStations());
        $station = Station::where('code', 'MRI')->first();
        $schedules = $this->app->make(ScheduleSyncService::class);
        foreach ([now(), now()->addDay()] as $date) {
            $schedules->persist($station, $date, $kci->getStationSchedules('MRI', $date));
        }
        $schedules->refreshLineColors();

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

    public function test_station_list_has_no_manual_sync_any_more(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->fromFrontend();

        $this->postJson('/api/v1/admin/stations/sync')->assertNotFound();
        $this->getJson('/api/v1/admin/stations')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['last_station_sync', 'station_sync_in_progress']]);
    }

    public function test_station_list_puts_the_busiest_and_most_recently_synced_stations_first(): void
    {
        $admin = User::factory()->admin()->create();
        $sync = $this->app->make(ScheduleSyncService::class);
        $make = fn (string $code) => Station::create(['code' => $code, 'name' => "Stasiun {$code}", 'slug' => strtolower($code)]);
        [$a, $b, $c, $never] = [$make('AAA'), $make('BBB'), $make('CCC'), $make('NEV')];
        $rows = fn (int $n) => collect(range(1, $n))->map(fn (int $i) => new KciSchedule("T{$i}", 'COMMUTER LINE X', '#123456', null, 'TUJUAN', sprintf('05:%02d:00', $i), null));

        $this->travelTo(now()->subHour());
        $sync->persist($b, now(), $rows(2));
        $this->travelBack();
        $sync->persist($c, now(), $rows(2));
        $sync->persist($a, now(), $rows(5));
        // A carried-forward copy is not a sync: NEV stays "never synced".
        Schedule::create(['station_id' => $never->id, 'train_number' => 'OLD', 'destination' => 'X', 'departure_time' => '05:00:00', 'service_date' => now()->subDay()->toDateString()]);
        $this->app->make(ScheduleCarryForwardService::class)->carryForward(now());

        $response = $this->actingAs($admin, 'admin')->fromFrontend()
            ->getJson('/api/v1/admin/stations?per_page=200')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame(['AAA', 'CCC', 'BBB', 'NEV'], array_slice($codes, 0, 4), 'most trains today, then the latest sync; never synced last among equals');
        $this->assertNull($response->json('data.3.schedules_synced_at'));
        $this->assertNotNull($response->json('data.0.schedules_synced_at'));
    }
}
