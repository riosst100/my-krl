<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Kci\Contracts\KciClient;
use App\Services\ScheduleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeKciClient;
use Tests\TestCase;

class SchedulesApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://schedules.example.test/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A00';

    /** Shape of the real https://www.kci.id/api/krl/schedules response. */
    private const PAYLOAD = ['status' => 200, 'data' => [
        ['train_id' => '5198C', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'ANGKE-CIKARANG', 'dest' => 'CIKARANG', 'time_est' => '00:01:00', 'color' => '#0084D8', 'dest_time' => '01:04:00'],
        ['train_id' => '5701C', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'MANGGARAI-KAMPUNGBANDAN', 'dest' => 'KAMPUNGBANDAN VIA MRI', 'time_est' => '04:27:00', 'color' => '#0084D8', 'dest_time' => '04:45:00'],
        ['train_id' => '1802', 'ka_name' => 'COMMUTER LINE RANGKASBITUNG', 'route_name' => 'TANAHABANG-RANGKASBITUNG', 'dest' => 'RANGKASBITUNG', 'time_est' => '05:10:00', 'color' => '#16812B', 'dest_time' => '07:05:00'],
        ['train_id' => '1804', 'ka_name' => 'COMMUTER LINE RANGKASBITUNG', 'route_name' => 'TANAHABANG-RANGKASBITUNG', 'dest' => 'RANGKASBITUNG', 'time_est' => '05:40:00', 'color' => '#16812B', 'dest_time' => '07:35:00'],
        ['train_id' => '1800A', 'ka_name' => 'COMMUTER LINE RANGKASBITUNG', 'route_name' => 'TANAHABANG-SERPONG', 'dest' => 'SERPONG', 'time_est' => '21:30:00', 'color' => '#93ca3e', 'dest_time' => '22:05:00'],
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(KciClient::class, new FakeKciClient);
        Http::preventStrayRequests();

        foreach ([['THB', 'Tanah Abang'], ['SUD', 'Sudirman'], ['KPB', 'Kampung Bandan'], ['RK', 'Rangkasbitung']] as [$code, $name]) {
            Station::create(['code' => $code, 'name' => $name, 'slug' => strtolower($code)]);
        }
    }

    private function sync(array $stations = ['THB']): SyncLog
    {
        $service = $this->app->make(ScheduleSyncService::class);

        return $service->run($service->createLog('console'), now(), 7, $stations);
    }

    public function test_url_station_id_is_replaced_per_station(): void
    {
        $this->assertSame(
            'https://x.test/schedules?stationid=SUD&timefrom=00%3A00',
            ScheduleSyncService::urlForStation('https://x.test/schedules?stationid=THB&timefrom=00%3A00', 'SUD'),
        );
        $this->assertSame('https://x.test/s/BKS', ScheduleSyncService::urlForStation('https://x.test/s/{station}', 'BKS'));
    }

    public function test_sync_stores_exactly_what_the_api_returns_for_today_only(): void
    {
        Setting::put(Setting::SCHEDULES_API_URL, self::URL);
        Http::fake(['schedules.example.test/*' => Http::response(self::PAYLOAD)]);

        $log = $this->sync();

        $this->assertSame(SyncStatus::Success, $log->status);
        $this->assertSame('http', $log->source);
        $this->assertSame(1, $log->meta['days'], 'undated timetable is stored for one day, not copied to 7');
        $this->assertSame(5, Schedule::count());
        $this->assertSame([now()->toDateString()], Schedule::distinct()->pluck('service_date')->map->toDateString()->all());

        $this->fromFrontend()->getJson('/api/v1/stations/THB/schedules?date='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.train_number', '5198C')
            ->assertJsonPath('data.0.departure_time', '00:01')
            ->assertJsonPath('data.0.destination_arrival_time', '01:04')
            ->assertJsonPath('data.0.route_name', 'ANGKE-CIKARANG')
            ->assertJsonPath('data.1.destination', 'Kampung Bandan via MRI')
            ->assertJsonPath('data.2.line.color', '#16812B')
            // Per-train colour as published, while the line keeps its dominant colour.
            ->assertJsonPath('data.4.train_number', '1800A')
            ->assertJsonPath('data.4.color', '#93ca3e')
            ->assertJsonPath('data.4.line.color', '#16812B');
    }

    public function test_each_station_gets_its_own_request(): void
    {
        Setting::put(Setting::SCHEDULES_API_URL, self::URL);
        Http::fake(['schedules.example.test/*' => Http::response(self::PAYLOAD)]);

        $this->sync(['THB', 'SUD']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'stationid=THB&timefrom=00%3A00&timeto=23%3A00'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stationid=SUD&'));
        Http::assertSentCount(2);
    }

    public function test_blocked_url_marks_sync_failed_and_keeps_existing_schedules(): void
    {
        Setting::put(Setting::SCHEDULES_API_URL, self::URL);
        Http::fake(['schedules.example.test/*' => Http::sequence()
            ->push(self::PAYLOAD)
            ->push('<html>Attention Required! | Cloudflare</html>', 403, ['Server' => 'cloudflare'])]);

        $this->sync();
        $log = $this->sync();

        $this->assertSame(SyncStatus::Failed, $log->status);
        $this->assertStringContainsString('HTTP 403', $log->error_message);
        $this->assertSame(5, Schedule::count());
    }

    public function test_empty_url_uses_the_kci_client(): void
    {
        Setting::put(Setting::SCHEDULES_API_URL, '');
        Station::create(['code' => 'BKS', 'name' => 'Bekasi', 'slug' => 'bekasi']);

        $log = $this->sync(['BKS']);

        $this->assertSame('fake', $log->source);
        $this->assertSame(7, $log->meta['days']); // KCI client path keeps KCI_SYNC_DAYS
        $this->assertSame(2 * 7, Schedule::count()); // 2 BKS trains x 7 days
        Http::assertNothingSent();
    }

    public function test_admin_can_configure_and_test_the_url(): void
    {
        config(['kci.schedules_api_url' => self::URL]);
        $admin = User::factory()->admin()->create();
        Http::fake(['schedules.example.test/*' => Http::response(self::PAYLOAD)]);

        $this->actingAs($admin, 'admin')->fromFrontend()
            ->getJson('/api/v1/admin/settings/schedules-api')
            ->assertOk()
            ->assertJsonPath('data.url', self::URL)
            ->assertJsonPath('data.is_default', true);

        $this->fromFrontend()
            ->putJson('/api/v1/admin/settings/schedules-api', ['url' => 'https://schedules.example.test/api/krl/schedules'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->fromFrontend()
            ->putJson('/api/v1/admin/settings/schedules-api', ['url' => 'https://schedules.example.test/s/{station}'])
            ->assertOk()
            ->assertJsonPath('data.is_default', false);

        $this->fromFrontend()
            ->postJson('/api/v1/admin/settings/schedules-api/test', ['url' => self::URL, 'station' => 'sud'])
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.count', 5)
            ->assertJsonPath('data.first', '00:01')
            ->assertJsonPath('data.last', '21:30')
            ->assertJsonPath('data.url', 'https://schedules.example.test/api/krl/schedules?stationid=SUD&timefrom=00%3A00&timeto=23%3A00')
            ->assertJsonPath('data.lines', ['COMMUTER LINE CIKARANG', 'COMMUTER LINE RANGKASBITUNG']);

        $this->assertSame(0, Schedule::count());

        $this->fromFrontend()->getJson('/api/v1/admin/sync-logs')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['in_progress', 'last_schedule_sync', 'last_successful_schedule_sync', 'next_schedule_sync']]);
    }
}
