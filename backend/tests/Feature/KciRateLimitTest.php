<?php

namespace Tests\Feature;

use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Exceptions\KciApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class KciRateLimitTest extends TestCase
{
    private const URL = 'https://kci.example.test/api/krl/schedules?stationid=THB';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
    }

    public function test_429_is_retried_after_retry_after_seconds(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('Too Many Requests', 429, ['Retry-After' => '7'])
            ->push(['status' => 200, 'data' => []])]);

        $this->assertSame(['status' => 200, 'data' => []], app(KciUrlClient::class)->fetch(self::URL));

        Sleep::assertSequence([Sleep::for(7)->seconds()]);
        Http::assertSentCount(2);
    }

    public function test_429_without_retry_after_uses_configured_pause_and_gives_up_eventually(): void
    {
        config(['kci.rate_limit_pause_seconds' => 30]);
        Http::fake([self::URL => Http::response('Too Many Requests', 429)]);

        try {
            app(KciUrlClient::class)->fetch(self::URL);
            $this->fail('Expected KciApiException');
        } catch (KciApiException $e) {
            $this->assertStringContainsString('HTTP 429', $e->getMessage());
            $this->assertStringContainsString('rate limited', $e->getMessage());
        }

        Sleep::assertSequence([Sleep::for(30)->seconds(), Sleep::for(30)->seconds()]);
        Http::assertSentCount(3);
    }

    public function test_batches_pause_when_quota_is_nearly_used_up(): void
    {
        config(['kci.rate_limit_pause_seconds' => 30]);
        Http::fake(['kci.example.test/*' => Http::response(['data' => []], 200, ['X-RateLimit-Remaining' => '2'])]);

        $urls = ['a' => 'https://kci.example.test/1', 'b' => 'https://kci.example.test/2', 'c' => 'https://kci.example.test/3'];
        $results = app(KciUrlClient::class)->fetchMany($urls, concurrency: 3);

        $this->assertSame(['a', 'b', 'c'], array_keys($results));
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 30, 1);
    }
}
