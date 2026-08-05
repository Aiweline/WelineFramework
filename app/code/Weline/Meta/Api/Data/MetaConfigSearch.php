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
        MetaConfigIdentity::assertNamespace($this->namespace);
        MetaConfigIdentity::assertScope($this->scope);
        if ($this->configKey !== null) {
            MetaConfigIdentity::assertConfigKey($this->configKey);
        }
        MetaConfigIdentity::assertConfigKeyPrefix($this->configKeyPrefix);
        MetaConfigIdentity::assertLocale($this->locale);
        MetaConfigIdentity::assertIdentifyId($this->identifyId);
        MetaConfigIdentity::assertMetaIdentify($this->metaIdentify);
        if ($this->configKey !== null && $this->configKeyPrefix !== null) {
            throw new \InvalidArgumentException('Meta config search cannot combine configKey and configKeyPrefix.');
        }
        if (!$this->hasOwnerIdentity()) {
            throw new \InvalidArgumentException('Meta config search requires identifyId, metaId, or metaIdentify.');
        }
        if ($this->allLocales && $this->locale !== null) {
            throw new \InvalidArgumentException('allLocales cannot be combined with an exact locale.');
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
