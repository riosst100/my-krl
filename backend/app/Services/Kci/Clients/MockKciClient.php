<?php

namespace App\Services\Kci\Clients;

use App\Services\Kci\Contracts\KciClient;
use App\Services\Kci\Exceptions\KciApiException;
use Carbon\CarbonInterface;

/**
 * Deterministic, offline stand-in for the KCI API. It produces payloads with
 * the same shape as the real API so the rest of the application cannot tell
 * the difference. Timetables are generated, not real.
 */
class MockKciClient implements KciClient
{
    /**
     * Lines with their ordered stations and minutes from the first station.
     * Each pattern is a [from, to] slice of the station list served by a trip.
     */
    private const LINES = [
        [
            'name' => 'COMMUTER LINE BOGOR',
            'color' => '#E30A16',
            'number_base' => 1000,
            'headway' => [6, 10, 15], // peak, off-peak, weekend (minutes)
            'stations' => [
                ['JAKK', 'JAKARTA KOTA', 0], ['JAY', 'JAYAKARTA', 2], ['MGB', 'MANGGA BESAR', 4],
                ['SW', 'SAWAH BESAR', 6], ['JUA', 'JUANDA', 8], ['GDD', 'GONDANGDIA', 11],
                ['CKI', 'CIKINI', 13], ['MRI', 'MANGGARAI', 16], ['TEB', 'TEBET', 19],
                ['CW', 'CAWANG', 21], ['DRN', 'DUREN KALIBATA', 24], ['PSMB', 'PASAR MINGGU BARU', 26],
                ['PSM', 'PASAR MINGGU', 29], ['TNT', 'TANJUNG BARAT', 31], ['LNA', 'LENTENG AGUNG', 34],
                ['UP', 'UNIVERSITAS PANCASILA', 36], ['UI', 'UNIVERSITAS INDONESIA', 38], ['POC', 'PONDOK CINA', 40],
                ['DPB', 'DEPOK BARU', 43], ['DP', 'DEPOK', 46], ['CTA', 'CITAYAM', 51],
                ['BJD', 'BOJONG GEDE', 55], ['CLT', 'CILEBUT', 60], ['BOO', 'BOGOR', 66],
            ],
            'patterns' => [[0, 23], [0, 19]],
        ],
        [
            'name' => 'COMMUTER LINE CIKARANG',
            'color' => '#0084D8',
            'number_base' => 5000,
            'headway' => [10, 15, 20],
            'stations' => [
                ['CKR', 'CIKARANG', 0], ['TLM', 'METLAND TELAGAMURNI', 4], ['CIT', 'CIBITUNG', 8],
                ['TB', 'TAMBUN', 13], ['BKST', 'BEKASI TIMUR', 17], ['BKS', 'BEKASI', 21],
                ['KRI', 'KRANJI', 25], ['CUK', 'CAKUNG', 29], ['KLDB', 'KLENDER BARU', 32],
                ['BUA', 'BUARAN', 34], ['KLD', 'KLENDER', 37], ['JNG', 'JATINEGARA', 41],
                ['MTR', 'MATRAMAN', 44], ['MRI', 'MANGGARAI', 47], ['SUD', 'SUDIRMAN', 51],
                ['THB', 'TANAH ABANG', 56], ['DU', 'DURI', 61],
                ['AK', 'ANGKE', 64], ['KPB', 'KAMPUNG BANDAN', 68],
            ],
            'patterns' => [[0, 18], [5, 18]],
        ],
        [
            'name' => 'COMMUTER LINE RANGKASBITUNG',
            'color' => '#16812B',
            'number_base' => 3000,
            'headway' => [12, 20, 25],
            'stations' => [
                ['THB', 'TANAH ABANG', 0], ['PLM', 'PALMERAH', 4], ['KBY', 'KEBAYORAN', 9],
                ['PDJ', 'PONDOK RANJI', 14], ['JMU', 'JURANGMANGU', 18], ['SDM', 'SUDIMARA', 22],
                ['RU', 'RAWA BUNTU', 26], ['SRP', 'SERPONG', 30], ['CSK', 'CISAUK', 34],
                ['CC', 'CICAYUR', 37], ['PRP', 'PARUNG PANJANG', 44], ['CJT', 'CILEJIT', 49],
                ['DAR', 'DARU', 53], ['TEJ', 'TENJO', 58], ['TGS', 'TIGARAKSA', 63],
                ['CKY', 'CIKOYA', 67], ['MJ', 'MAJA', 72], ['CTR', 'CITERAS', 80],
                ['RK', 'RANGKASBITUNG', 88],
            ],
            'patterns' => [[0, 7], [0, 10], [0, 18]],
        ],
        [
            'name' => 'COMMUTER LINE TANGERANG',
            'color' => '#623814',
            'number_base' => 2000,
            'headway' => [12, 20, 25],
            'stations' => [
                ['DU', 'DURI', 0], ['GGL', 'GROGOL', 4], ['PSG', 'PESING', 7],
                ['TKO', 'TAMAN KOTA', 10], ['BOI', 'BOJONG INDAH', 13], ['RW', 'RAWA BUAYA', 16],
                ['KDS', 'KALIDERES', 19], ['PI', 'PORIS', 23], ['BPR', 'BATU CEPER', 26],
                ['THI', 'TANAH TINGGI', 29], ['TNG', 'TANGERANG', 33],
            ],
            'patterns' => [[0, 10]],
        ],
        [
            'name' => 'COMMUTER LINE TANJUNGPRIOK',
            'color' => '#DD0067',
            'number_base' => 4000,
            'headway' => [30, 30, 40],
            'stations' => [
                ['JAKK', 'JAKARTA KOTA', 0], ['KPB', 'KAMPUNG BANDAN', 5],
                ['AC', 'ANCOL', 9], ['TPK', 'TANJUNG PRIOK', 15],
            ],
            'patterns' => [[0, 3]],
        ],
    ];

    /** Stations that exist in the KCI list but are currently closed. */
    private const DISABLED_STATIONS = ['TGS'];

    /** @var array<string, array<string, list<array<string, string>>>> date => station => rows */
    private array $cache = [];

    public function __construct(private readonly bool $failing = false) {}

    public function fetchStations(): array
    {
        $this->guard();

        $stations = [];
        foreach (self::LINES as $line) {
            foreach ($line['stations'] as [$code, $name]) {
                $stations[$code] ??= [
                    'sta_id' => $code,
                    'sta_name' => $name,
                    'group_wil' => 0,
                    'fg_enable' => 1,
                ];
            }
        }

        foreach (self::DISABLED_STATIONS as $code) {
            if (isset($stations[$code])) {
                $stations[$code]['fg_enable'] = 0;
            }
        }

        return ['status' => 200, 'message' => 'success', 'data' => array_values($stations)];
    }

    public function fetchStationSchedules(string $stationCode, CarbonInterface $date): array
    {
        $this->guard();

        $rows = $this->timetable($date)[strtoupper($stationCode)] ?? [];

        usort($rows, fn ($a, $b) => strcmp($a['time_est'], $b['time_est']));

        return ['status' => 200, 'data' => $rows];
    }

    public function isDateSpecific(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'mock';
    }

    private function guard(): void
    {
        if ($this->failing) {
            throw KciApiException::unavailable('mock client configured to fail');
        }
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function timetable(CarbonInterface $date): array
    {
        $key = $date->toDateString();

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $weekend = $date->isWeekend();
        $byStation = [];

        foreach (self::LINES as $line) {
            $stations = $line['stations'];

            foreach ([0, 1] as $direction) {
                $minute = 4 * 60 + ($direction ? 7 : 0); // first departure 04:00 / 04:07
                $trip = 0;

                while ($minute <= 22 * 60 + 30) {
                    [$from, $to] = $line['patterns'][$trip % count($line['patterns'])];
                    $slice = array_slice($stations, $from, $to - $from + 1);
                    if ($direction === 1) {
                        $slice = array_reverse($slice);
                    }

                    $origin = $slice[0];
                    $terminus = $slice[array_key_last($slice)];
                    $number = (string) ($line['number_base'] + $trip * 2 + $direction + 1);
                    $arrival = $minute + abs($terminus[2] - $origin[2]);

                    foreach ($slice as $index => [$code, , $offset]) {
                        if ($index === array_key_last($slice)) {
                            break; // trains do not depart from their final stop
                        }

                        $byStation[$code][] = [
                            'train_id' => $number,
                            'ka_name' => $line['name'],
                            'route_name' => str_replace(' ', '', $origin[1]).'-'.str_replace(' ', '', $terminus[1]),
                            'dest' => str_replace(' ', '', $terminus[1]),
                            'time_est' => $this->clock($minute + abs($offset - $origin[2])),
                            'color' => $line['color'],
                            'dest_time' => $this->clock($arrival),
                        ];
                    }

                    $minute += $this->headway($line['headway'], $minute, $weekend);
                    $trip++;
                }
            }
        }

        return $this->cache[$key] = $byStation;
    }

    private function headway(array $headways, int $minute, bool $weekend): int
    {
        if ($weekend) {
            return $headways[2];
        }

        $peak = ($minute >= 5 * 60 + 30 && $minute < 9 * 60) || ($minute >= 16 * 60 && $minute < 19 * 60 + 30);

        return $peak ? $headways[0] : $headways[1];
    }

    private function clock(int $minutes): string
    {
        $minutes = min($minutes, 23 * 60 + 59);

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
