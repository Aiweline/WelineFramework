<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetaConfigIdentity
{
    public function __construct(
        public string $namespace,
        public string $configKey,
        public string $scope,
        public ?string $locale = null,
        public ?string $identifyId = null,
        public ?int $metaId = null,
        public ?string $metaIdentify = null,
    ) {
        if (trim($this->namespace) === '' || trim($this->configKey) === '' || trim($this->scope) === '') {
            throw new \InvalidArgumentException('Meta config identity requires namespace, configKey, and scope.');
        }
        if (!$this->hasOwnerIdentity()) {
            throw new \InvalidArgumentException('Meta config identity requires identifyId, metaId, or metaIdentify.');
        }
        if ($this->locale !== null && trim($this->locale) === '') {
            throw new \InvalidArgumentException('Meta config locale must be NULL or a non-empty locale code.');
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
