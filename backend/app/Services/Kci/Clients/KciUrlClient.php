<?php

namespace App\Services\Kci\Clients;

use App\Services\Kci\Exceptions\KciApiException;
use App\Services\Kci\Exceptions\KciBlockedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * GETs JSON from a full, admin-configurable URL (Stations API URL,
 * Schedules API URL, Train Stops API URL — default https://www.kci.id/api/krl/...).
 *
 * The KCI web API is rate limited (x-ratelimit-limit: 60): HTTP 429 responses
 * are retried after waiting, and batches pause when the remaining quota is low.
 *
 * Cloudflare in front of kci.id rejects PHP's TLS fingerprint, so when
 * KCI_FETCH_PROXY_URL is set every request goes through the kci-fetch sidecar
 * (docker/kci-fetch, curl_cffi with a browser fingerprint). It passes the
 * upstream status, body and rate-limit headers through unchanged.
 *
 * Once KCI blocks this server, requests stop for kci.block_cooldown_minutes
 * (KciBlockedException without touching the network): hammering a block only
 * makes it last longer.
 */
class KciUrlClient
{
    private const MAX_RATE_LIMIT_RETRIES = 2;

    public const BLOCKED_UNTIL_KEY = 'kci:blocked-until';

    /**
     * When requests to KCI are paused after a block, else null.
     */
    public static function blockedUntil(): ?Carbon
    {
        $until = Cache::get(self::BLOCKED_UNTIL_KEY);

        return $until && Carbon::parse($until)->isFuture() ? Carbon::parse($until) : null;
    }

    /**
     * @throws KciBlockedException
     */
    private function ensureNotBlocked(): void
    {
        if ($until = self::blockedUntil()) {
            throw KciBlockedException::coolingDown($until);
        }
    }

    private function markBlocked(): void
    {
        $minutes = (int) config('kci.block_cooldown_minutes');

        if ($minutes > 0) {
            Cache::put(self::BLOCKED_UNTIL_KEY, now()->addMinutes($minutes)->toIso8601String(), now()->addMinutes($minutes));
        }
    }

    public function fetch(string $url, ?string $token = null): array
    {
        $this->ensureNotBlocked();

        $request = $this->configure(Http::createPendingRequest(), $token)
            ->retry(config('kci.retries') + 1, 500, fn ($e) => $e instanceof ConnectionException, throw: false);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $request->get($this->target($url));
            } catch (ConnectionException $e) {
                throw KciApiException::unavailable("cannot connect to {$url}: {$e->getMessage()}");
            }

            if ($response->status() === 429 && $attempt >= self::MAX_RATE_LIMIT_RETRIES) {
                // Still rate limited after waiting: stop instead of queueing more requests.
                $this->markBlocked();

                throw KciBlockedException::unavailable("HTTP 429 from {$url} (rate limited by the provider: too many requests, try again later)");
            }

            if ($response->status() !== 429) {
                return $this->decode($response, $url);
            }

            $this->waitForRateLimit($response, $url);
        }
    }

    /**
     * Fetches many URLs with at most $concurrency requests in flight. Each
     * result is the decoded JSON array or the KciApiException for that URL.
     *
     * @param  array<string, string>  $urls  key => url
     * @return array<string, array|KciApiException>
     */
    public function fetchMany(array $urls, ?string $token = null, int $concurrency = 5): array
    {
        $results = [];
        $delay = (int) config('kci.request_delay_ms');
        $first = true;

        foreach (array_chunk($urls, max(1, $concurrency), preserve_keys: true) as $batch) {
            $this->ensureNotBlocked();
            if (! $first && $delay > 0) {
                Sleep::for($delay)->milliseconds();
            }
            $first = false;

            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $key) => $this->configure($pool->as($key), $token)->get($this->target($batch[$key])),
                array_keys($batch),
            ));

            $remaining = null;
            $limited = null;

            foreach ($batch as $key => $url) {
                $response = $responses[$key] ?? null;
                if ($response instanceof Response && $response->status() === 429) {
                    $limited = $response;
                }

                try {
                    if (! $response instanceof Response) {
                        $reason = $response instanceof Throwable ? $response->getMessage() : 'no response';
                        throw KciApiException::unavailable("cannot connect to {$url}: {$reason}");
                    }

                    $header = $response->header('X-RateLimit-Remaining');
                    if (is_numeric($header)) {
                        $remaining = $remaining === null ? (int) $header : min($remaining, (int) $header);
                    }

                    $results[$key] = $this->decode($response, $url);
                } catch (KciBlockedException $e) {
                    throw $e;
                } catch (KciApiException $e) {
                    $results[$key] = $e;
                }
            }

            if ($limited) {
                // These are retried one by one by the caller; give the quota time to refill first.
                $this->waitForRateLimit($limited, (string) $limited->effectiveUri());
            } elseif ($remaining !== null && $remaining < $concurrency) {
                Log::info('KCI rate limit almost reached, pausing', ['remaining' => $remaining]);
                Sleep::for(config('kci.rate_limit_pause_seconds'))->seconds();
            }
        }

        return $results;
    }

    /**
     * The URL actually requested: through the kci-fetch sidecar when one is configured.
     */
    public function target(string $url): string
    {
        $proxy = rtrim((string) config('kci.fetch_proxy_url'), '/');

        return $proxy !== '' ? $proxy.'/fetch?url='.rawurlencode($url) : $url;
    }

    private function waitForRateLimit(Response $response, string $url): void
    {
        $retryAfter = $response->header('Retry-After');
        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : (int) config('kci.rate_limit_pause_seconds');
        $seconds = max(1, min($seconds, 60));

        Log::info('KCI rate limited (HTTP 429), waiting before retry', ['url' => $url, 'seconds' => $seconds]);
        Sleep::for($seconds)->seconds();
    }

    private function configure(PendingRequest $request, ?string $token): PendingRequest
    {
        $request->acceptJson()
            ->withUserAgent(config('kci.user_agent'))
            ->timeout(config('kci.timeout'));

        return $token ? $request->withToken($token) : $request;
    }

    /**
     * @throws KciApiException
     */
    private function decode(Response $response, string $url): array
    {
        // Answered by the kci-fetch sidecar itself (Cloudflare block on every browser profile, KCI unreachable, URL not allowed).
        if ($response->header('X-Kci-Fetch-Error') !== '') {
            $reason = (string) ($response->json('message') ?? $response->header('X-Kci-Fetch-Error'));
            Log::warning('KCI request failed in kci-fetch', ['url' => $url, 'error' => $response->header('X-Kci-Fetch-Error'), 'status' => $response->json('status'), 'cf_ray' => $response->json('cf_ray')]);

            if (in_array($response->json('status'), [403, 429, 503], true)) {
                $this->markBlocked();

                throw KciBlockedException::unavailable("{$url}: {$reason}");
            }

            throw KciApiException::unavailable("{$url}: {$reason}");
        }

        if ($this->isCloudflareBlock($response)) {
            Log::warning('KCI request blocked by Cloudflare', ['url' => $url, 'status' => $response->status(), 'cf_ray' => $response->header('cf-ray')]);
            $this->markBlocked();

            throw KciBlockedException::unavailable("HTTP {$response->status()} from {$url} (blocked by Cloudflare: the provider does not allow automated requests from this client"
                .(config('kci.fetch_proxy_url') ? '' : '; set KCI_FETCH_PROXY_URL to the kci-fetch service').', cf-ray '.($response->header('cf-ray') ?: '-').')');
        }

        if ($response->failed()) {
            $hint = match (true) {
                $response->status() === 429 => ' (rate limited by the provider: too many requests, try again in a minute)',
                default => '',
            };

            throw KciApiException::unavailable("HTTP {$response->status()} from {$url}{$hint}");
        }

        $json = $response->json();

        if (! is_array($json)) {
            $type = $response->header('Content-Type') ?: 'unknown content type';
            throw KciApiException::invalidResponse("{$url} did not return JSON ({$type})");
        }

        return $json;
    }

    /**
     * A Cloudflare challenge/block page instead of the API response: never parse that HTML as JSON.
     */
    private function isCloudflareBlock(Response $response): bool
    {
        if ($response->header('cf-mitigated') !== '') {
            return true;
        }

        if (! in_array($response->status(), [403, 503], true) && ! str_contains($response->header('Content-Type'), 'html')) {
            return false;
        }

        $head = substr($response->body(), 0, 4000);

        return str_contains($head, 'Attention Required') || str_contains($head, 'Just a moment')
            || (in_array($response->status(), [403, 503], true) && str_contains((string) $response->header('Server'), 'cloudflare'));
    }
}
