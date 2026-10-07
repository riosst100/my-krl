<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    public function test_ingest_replaces_only_what_was_pushed_and_keeps_other_data_valid(): void
    {
        config(['kci.ingest_token' => self::TOKEN]);
        $date = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        [$thb, $sud, $kpb] = ['THB', 'SUD', 'KPB'];
        $id = fn (string $code) => Station::where('code', $code)->value('id');

        // THB and KPB were last synced yesterday; SUD already has today's data.
        Schedule::create(['station_id' => $id($thb), 'train_number' => 'OLD1', 'destination' => 'X', 'departure_time' => '05:00:00', 'service_date' => $yesterday]);
        Schedule::create(['station_id' => $id($kpb), 'train_number' => 'K1', 'destination' => 'Y', 'departure_time' => '05:30:00', 'service_date' => $yesterday]);
        Schedule::create(['station_id' => $id($sud), 'train_number' => 'S1', 'destination' => 'Z', 'departure_time' => '05:45:00', 'service_date' => $date]);
        TrainStop::create(['service_date' => $yesterday, 'train_number' => 'K1', 'sequence' => 1, 'station_code' => 'KPB', 'station_id' => $id($kpb), 'time' => '05:30:00']);
        TrainStop::create(['service_date' => $yesterday, 'train_number' => 'K1', 'sequence' => 2, 'station_code' => 'THB', 'station_id' => $id($thb), 'time' => '05:40:00']);

        $runId = $this->postJson('/api/v1/ingest/start', ['date' => $date, 'stations' => ['THB']], $this->ingestHeaders())->json('data.run_id');

        // Carried forward at start: today's lookups already see the last known timetable.
        $this->getJson('/api/v1/stations/KPB/schedules')->assertOk()->assertJsonPath('data.0.train_number', 'K1');
        $this->assertSame(2, TrainStop::whereDate('service_date', $date)->where('train_number', 'K1')->count());

        $this->postJson('/api/v1/ingest/stations', ['stations' => [
            ['code' => 'THB', 'name' => 'Tanah Abang Baru', 'slug' => 'thb', 'is_active' => false],
            ['code' => 'KRI', 'name' => 'Kranji', 'latitude' => -6.22, 'longitude' => 106.97, 'operational_area' => 0],
        ]], $this->ingestHeaders())->assertOk()->assertJsonPath('data.stations', 2);

        $this->assertSame('Tanah Abang Baru', Station::find($id($thb))->name);
        $this->assertTrue(Station::find($id($thb))->is_active, 'an existing station keeps its activation on the server');
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
            ->assertOk()->assertJsonPath('data.status', 'success');

        $today = fn (string $code) => Schedule::where('station_id', $id($code))->whereDate('service_date', $date)->pluck('train_number')->all();
        $this->assertSame(['5198C'], $today('THB'), 'the synced station has exactly the pushed timetable');
        $this->assertSame(['K1'], $today('KPB'), 'a station the run did not send keeps its last timetable');
        $this->assertSame(['S1'], $today('SUD'));
        // Only the pushed station's older day is replaced; KPB was not pushed and keeps yesterday too.
        $this->assertSame(['K1'], Schedule::whereDate('service_date', $yesterday)->pluck('train_number')->all());
        $this->assertSame(2, TrainStop::whereDate('service_date', $yesterday)->count());
        $this->assertSame(4, TrainStop::whereDate('service_date', $date)->count());

        $log = SyncLog::findOrFail($runId);
        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame(1, $log->records_processed);
        $this->assertSame('ingest', $log->trigger);
        $this->assertSame(['schedules' => 2, 'stops' => 2], $log->meta['carried_forward']);

        $this->getJson('/api/v1/stations/THB/schedules')->assertOk()->assertJsonPath('data.0.train_number', '5198C');
    }

    public function test_the_daily_command_carries_the_latest_day_forward_once(): void
    {
        $thb = Station::where('code', 'THB')->value('id');
        $base = ['station_id' => $thb, 'destination' => 'X', 'departure_time' => '05:00:00'];
        Schedule::create([...$base, 'train_number' => 'A1', 'service_date' => '2026-10-01']);
        Schedule::create([...$base, 'train_number' => 'B1', 'service_date' => '2026-10-03']);
        TrainStop::create(['service_date' => '2026-10-03', 'train_number' => 'B1', 'sequence' => 1, 'station_code' => 'THB', 'station_id' => $thb, 'time' => '05:00:00']);

        $this->artisan('schedules:carry-forward', ['--date' => '2026-10-05'])
            ->expectsOutput('2026-10-05: 1 schedules, 1 stops carried forward')->assertSuccessful();
        $this->assertSame(['B1'], Schedule::whereDate('service_date', '2026-10-05')->pluck('train_number')->all(), 'only the latest day is copied');

        // Running again changes nothing: the date already has data.
        $this->artisan('schedules:carry-forward', ['--date' => '2026-10-05'])
            ->expectsOutput('2026-10-05: 0 schedules, 0 stops carried forward')->assertSuccessful();
        $this->assertSame(3, Schedule::count());
        $this->assertSame(2, TrainStop::count());
    }

    public function test_a_second_run_is_refused_while_one_is_in_progress(): void
    {
        config(['kci.ingest_token' => self::TOKEN]);

        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertCreated();
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertConflict();
    }

    public function test_progress_is_kept_for_the_page_and_a_stopped_run_tells_the_sender_to_stop(): void
    {
        config(['kci.ingest_token' => self::TOKEN]);
        $this->getJson('/api/v1/ingest/runs/latest', $this->ingestHeaders())->assertOk()->assertExactJson(['data' => null]);

        $runId = $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04', 'stations' => ['THB']], $this->ingestHeaders())->json('data.run_id');
        $progress = ['phase' => 'stops', 'percent' => 40, 'progress' => ['stations' => '1/1', 'trains' => '12/360']];

        $this->postJson('/api/v1/ingest/progress', ['run_id' => $runId, 'progress' => $progress], $this->ingestHeaders())
            ->assertOk()->assertJsonPath('data.status', 'running');
        $this->getJson('/api/v1/ingest/runs/latest', $this->ingestHeaders())->assertOk()
            ->assertJsonPath('data.run_id', $runId)
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.progress.percent', 40);

        // Stopped from the page: the run is closed as failed ...
        $this->postJson('/api/v1/ingest/finish', ['run_id' => $runId, 'date' => '2026-10-04', 'status' => 'failed', 'error' => 'Dihentikan'], $this->ingestHeaders())
            ->assertOk()->assertJsonPath('data.status', 'failed');

        // ... the sender learns it at its next report, and its own finish does not overwrite it.
        $this->postJson('/api/v1/ingest/progress', ['run_id' => $runId, 'progress' => $progress], $this->ingestHeaders())
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->postJson('/api/v1/ingest/finish', ['run_id' => $runId, 'date' => '2026-10-04', 'status' => 'success'], $this->ingestHeaders())
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('Dihentikan', SyncLog::find($runId)->error_message);
    }

    public function test_admin_switches_krl_syncs_automatic_sync(): void
    {
        config(['kci.ingest_token' => self::TOKEN]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->putJson('/api/v1/admin/settings/ingest-auto-sync', ['enabled' => false])
            ->assertOk()->assertJsonPath('data.auto_sync', false);
        $this->getJson('/api/v1/ingest/config', $this->ingestHeaders())->assertOk()->assertJsonPath('data.auto_sync', false);

        $this->putJson('/api/v1/admin/settings/ingest-auto-sync', ['enabled' => true])->assertOk()->assertJsonPath('data.auto_sync', true);
        $this->putJson('/api/v1/admin/settings/ingest-auto-sync', [])->assertUnprocessable();
    }

    public function test_this_server_no_longer_syncs_from_kci_itself(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->fromFrontend();

        $this->postJson('/api/v1/admin/sync/kci')->assertNotFound();
        $this->getJson('/api/v1/admin/settings/auto-sync')->assertNotFound();
        $this->getJson('/api/v1/admin/settings/schedules-api')->assertNotFound();
        $this->assertArrayNotHasKey('kci:auto-sync', Artisan::all());
    }
}
