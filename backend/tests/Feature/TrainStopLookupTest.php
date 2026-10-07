<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Station;
use App\Models\TrainStop;
use App\Services\ScheduleCarryForwardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A train's stops are fetched on first view through krl-sync (Vercel), then served from the database.
 */
class TrainStopLookupTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = 'https://krl-sync.example.test/api/sync';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kci.stops_proxy_url' => self::PROXY, 'kci.ingest_token' => 'secret-ingest-token']);

        foreach ([['THB', 'Tanah Abang'], ['SUD', 'Sudirman'], ['MRI', 'Manggarai']] as [$code, $name]) {
            Station::create(['code' => $code, 'name' => $name, 'slug' => strtolower($code)]);
        }
        Schedule::create([
            'station_id' => Station::where('code', 'THB')->value('id'), 'train_number' => '5701C',
            'destination' => 'Manggarai', 'departure_time' => '06:00:00', 'service_date' => now()->toDateString(),
        ]);
    }

    private const STOPS = ['train_number' => '5701C', 'stops' => [
        ['station_code' => 'THB', 'time' => '06:00:00', 'is_transit' => false],
        ['station_code' => 'SUD', 'time' => '06:05:00', 'is_transit' => true],
        ['station_code' => 'MRI', 'time' => '06:12:00', 'is_transit' => false],
    ]];

    private function fakeProxy(): void
    {
        Http::fake([self::PROXY.'*' => Http::response(self::STOPS)]);
    }

    public function test_first_view_fetches_through_krl_sync_and_later_views_use_the_database(): void
    {
        $this->fakeProxy();

        $this->getJson('/api/v1/schedules/trains/5701C/stops')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.station.name', 'Sudirman')
            ->assertJsonPath('meta.carried_forward', false);
        $this->getJson('/api/v1/schedules/trains/5701C/stops')->assertOk()->assertJsonCount(3, 'data');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::PROXY.'?train=5701C'
            && $r->hasHeader('Authorization', 'Bearer secret-ingest-token'));
        $this->assertSame(3, TrainStop::whereDate('service_date', now()->toDateString())->count());
    }

    public function test_carried_forward_stops_are_refreshed_and_kept_when_the_fetch_fails(): void
    {
        TrainStop::create(['service_date' => now()->subDay()->toDateString(), 'train_number' => '5701C', 'sequence' => 1, 'station_code' => 'THB', 'time' => '06:01:00']);
        TrainStop::create(['service_date' => now()->subDay()->toDateString(), 'train_number' => '5701C', 'sequence' => 2, 'station_code' => 'MRI', 'time' => '06:15:00']);
        app(ScheduleCarryForwardService::class)->carryForward(now());

        // First view: krl-sync down, yesterday's stops (carried to today) are shown instead.
        Http::fake([self::PROXY.'*' => Http::sequence()->push(['error' => 'blocked'], 503)->push(self::STOPS)]);
        $this->getJson('/api/v1/schedules/trains/5701C/stops')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.carried_forward', true);

        // Next view: fetched for today, replacing the carried copy and the older day.
        $this->getJson('/api/v1/schedules/trains/5701C/stops')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.carried_forward', false);
        $this->assertSame(3, TrainStop::where('train_number', '5701C')->count());
    }

    public function test_no_stops_anywhere_is_still_a_404(): void
    {
        Http::fake([self::PROXY.'*' => Http::response(['error' => 'not found'], 502)]);

        $this->getJson('/api/v1/schedules/trains/9999/stops')->assertNotFound();
    }
}
