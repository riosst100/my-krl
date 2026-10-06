<?php

namespace App\Services\Kci\Exceptions;

use Carbon\CarbonInterface;

/**
 * KCI refuses this client (Cloudflare block or rate limit that did not clear).
 * Every further request in the same run would be refused too, so syncs stop
 * instead of trying the remaining stations/trains.
 */
class KciBlockedException extends KciApiException
{
    public static function coolingDown(CarbonInterface $until): self
    {
        return new self('KCI API unavailable: requests paused after KCI blocked this server; try again after '.$until->format('H:i'));
    }
}
