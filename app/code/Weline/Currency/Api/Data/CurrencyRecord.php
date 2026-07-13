<?php

declare(strict_types=1);

namespace Weline\Currency\Api\Data;

/** Immutable currency projection for cross-module reads. */
final readonly class CurrencyRecord
{
    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public bool $active,
        public string $format = '1,0',
        public string $position = 'left',
        public float $rate = 0.0,
    ) {
    }
}
