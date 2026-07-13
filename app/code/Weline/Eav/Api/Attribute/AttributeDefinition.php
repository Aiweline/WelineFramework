<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute;

/**
 * Immutable input used when a module declares one EAV attribute.
 */
final readonly class AttributeDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public string $typeCode = 'input_string',
        public bool $multiple = false,
        public bool $hasOption = false,
        public string $setCode = 'default',
        public string $groupCode = 'default',
        public bool $system = false,
        public bool $enabled = true,
    ) {
    }
}
