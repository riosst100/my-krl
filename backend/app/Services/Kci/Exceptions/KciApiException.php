<?php

namespace App\Services\Kci\Exceptions;

use RuntimeException;

class KciApiException extends RuntimeException
{
    public static function unavailable(string $reason): self
    {
        return new self("KCI API unavailable: {$reason}");
    }

    public static function invalidResponse(string $reason): self
    {
        return new self("KCI API returned an invalid response: {$reason}");
    }
}
