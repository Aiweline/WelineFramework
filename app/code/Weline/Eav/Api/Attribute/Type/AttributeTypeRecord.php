<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute\Type;

/** Immutable public projection of an EAV type row. */
final readonly class AttributeTypeRecord
{
    public function __construct(
        public int $id,
        public string $fieldType,
        public string $code,
        public string $frontendAttributes,
        public int $fieldLength,
        public bool $swatch,
        public string $element,
        public string $modelClass,
        public string $modelClassData,
        public bool $required,
        public string $defaultValue,
        public string $name,
    ) {
    }
}
