<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Services\Kci\Contracts\KciClient;
use App\Services\KciService;
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
}
