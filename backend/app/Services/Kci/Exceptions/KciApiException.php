<?php

namespace App\Services\Kci\Exceptions;

use RuntimeException;

class KciApiException extends RuntimeException
{
    public static function unavailable(string $reason): self
    {
        return new static("KCI API unavailable: {$reason}");
    }

    public static function invalidResponse(string $reason): self
    {
        return new static("KCI API returned an invalid response: {$reason}");
    }
}
