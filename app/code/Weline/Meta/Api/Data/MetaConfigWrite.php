<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetaConfigWrite
{
    public function __construct(
        public MetaConfigIdentity $identity,
        public string $value,
    ) {
    }
}
