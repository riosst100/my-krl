<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Jobs\SyncKciJob;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\AutoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Automatic sync at configurable times of day, and the manual "Sync dari KCI".
 */
class AutoSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = ['status' => 200, 'data' => [
        ['train_id' => '5198C', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'ANGKE-CIKARANG', 'dest' => 'CIKARANG', 'time_est' => '06:01:00', 'color' => '#0084D8', 'dest_time' => '07:04:00'],
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        config(['kci.auto_sync_grace_minutes' => 10]);
        Station::create(['code' => 'THB', 'name' => 'Tanah Abang', 'slug' => 'thb']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->admin()->create(), 'admin')->fromFrontend();
    }

    private function useKciUrls(): void
    {
        Setting::put(Setting::SCHEDULES_API_URL, 'https://www.kci.id/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A59');
        Setting::put(Setting::TRAIN_STOPS_API_URL, '');
        Setting::put(Setting::SYNC_STATIONS, 'THB');
    }

    public function test_admin_configures_several_sync_times(): void
    {
        $this->admin()->getJson('/api/v1/admin/settings/auto-sync')
            ->assertOk()
            ->assertJsonPath('data.times', [])
            ->assertJsonPath('data.next_run_at', null);

        Carbon::setTestNow('2026-10-06 10:00:00');

        $this->putJson('/api/v1/admin/settings/auto-sync', ['times' => ['22:15', '04:00', '04:00', '00:30']])
            ->assertOk()
            ->assertJsonPath('data.times', ['00:30', '04:00', '22:15'])
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.next_run_at', Carbon::parse('2026-10-06 22:15')->toIso8601String());

        $this->putJson('/api/v1/admin/settings/auto-sync', ['times' => ['25:00']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('times.0');

        // An empty list switches it off; reset goes back to KCI_AUTO_SYNC_TIMES.
        $this->putJson('/api/v1/admin/settings/auto-sync', ['times' => []])->assertOk()->assertJsonPath('data.times', []);

        config(['kci.auto_sync_times' => '05:00, 4:30']);
        $this->deleteJson('/api/v1/admin/settings/auto-sync')->assertOk()
            ->assertJsonPath('data.times', ['04:30', '05:00'])
            ->assertJsonPath('data.is_default', true);
    }

    public function test_the_sync_starts_once_per_configured_time(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-06 03:00:00');
        Setting::put(Setting::AUTO_SYNC_TIMES, '04:00,16:30');

        $auto = app(AutoSyncService::class);

        Carbon::setTestNow('2026-10-06 03:59:00');
        $this->assertNull($auto->runDue());

        Carbon::setTestNow('2026-10-06 04:00:20');
        $log = $auto->runDue();
        $this->assertNotNull($log);
        $this->assertSame('schedule', $log->trigger);
        $this->assertSame(SyncLog::TYPE_KCI_SCHEDULES, $log->type);
        Queue::assertPushed(SyncKciJob::class, fn ($job) => $job->syncLogId === $log->id);

        // Same slot again (the queued run is finished meanwhile): not started twice.
        $log->update(['status' => SyncStatus::Success]);
        Carbon::setTestNow('2026-10-06 04:01:00');
        $this->assertNull($auto->runDue());

        // A missed minute (scheduler restarted) still runs within the grace period...
        Carbon::setTestNow('2026-10-06 16:38:00');
        $this->assertNotNull($auto->runDue()?->update(['status' => SyncStatus::Success]));

        // ...but not after it.
        Carbon::setTestNow('2026-10-07 04:11:00');
        $this->assertNull($auto->runDue());

        Queue::assertPushed(SyncKciJob::class, 2);
    }

    public function test_a_newly_added_time_that_already_passed_does_not_run_immediately(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-06 04:05:00');
        Setting::put(Setting::AUTO_SYNC_TIMES, '04:00');

        $this->assertNull(app(AutoSyncService::class)->runDue());
        Queue::assertNothingPushed();
    }

    public function test_a_due_run_is_skipped_while_another_sync_is_running(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-06 03:00:00');
        Setting::put(Setting::AUTO_SYNC_TIMES, '04:00');

        Carbon::setTestNow('2026-10-06 04:00:00');
        SyncLog::create(['type' => SyncLog::TYPE_KCI_SCHEDULES, 'status' => SyncStatus::Running, 'trigger' => 'manual', 'source' => 'http']);
        $this->assertNull(app(AutoSyncService::class)->runDue());
        Queue::assertNothingPushed();
    }

    public function test_the_scheduler_command_queues_the_sync_at_the_configured_time(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-06 03:00:00');
        Setting::put(Setting::AUTO_SYNC_TIMES, '04:00');

        $this->artisan('kci:auto-sync')->assertSuccessful();
        Queue::assertNothingPushed();

        Carbon::setTestNow('2026-10-06 04:00:00');
        $this->artisan('kci:auto-sync')->assertSuccessful();

        $log = SyncLog::sole();
        $this->assertSame(SyncLog::TYPE_KCI_SCHEDULES, $log->type);
        $this->assertSame('schedule', $log->trigger);
        Queue::assertPushed(SyncKciJob::class, fn ($job) => $job->syncLogId === $log->id);

        $this->admin()->getJson('/api/v1/admin/settings/auto-sync')
            ->assertOk()
            ->assertJsonPath('data.last_run.id', $log->id)
            ->assertJsonPath('data.scheduler_running', true);
    }

    public function test_admin_syncs_from_kci_with_progress(): void
    {
        $this->useKciUrls();
        Http::preventStrayRequests();
        Http::fake(['www.kci.id/*' => Http::response(self::PAYLOAD)]);

        $this->admin()->postJson('/api/v1/admin/sync/kci')
            ->assertAccepted()
            ->assertJsonPath('data.type', SyncLog::TYPE_KCI_SCHEDULES)
            ->assertJsonPath('data.trigger', 'manual');

        // QUEUE_CONNECTION=sync in tests, so the job already ran.
        $log = SyncLog::sole();
        $this->assertSame(SyncStatus::Success, $log->status, (string) $log->error_message);
        $this->assertSame(100, $log->meta['progress']['percent']);
        $this->assertSame(1, Schedule::count());

        $this->getJson('/api/v1/admin/sync-logs')
            ->assertOk()
            ->assertJsonPath('meta.last_successful_sync.id', $log->id);
    }

    public function test_kci_requests_go_through_the_fetch_sidecar(): void
    {
        $this->useKciUrls();
        config(['kci.fetch_proxy_url' => 'http://kci-fetch:8080']);
        Http::preventStrayRequests();
        Http::fake(['kci-fetch:8080/fetch*' => Http::response(self::PAYLOAD)]);

        $this->admin()->postJson('/api/v1/admin/sync/kci')->assertAccepted();

        $this->assertSame(SyncStatus::Success, SyncLog::sole()->status);
        Http::assertSent(fn ($r) => $r->url() === 'http://kci-fetch:8080/fetch?url='.rawurlencode('https://www.kci.id/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A59'));
    }

    public function test_a_cloudflare_block_fails_with_a_clear_message_instead_of_parsing_html(): void
    {
        $this->useKciUrls();
        Http::preventStrayRequests();
        Http::fake(['www.kci.id/*' => Http::response('<html><title>Attention Required! | Cloudflare</title></html>', 403, ['Content-Type' => 'text/html', 'Server' => 'cloudflare', 'cf-ray' => 'abc123-CGK'])]);

        $this->admin()->postJson('/api/v1/admin/sync/kci')->assertAccepted();

        $log = SyncLog::sole();
        $this->assertSame(SyncStatus::Failed, $log->status);
        $this->assertStringContainsString('blocked by Cloudflare', $log->error_message);
        $this->assertStringContainsString('abc123-CGK', $log->error_message);
    }

    public function test_a_block_reported_by_the_sidecar_is_explained(): void
    {
        $this->useKciUrls();
        config(['kci.fetch_proxy_url' => 'http://kci-fetch:8080']);
        Http::preventStrayRequests();
        Http::fake(['kci-fetch:8080/*' => Http::response(
            ['error' => 'cloudflare_blocked', 'message' => 'blocked by Cloudflare (HTTP 403, impersonate=firefox, cf-ray=xyz)', 'status' => 403, 'cf_ray' => 'xyz'],
            502,
            ['X-Kci-Fetch-Error' => 'cloudflare_blocked'],
        )]);

        $this->admin()->postJson('/api/v1/admin/sync/kci')->assertAccepted();

        $log = SyncLog::sole();
        $this->assertSame(SyncStatus::Failed, $log->status);
        $this->assertStringContainsString('impersonate=firefox, cf-ray=xyz', $log->error_message);
    }
}
