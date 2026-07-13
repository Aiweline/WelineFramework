<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetadataIdentity
{
    public function __construct(
        public string $namespace,
        public string $type,
        public string $identify,
    ) {
        if (trim($this->namespace) === '' || trim($this->type) === '' || trim($this->identify) === '') {
            throw new \InvalidArgumentException('Metadata identity requires namespace, type, and identify.');
        }
    }
}
