<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute;

use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Scope\EavScopeValue;
use Weline\Framework\Runtime\ScopeIdentity;

/** Optional bulk read capability; single-value providers remain compatible. */
interface ScopedAttributeBatchReaderInterface
{
    /**
     * @param list<int|string> $ownerIds
     * @param list<AttributeRecord> $attributes
     * @return array<int|string, array<string, EavScopeValue>> Includes unresolved and cleared cells.
     */
    public function readScopedValues(
        EntityDefinitionInterface $entity,
        array $ownerIds,
        array $attributes,
        ScopeIdentity $scope,
        string $locale = '',
    ): array;
}
