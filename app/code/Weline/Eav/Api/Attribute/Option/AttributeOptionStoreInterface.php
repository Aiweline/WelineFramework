<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute\Option;

interface AttributeOptionStoreInterface
{
    public function register(AttributeOptionDefinition $definition): void;

    public function find(int $attributeId, string $code): ?AttributeOptionRecord;
}
