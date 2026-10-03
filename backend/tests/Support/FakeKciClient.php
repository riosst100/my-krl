<?php

namespace Tests\Support;

use App\Services\Kci\Contracts\KciClient;
use App\Services\Kci\Exceptions\KciApiException;
use Carbon\CarbonInterface;

/**
 * Tiny, configurable KCI client for tests.
 */
class FakeKciClient implements KciClient
{
    public array $stations = [
        ['sta_id' => 'BKS', 'sta_name' => 'BEKASI', 'fg_enable' => 1],
        ['sta_id' => 'MRI', 'sta_name' => 'MANGGARAI', 'fg_enable' => 1],
        ['sta_id' => 'JAKK', 'sta_name' => 'JAKARTA KOTA', 'fg_enable' => 1],
    ];

    /** @var array<string, list<array<string, mixed>>> */
    public array $schedules = [
        'BKS' => [
            ['train_id' => '5001', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'CIKARANG-KAMPUNGBANDAN', 'dest' => 'KAMPUNGBANDAN', 'time_est' => '05:21:00', 'color' => '#0084D8', 'dest_time' => '06:08:00'],
            ['train_id' => '5003', 'ka_name' => 'COMMUTER LINE CIKARANG', 'route_name' => 'CIKARANG-KAMPUNGBANDAN', 'dest' => 'KAMPUNGBANDAN', 'time_est' => '05:41:00', 'color' => '#0084D8', 'dest_time' => '06:28:00'],
        ],
        'MRI' => [
            ['train_id' => '1001', 'ka_name' => 'COMMUTER LINE BOGOR', 'route_name' => 'JAKARTAKOTA-BOGOR', 'dest' => 'BOGOR', 'time_est' => '04:16:00', 'color' => '#E30A16', 'dest_time' => '05:06:00'],
            ['train_id' => '1002', 'ka_name' => 'COMMUTER LINE BOGOR', 'route_name' => 'BOGOR-JAKARTAKOTA', 'dest' => 'JAKARTAKOTA', 'time_est' => '04:57:00', 'color' => '#E30A16', 'dest_time' => '05:13:00'],
        ],
        'JAKK' => [],
    ];

    /** @var list<string> station codes whose schedule request fails */
    public array $failingStations = [];

    public bool $failStations = false;

    public int $stationFetches = 0;

    public function fetchStations(): array
    {
        $this->stationFetches++;

        if ($this->failStations) {
            throw KciApiException::unavailable('connection refused');
        }

        return ['status' => 200, 'data' => $this->stations];
    }

    public function fetchStationSchedules(string $stationCode, CarbonInterface $date): array
    {
        if (in_array($stationCode, $this->failingStations, true)) {
            throw KciApiException::unavailable("timeout for {$stationCode}");
        }

        return ['status' => 200, 'data' => $this->schedules[$stationCode] ?? []];
    }

    public function isDateSpecific(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'fake';
    }
}
