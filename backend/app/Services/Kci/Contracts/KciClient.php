<?php

namespace App\Services\Kci\Contracts;

use App\Services\Kci\Exceptions\KciApiException;
use Carbon\CarbonInterface;

/**
 * Low-level transport to a KCI data source. Implementations return the raw
 * decoded payload; validation and transformation happen in KciService.
 */
interface KciClient
{
    /**
     * @return array<string, mixed> Decoded response body, e.g. {"status":200,"data":[{"sta_id":"BKS",...}]}
     *
     * @throws KciApiException
     */
    public function fetchStations(): array;

    /**
     * @return array<string, mixed> Decoded response body, e.g. {"status":200,"data":[{"train_id":"5012",...}]}
     *
     * @throws KciApiException
     */
    public function fetchStationSchedules(string $stationCode, CarbonInterface $date): array;

    /**
     * Whether the source returns different timetables for different dates.
     * When false, one request per station is reused for every synced date.
     */
    public function isDateSpecific(): bool;

    public function name(): string;
}
