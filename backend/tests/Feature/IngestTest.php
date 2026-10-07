<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ingest API: krl-sync (Vercel) fetches from KCI and pushes the timetable here.
 */
class IngestTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'secret-ingest-token';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['THB', 'Tanah Abang'], ['SUD', 'Sudirman'], ['KPB', 'Kampung Bandan']] as [$code, $name]) {
            Station::create(['code' => $code, 'name' => $name, 'slug' => strtolower($code)]);
        }
    }

    private function ingestHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    public function test_ingest_is_off_without_a_configured_token_and_needs_the_right_one(): void
    {
        config(['kci.ingest_token' => null]);
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertForbidden();
        $this->getJson('/api/v1/ingest/config', $this->ingestHeaders())->assertForbidden();

        config(['kci.ingest_token' => self::TOKEN]);
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'])->assertUnauthorized();
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertCreated();
    }

    public function test_config_follows_the_admin_settings(): void
    {
        config(['kci.ingest_token' => self::TOKEN, 'kci.sync_stations' => []]);

        // Nothing chosen: every active station, and automatic sync on.
        $this->getJson('/api/v1/ingest/config', $this->ingestHeaders())->assertOk()
            ->assertExactJson(['data' => ['stations' => ['KPB', 'SUD', 'THB'], 'auto_sync' => true]]);

        Setting::put(Setting::SYNC_STATIONS, 'thb,SUD');
        Setting::put(Setting::INGEST_AUTO_SYNC, 'false');

        $this->getJson('/api/v1/ingest/config', $this->ingestHeaders())->assertOk()
            ->assertExactJson(['data' => ['stations' => ['THB', 'SUD'], 'auto_sync' => false]]);
    }

    public function test_ingest_replaces_the_server_data_with_what_was_pushed(): void
    {
        config(['kci.ingest_token' => self::TOKEN]);
        $date = now()->toDateString();
        $thb = Station::where('code', 'THB')->first();
        $sud = Station::where('code', 'SUD')->first();

        // Stale data that must disappear: another date, and a station that is not pushed.
        Schedule::create(['station_id' => $thb->id, 'train_number' => 'OLD1', 'destination' => 'X', 'departure_time' => '05:00:00', 'service_date' => now()->subDay()->toDateString()]);
        Schedule::create(['station_id' => $sud->id, 'train_number' => 'OLD2', 'destination' => 'X', 'departure_time' => '05:00:00', 'service_date' => $date]);
        TrainStop::create(['service_date' => now()->subDay()->toDateString(), 'train_number' => 'OLD1', 'sequence' => 1, 'station_code' => 'THB', 'time' => '05:00:00']);

        $runId = $this->postJson('/api/v1/ingest/start', ['date' => $date, 'stations' => ['THB']], $this->ingestHeaders())->json('data.run_id');

        $this->postJson('/api/v1/ingest/stations', ['stations' => [
            ['code' => 'THB', 'name' => 'Tanah Abang Baru', 'slug' => 'thb', 'is_active' => false],
            ['code' => 'KRI', 'name' => 'Kranji', 'latitude' => -6.22, 'longitude' => 106.97, 'operational_area' => 0],
        ]], $this->ingestHeaders())->assertOk()->assertJsonPath('data.stations', 2);

        $this->assertSame('Tanah Abang Baru', $thb->fresh()->name);
        $this->assertTrue($thb->fresh()->is_active, 'an existing station keeps its activation on the server');
        $this->assertSame('kranji', Station::where('code', 'KRI')->value('slug'));

        $this->postJson('/api/v1/ingest/schedules', [
            'run_id' => $runId, 'date' => $date, 'station' => 'THB',
            'schedules' => [[
                'train_number' => '5198C', 'line_name' => 'COMMUTER LINE CIKARANG', 'line_color' => '#0084D8', 'route_name' => 'ANGKE-CIKARANG',
                'destination' => 'Cikarang', 'departure_time' => '06:01:00', 'destination_arrival_time' => '07:04:00',
            ]],
        ], $this->ingestHeaders())->assertOk()->assertJsonPath('data.records', 1);

        $this->postJson('/api/v1/ingest/stops', [
            'run_id' => $runId, 'date' => $date,
            'trains' => [['train_number' => '5198C', 'stops' => [
                ['station_code' => 'THB', 'time' => '06:01:00', 'is_transit' => true],
                ['station_code' => 'SUD', 'time' => '06:10:00'],
            ]]],
        ], $this->ingestHeaders())->assertOk()->assertJsonPath('data.stops', 2);

        $this->postJson('/api/v1/ingest/finish', ['run_id' => $runId, 'date' => $date, 'status' => 'success', 'stations' => ['THB']], $this->ingestHeaders())
            ->assertOk()->assertJsonPath('data.pruned', 2);

        $this->assertSame(['5198C'], Schedule::pluck('train_number')->all());
        $this->assertSame(2, TrainStop::count());
        $log = SyncLog::findOrFail($runId);
        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame(1, $log->records_processed);
        $this->assertSame('ingest', $log->trigger);

        $this->getJson('/api/v1/stations/THB/schedules')->assertOk()->assertJsonPath('data.0.train_number', '5198C');
    }

    public function test_a_second_run_is_refused_while_one_is_in_progress(): void
    {
        config(['kci.ingest_token' => self::TOKEN]);

        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertCreated();
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertConflict();
    }
}
