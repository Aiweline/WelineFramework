<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute\Option;

/** Immutable input for one EAV attribute option. */
final readonly class AttributeOptionDefinition
{
    public function __construct(
        public int $attributeId,
        public string $code,
        public string $value,
    ) {
    }
}
