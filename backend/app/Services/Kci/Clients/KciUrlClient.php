<?php

namespace App\Services\Kci\Clients;

use App\Services\Kci\Exceptions\KciApiException;
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
 */
class KciUrlClient
{
    private const MAX_RATE_LIMIT_RETRIES = 2;

    public function fetch(string $url, ?string $token = null): array
    {
        $request = $this->configure(Http::createPendingRequest(), $token)
            ->retry(config('kci.retries') + 1, 500, fn ($e) => $e instanceof ConnectionException, throw: false);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $request->get($url);
            } catch (ConnectionException $e) {
                throw KciApiException::unavailable("cannot connect to {$url}: {$e->getMessage()}");
            }

            if ($response->status() !== 429 || $attempt >= self::MAX_RATE_LIMIT_RETRIES) {
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

        foreach (array_chunk($urls, max(1, $concurrency), preserve_keys: true) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $key) => $this->configure($pool->as($key), $token)->get($batch[$key]),
                array_keys($batch),
            ));

            $remaining = null;

            foreach ($batch as $key => $url) {
                $response = $responses[$key] ?? null;

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
                } catch (KciApiException $e) {
                    $results[$key] = $e;
                }
            }

            // Pause before the next batch would run out of quota.
            if ($remaining !== null && $remaining < $concurrency) {
                Log::info('KCI rate limit almost reached, pausing', ['remaining' => $remaining]);
                Sleep::for(config('kci.rate_limit_pause_seconds'))->seconds();
            }
        }

        return $results;
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
        if ($response->failed()) {
            $hint = match (true) {
                $response->status() === 429 => ' (rate limited by the provider: too many requests, try again in a minute)',
                $response->status() === 403 && str_contains((string) $response->header('Server'), 'cloudflare') => ' (blocked by Cloudflare: the provider does not allow automated requests from this server)',
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
}
