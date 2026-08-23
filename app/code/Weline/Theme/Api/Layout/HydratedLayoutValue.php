<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

final readonly class HydratedLayoutValue
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public mixed $value,
        public array $metadata = [],
    ) {
    }
}
