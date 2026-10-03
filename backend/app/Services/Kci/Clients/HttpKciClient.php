<?php

namespace App\Services\Kci\Clients;

use App\Services\Kci\Contracts\KciClient;
use App\Services\Kci\Exceptions\KciApiException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the KCI "krl-webs" style API configured through KCI_API_URL.
 * The upstream timetable is not date specific: every request returns the
 * current timetable for a station.
 */
class HttpKciClient implements KciClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
        private readonly int $retries,
        private readonly array $endpoints,
    ) {}

    public function fetchStations(): array
    {
        return $this->get($this->endpoints['stations']);
    }

    public function fetchStationSchedules(string $stationCode, CarbonInterface $date): array
    {
        return $this->get($this->endpoints['schedules'], [
            'stationid' => $stationCode,
            'timefrom' => '00:00',
            'timeto' => '23:59',
        ]);
    }

    public function isDateSpecific(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'http';
    }

    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get($path, $query)->throw();
        } catch (ConnectionException $e) {
            throw KciApiException::unavailable($e->getMessage());
        } catch (RequestException $e) {
            throw KciApiException::unavailable("HTTP {$e->response->status()} for {$path}");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw KciApiException::invalidResponse("non-JSON body for {$path}");
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry($this->retries + 1, 500, throw: true);

        if ($this->apiKey) {
            $request->withToken($this->apiKey);
        }

        return $request;
    }
}
