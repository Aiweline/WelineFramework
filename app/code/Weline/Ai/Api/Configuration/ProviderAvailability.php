<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Configuration;

final readonly class ProviderAvailability
{
    public function __construct(
        public string $providerCode,
        public bool $available,
    ) {
    }
}
