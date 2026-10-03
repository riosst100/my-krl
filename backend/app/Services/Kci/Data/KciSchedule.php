<?php

namespace App\Services\Kci\Data;

/**
 * One train calling at one station, as published by KCI.
 */
final readonly class KciSchedule
{
    public function __construct(
        public string $trainNumber,
        public string $lineName,
        public ?string $lineColor,
        public ?string $routeName,
        public string $destination,
        public string $departureTime,          // H:i:s, time the train is at the station
        public ?string $destinationArrivalTime, // H:i:s, arrival at the final destination
    ) {}
}
