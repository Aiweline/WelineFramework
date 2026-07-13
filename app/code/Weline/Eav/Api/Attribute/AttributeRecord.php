<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute;

/**
 * Immutable public projection of EAV attribute metadata.
 */
final readonly class AttributeRecord
{
    public function __construct(
        public int $id,
        public int $entityId,
        public string $code,
        public string $name,
        public int $typeId,
        public string $typeCode,
        public int $setId,
        public int $groupId,
        public bool $multiple,
        public bool $hasOption,
    ) {
    }
}
