<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetaConfigScopeSearch
{
    public function __construct(
        public string $namespace,
        public ?string $identifyId = null,
        public ?int $metaId = null,
        public ?string $metaIdentify = null,
    ) {
        if (trim($this->namespace) === '') {
            throw new \InvalidArgumentException('Meta config scope search requires a namespace.');
        }
        if (!$this->hasOwnerIdentity()) {
            throw new \InvalidArgumentException('Meta config scope search requires identifyId, metaId, or metaIdentify.');
        }
        if ($this->metaId !== null && $this->metaId < 1) {
            throw new \InvalidArgumentException('Meta config metaId must be a positive integer when provided.');
        }
    }

    public function hasOwnerIdentity(): bool
    {
        return ($this->identifyId !== null && trim($this->identifyId) !== '')
            || $this->metaId !== null
            || ($this->metaIdentify !== null && trim($this->metaIdentify) !== '');
    }
}
