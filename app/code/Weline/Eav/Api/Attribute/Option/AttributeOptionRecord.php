<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute\Option;

/** Immutable public projection of one EAV attribute option row. */
final readonly class AttributeOptionRecord
{
    public function __construct(
        public int $id,
        public int $attributeId,
        public string $code,
        public string $value,
        public int $eavEntityId = 0,
        public int $scopeInstanceId = 0,
        public string $swatchColor = '',
        public string $swatchImage = '',
        public string $swatchText = '',
    ) {
    }

    public function isShared(): bool
    {
        return $this->scopeInstanceId <= 0;
    }
}
