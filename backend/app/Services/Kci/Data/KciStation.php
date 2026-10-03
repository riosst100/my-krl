<?php

namespace App\Services\Kci\Data;

final readonly class KciStation
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $enabled,
        public ?int $operationalArea = null,
    ) {}
}
