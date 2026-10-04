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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Local machine -> production: the push client (admin "Sync Data to Prod") and
 * the ingest API on the server.
 */
class ProdSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'secret-ingest-token';

    private const PAYLOAD = ['status' => 200, 'data' => [
        ['train_id' => '5198C', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'ANGKE-CIKARANG', 'dest' => 'CIKARANG', 'time_est' => '06:01:00', 'color' => '#0084D8', 'dest_time' => '07:04:00'],
        ['train_id' => '5701C', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'MANGGARAI-KAMPUNGBANDAN', 'dest' => 'KAMPUNGBANDAN', 'time_est' => '06:27:00', 'color' => '#0084D8', 'dest_time' => '06:45:00'],
    ]];

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

    // --- Server side: the ingest API --------------------------------------------------

    public function test_ingest_is_off_without_a_configured_token_and_needs_the_right_one(): void
    {
        config(['kci.ingest_token' => null]);
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertForbidden();

        config(['kci.ingest_token' => self::TOKEN]);
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'])->assertUnauthorized();
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/ingest/start', ['date' => '2026-10-04'], $this->ingestHeaders())->assertCreated();
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

    // --- Local side: "Sync Data to Prod" ------------------------------------------------

    public function test_pushing_requires_the_local_configuration(): void
    {
        $admin = User::factory()->admin()->create();
        config(['kci.push_url' => '', 'kci.push_token' => null]);

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->postJson('/api/v1/admin/sync/prod')
            ->assertUnprocessable();

        $this->getJson('/api/v1/admin/sync-logs')->assertOk()->assertJsonPath('meta.mode', 'prod');
    }

    public function test_admin_pushes_the_configured_stations_to_prod_with_progress(): void
    {
        config(['kci.push_url' => 'https://prod.example.test', 'kci.push_token' => self::TOKEN]);
        Setting::put(Setting::SCHEDULES_API_URL, 'https://kci.example.test/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A59');
        Setting::put(Setting::TRAIN_STOPS_API_URL, '');
        Setting::put(Setting::SYNC_STATIONS, 'THB,SUD');
        TrainStop::create(['service_date' => now()->toDateString(), 'train_number' => '5198C', 'sequence' => 1, 'station_code' => 'THB', 'time' => '06:01:00']);
        TrainStop::create(['service_date' => now()->toDateString(), 'train_number' => '5198C', 'sequence' => 2, 'station_code' => 'SUD', 'time' => '06:09:00', 'is_transit' => true]);

        Http::fake([
            'kci.example.test/*' => Http::response(self::PAYLOAD),
            'prod.example.test/api/v1/ingest/start' => Http::response(['data' => ['run_id' => 42]], 201),
            'prod.example.test/*' => Http::response(['data' => []]),
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->fromFrontend()
            ->postJson('/api/v1/admin/sync/prod')
            ->assertAccepted()
            ->assertJsonPath('data.type', SyncLog::TYPE_PROD_PUSH);

        // QUEUE_CONNECTION=sync in tests, so the job already ran.
        $log = SyncLog::where('type', SyncLog::TYPE_PROD_PUSH)->latest('id')->first();
        $this->assertSame(SyncStatus::Success, $log->status, (string) $log->error_message);
        $this->assertSame(['THB', 'SUD'], $log->meta['stations'], 'only the admin-configured stations are synced');
        $this->assertSame(100, $log->meta['progress']['percent']);
        $this->assertSame(2, $log->stations_processed);
        $this->assertSame(4, $log->records_processed);
        $this->assertSame(42, $log->meta['run_id']);

        $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->filter(fn ($p) => str_starts_with((string) $p, '/api/v1/ingest'))->values();
        $this->assertSame(['/api/v1/ingest/stations', '/api/v1/ingest/start', '/api/v1/ingest/schedules', '/api/v1/ingest/schedules', '/api/v1/ingest/stops', '/api/v1/ingest/finish'], $paths->all());

        // Every request carries the token; stations were fetched from KCI one by one.
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'prod.example.test') || $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'kci.example.test') && str_contains($r->url(), 'stationid=SUD'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ingest/stops') && count($r['trains']) === 1 && count($r['trains'][0]['stops']) === 2);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ingest/finish') && $r['status'] === 'success' && $r['stations'] === ['THB', 'SUD']);

        $this->getJson('/api/v1/admin/sync-logs')
            ->assertOk()
            ->assertJsonPath('meta.mode', 'local')
            ->assertJsonPath('meta.push_target', 'prod.example.test')
            ->assertJsonPath('meta.last_successful_sync.id', $log->id);
    }

    public function test_a_prod_error_fails_the_push_with_a_clear_message(): void
    {
        config(['kci.push_url' => 'https://prod.example.test', 'kci.push_token' => 'wrong']);
        Setting::put(Setting::SCHEDULES_API_URL, 'https://kci.example.test/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A59');
        Setting::put(Setting::TRAIN_STOPS_API_URL, '');
        Setting::put(Setting::SYNC_STATIONS, 'THB');

        Http::fake([
            'kci.example.test/*' => Http::response(self::PAYLOAD),
            'prod.example.test/*' => Http::response(['message' => 'Invalid ingest token.'], 401),
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->fromFrontend()->postJson('/api/v1/admin/sync/prod')->assertAccepted();

        $log = SyncLog::where('type', SyncLog::TYPE_PROD_PUSH)->latest('id')->first();
        $this->assertSame(SyncStatus::Failed, $log->status);
        $this->assertStringContainsString('HTTP 401', $log->error_message);
        $this->assertStringContainsString('Invalid ingest token.', $log->error_message);
    }

    // --- Manual import of pasted KCI JSON ------------------------------------------------

    public function test_admin_imports_a_pasted_schedules_response(): void
    {
        $admin = User::factory()->admin()->create();
        $thb = Station::where('code', 'THB')->first();
        $kpb = Station::where('code', 'KPB')->first();
        Schedule::create(['station_id' => $kpb->id, 'train_number' => 'OLD', 'destination' => 'X', 'departure_time' => '05:00:00', 'service_date' => now()->subDay()->toDateString()]);
        Http::fake();

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->postJson('/api/v1/admin/sync/import', ['type' => 'schedules', 'station' => 'thb', 'json' => json_encode(self::PAYLOAD)])
            ->assertOk()
            ->assertJsonPath('data.records', 2)
            ->assertJsonPath('data.type', 'schedules');

        Http::assertNothingSent();
        $this->assertSame(['5198C', '5701C'], Schedule::where('station_id', $thb->id)->orderBy('train_number')->pluck('train_number')->all());
        $this->assertSame(0, Schedule::where('train_number', 'OLD')->count(), 'older days are dropped');
        $log = SyncLog::where('trigger', 'import')->first();
        $this->assertSame('manual-json', $log->source);
        $this->assertSame(SyncStatus::Success, $log->status);

        $this->getJson('/api/v1/stations/THB/schedules')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_manual_import_explains_bad_input(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'admin')->fromFrontend();

        $this->postJson('/api/v1/admin/sync/import', ['type' => 'schedules', 'station' => 'THB', 'json' => '{not json'])
            ->assertUnprocessable()->assertJsonValidationErrors('json');
        $this->postJson('/api/v1/admin/sync/import', ['type' => 'schedules', 'station' => 'THB', 'json' => '{"status":200}'])
            ->assertUnprocessable()->assertJsonValidationErrors('json');
        $this->postJson('/api/v1/admin/sync/import', ['type' => 'schedules', 'json' => json_encode(self::PAYLOAD)])
            ->assertUnprocessable()->assertJsonValidationErrors('station');
        $this->postJson('/api/v1/admin/sync/import', ['type' => 'schedules', 'station' => 'NOPE', 'json' => json_encode(self::PAYLOAD)])->assertUnprocessable();
        $this->postJson('/api/v1/admin/sync/import', ['type' => 'train_stops', 'json' => json_encode(['status' => 200, 'data' => [['station_id' => 'THB', 'time_est' => '06:01:00']]])])
            ->assertUnprocessable()->assertJsonValidationErrors('json'); // no train number anywhere

        $this->assertSame(0, Schedule::count());
    }

    public function test_admin_imports_train_stops_and_stations_from_json(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'admin')->fromFrontend();

        $stops = ['status' => 200, 'data' => [
            ['train_id' => '5198C', 'station_id' => 'THB', 'station_name' => 'TANAHABANG', 'time_est' => '06:01:00', 'transit_station' => true],
            ['train_id' => '5198C', 'station_id' => 'SUD', 'station_name' => 'SUDIRMAN', 'time_est' => '06:09:00', 'transit_station' => false],
        ]];

        $this->postJson('/api/v1/admin/sync/import', ['type' => 'train_stops', 'json' => json_encode($stops)])
            ->assertOk()->assertJsonPath('data.records', 2);
        $this->assertSame(['THB', 'SUD'], TrainStop::where('train_number', '5198C')->orderBy('sequence')->pluck('station_code')->all());

        $stations = ['status' => 200, 'data' => [
            ['sta_id' => 'KRI', 'sta_name' => 'KRANJI', 'group_wil' => 0, 'fg_enable' => 1],
            ['sta_id' => 'THB', 'sta_name' => 'TANAHABANG', 'group_wil' => 0, 'fg_enable' => 1],
        ]];
        $this->postJson('/api/v1/admin/sync/import', ['type' => 'stations', 'json' => json_encode($stations)])
            ->assertOk()->assertJsonPath('data.records', 2);
        $this->assertSame('Kranji', Station::where('code', 'KRI')->value('name'));
    }

    public function test_push_can_skip_fetching_and_send_the_local_data_as_is(): void
    {
        config(['kci.push_url' => 'https://prod.example.test', 'kci.push_token' => self::TOKEN]);
        Setting::put(Setting::SYNC_STATIONS, 'THB');
        $thb = Station::where('code', 'THB')->first();
        Schedule::create(['station_id' => $thb->id, 'train_number' => '5198C', 'destination' => 'Cikarang', 'departure_time' => '06:01:00', 'service_date' => now()->toDateString()]);

        Http::fake([
            'prod.example.test/api/v1/ingest/start' => Http::response(['data' => ['run_id' => 9]], 201),
            'prod.example.test/*' => Http::response(['data' => []]),
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->fromFrontend()->postJson('/api/v1/admin/sync/prod', ['fetch' => false])->assertAccepted();

        $log = SyncLog::where('type', SyncLog::TYPE_PROD_PUSH)->latest('id')->first();
        $this->assertSame(SyncStatus::Success, $log->status, (string) $log->error_message);
        $this->assertFalse($log->meta['fetch']);
        $this->assertSame(100, $log->meta['progress']['percent']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'kci.'));
        $this->assertSame(0, SyncLog::where('type', SyncLog::TYPE_KCI_SCHEDULES)->count(), 'nothing was fetched from KCI');

        // Nothing stored locally for today: a clear error instead of an empty push.
        Schedule::query()->delete();
        $this->postJson('/api/v1/admin/sync/prod', ['fetch' => false])->assertAccepted();
        $failed = SyncLog::where('type', SyncLog::TYPE_PROD_PUSH)->latest('id')->first();
        $this->assertSame(SyncStatus::Failed, $failed->status);
        $this->assertStringContainsString('database lokal', $failed->error_message);
    }
}
