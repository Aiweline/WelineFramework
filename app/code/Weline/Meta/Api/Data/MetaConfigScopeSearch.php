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
        MetaConfigIdentity::assertNamespace($this->namespace);
        MetaConfigIdentity::assertIdentifyId($this->identifyId);
        MetaConfigIdentity::assertMetaIdentify($this->metaIdentify);
        if (!$this->hasOwnerIdentity()) {
            throw new \InvalidArgumentException('Meta config scope search requires identifyId, metaId, or metaIdentify.');
        }
        if ($this->metaId !== null
            && ($this->metaId < 1 || $this->metaId > MetaConfigIdentity::META_ID_MAX)) {
            throw new \InvalidArgumentException('Meta config metaId must fit a positive signed 32-bit integer.');
        }
    }

    public function hasOwnerIdentity(): bool
    {
        return ($this->identifyId !== null && trim($this->identifyId) !== '')
            || $this->metaId !== null
            || ($this->metaIdentify !== null && trim($this->metaIdentify) !== '');
    }
}
