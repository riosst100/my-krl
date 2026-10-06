<?php

namespace Tests\Feature;

use App\Models\KciTimetableCheck;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use App\Services\Kci\Contracts\KciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeKciClient;
use Tests\TestCase;

class KciTimetableWatchTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://kci.example.test/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A59';

    private function payload(array $trains): array
    {
        return ['status' => 200, 'data' => array_map(fn (array $t) => [
            'train_id' => $t[0], 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'ANGKE-CIKARANG',
            'dest' => 'CIKARANG', 'time_est' => "{$t[1]}:00", 'color' => '#0084D8', 'dest_time' => '23:59:00',
        ], $trains)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(KciClient::class, new FakeKciClient);
        Http::preventStrayRequests();
        Setting::put(Setting::SCHEDULES_API_URL, self::URL);
        config(['kci.watch_station' => 'THB']);
    }

    public function test_detects_when_kci_changes_its_timetable(): void
    {
        $saturday = $this->payload([['5001', '05:00'], ['5003', '05:20'], ['5005', '05:40']]);
        $sunday = $this->payload([['5001', '05:10'], ['5005', '05:40']]);
        Http::fake(['kci.example.test/*' => Http::sequence()->push($saturday)->push($saturday)->push($sunday)]);

        $this->travelTo(now()->setTime(22, 45));
        $this->artisan('kci:watch-timetable')->assertSuccessful();
        $this->travelTo(now()->setTime(23, 0));
        $this->artisan('kci:watch-timetable')->assertSuccessful();
        $this->travelTo(now()->setTime(23, 15));
        $this->artisan('kci:watch-timetable')->expectsOutputToContain('CHANGED')->assertSuccessful();

        $checks = KciTimetableCheck::orderBy('id')->get();
        $this->assertSame([false, false, true], $checks->pluck('changed')->all());

        $change = $checks->last();
        $this->assertSame('23:15', $change->checked_at->format('H:i'));
        $this->assertSame(['added' => 1, 'removed' => 2, 'trains_before' => 3, 'trains_after' => 2], array_intersect_key(
            $change->diff['summary'], array_flip(['added', 'removed', 'trains_before', 'trains_after'])
        ));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->fromFrontend()
            ->getJson('/api/v1/admin/kci-watch')
            ->assertOk()
            ->assertJsonPath('data.station', 'THB')
            ->assertJsonPath('data.checks', 3)
            ->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.summary.trains_after', 2)
            ->assertJsonPath('data.last_check.changed', true);
    }

    public function test_failed_checks_are_recorded_and_do_not_count_as_changes(): void
    {
        Http::fake(['kci.example.test/*' => Http::sequence()
            ->push($this->payload([['5001', '05:00']]))
            ->push('blocked', 403, ['Server' => 'cloudflare'])
            ->push($this->payload([['5001', '05:00']]))]);

        $this->artisan('kci:watch-timetable')->assertSuccessful();
        $this->artisan('kci:watch-timetable')->assertFailed();
        $this->travel(config('kci.block_cooldown_minutes') + 1)->minutes(); // KCI requests pause after a block
        $this->artisan('kci:watch-timetable')->assertSuccessful();

        $this->assertSame([true, false, true], KciTimetableCheck::orderBy('id')->pluck('ok')->all());
        $this->assertSame(0, KciTimetableCheck::where('changed', true)->count());
    }

    public function test_scheduled_sync_can_store_the_next_day(): void
    {
        Station::create(['code' => 'THB', 'name' => 'Tanah Abang', 'slug' => 'tanah-abang']);
        Setting::put(Setting::TRAIN_STOPS_API_URL, '');
        Http::fake(['kci.example.test/*' => Http::response($this->payload([['5001', '05:00']]))]);

        $this->artisan('kci:sync-schedules', ['--station' => ['THB'], '--day-offset' => 1])->assertSuccessful();

        $this->assertSame([now()->addDay()->toDateString()], Schedule::pluck('service_date')->map->toDateString()->all());
    }
}
