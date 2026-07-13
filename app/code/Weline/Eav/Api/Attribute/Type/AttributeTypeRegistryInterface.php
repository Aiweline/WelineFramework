<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute\Type;

interface AttributeTypeRegistryInterface
{
    public function register(AttributeTypeDefinition $definition): void;

    public function find(string $code): ?AttributeTypeRecord;
}
