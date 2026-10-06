<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin -> Sinkronisasi -> Import Manual: a pasted KCI API response (JSON).
 */
class ManualImportTest extends TestCase
{
    use RefreshDatabase;

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
}
