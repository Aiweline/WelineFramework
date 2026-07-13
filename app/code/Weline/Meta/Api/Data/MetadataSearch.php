<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetadataSearch
{
    public function __construct(
        public string $namespace,
        public ?string $type = null,
        public ?string $identify = null,
        public ?string $identifyPrefix = null,
        public ?string $area = null,
        public ?string $category = null,
        public ?string $filePath = null,
    ) {
        if (trim($this->namespace) === '') {
            throw new \InvalidArgumentException('Metadata search requires a namespace.');
        }
        if ($this->identify !== null && $this->identifyPrefix !== null) {
            throw new \InvalidArgumentException('Metadata search cannot combine identify and identifyPrefix.');
        }
    }
}
