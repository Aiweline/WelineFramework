<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute\Type;

/** Immutable declaration for one EAV input/storage type. */
final readonly class AttributeTypeDefinition
{
    public function __construct(
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
