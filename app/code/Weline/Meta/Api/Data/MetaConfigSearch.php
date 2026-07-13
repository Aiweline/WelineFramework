<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetaConfigSearch
{
    public function __construct(
        public string $namespace,
        public string $scope,
        public ?string $configKey = null,
        public ?string $configKeyPrefix = null,
        public ?string $locale = null,
        public bool $allLocales = false,
        public ?string $identifyId = null,
        public ?int $metaId = null,
        public ?string $metaIdentify = null,
    ) {
        if (trim($this->namespace) === '' || trim($this->scope) === '') {
            throw new \InvalidArgumentException('Meta config search requires namespace and an exact scope.');
        }
        if ($this->configKey !== null && $this->configKeyPrefix !== null) {
            throw new \InvalidArgumentException('Meta config search cannot combine configKey and configKeyPrefix.');
        }
        if (!$this->hasOwnerIdentity()) {
            throw new \InvalidArgumentException('Meta config search requires identifyId, metaId, or metaIdentify.');
        }
        if ($this->locale !== null && trim($this->locale) === '') {
            throw new \InvalidArgumentException('Meta config locale must be NULL or a non-empty locale code.');
        }
        if ($this->allLocales && $this->locale !== null) {
            throw new \InvalidArgumentException('allLocales cannot be combined with an exact locale.');
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
