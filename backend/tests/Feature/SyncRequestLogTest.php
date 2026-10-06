<?php

namespace Tests\Feature;

use App\Models\SyncLog;
use App\Models\SyncLogRequest;
use App\Models\User;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Exceptions\KciApiException;
use App\Services\Kci\KciRequestLog;
use App\Services\ScheduleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncRequestLogTest extends TestCase
{
    use RefreshDatabase;

    private SyncLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
        $this->log = app(ScheduleSyncService::class)->createLog('manual');
    }

    public function test_requests_are_recorded_with_outcome_only_while_a_run_is_open(): void
    {
        Http::fake([
            'kci.example.test/ok' => Http::response(['status' => 200, 'data' => [['a' => 1], ['a' => 2]]]),
            'kci.example.test/blocked' => Http::response('<title>Attention Required! | Cloudflare</title>', 403, ['Server' => 'cloudflare', 'cf-ray' => 'abc-FRA']),
        ]);
        $client = app(KciUrlClient::class);

        $client->fetch('https://kci.example.test/ok'); // no run open: not recorded

        app(KciRequestLog::class)->begin($this->log);
        $client->fetch('https://kci.example.test/ok');

        try {
            $client->fetch('https://kci.example.test/blocked');
        } catch (KciApiException) {
        }
        app(KciRequestLog::class)->end();

        $rows = SyncLogRequest::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['https://kci.example.test/ok', true, 200, 'OK · 2 data'], [$rows[0]->url, $rows[0]->ok, $rows[0]->status_code, $rows[0]->message]);
        $this->assertSame([false, 403, 'Diblokir Cloudflare (cf-ray abc-FRA)'], [$rows[1]->ok, $rows[1]->status_code, $rows[1]->message]);
    }

    public function test_admin_can_poll_the_request_log(): void
    {
        foreach ([true, false, true] as $i => $ok) {
            SyncLogRequest::create(['sync_log_id' => $this->log->id, 'url' => "https://kci.example.test/{$i}", 'ok' => $ok, 'status_code' => $ok ? 200 : 403, 'created_at' => now()]);
        }
        $first = SyncLogRequest::min('id');

        $this->actingAs(User::factory()->admin()->create(), 'admin')->fromFrontend()
            ->getJson("/api/v1/admin/sync-logs/{$this->log->id}/requests?after={$first}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.url', 'https://kci.example.test/1')
            ->assertJsonPath('data.0.ok', false)
            ->assertJsonPath('meta', ['total' => 3, 'ok' => 2, 'failed' => 1]);
    }
}
