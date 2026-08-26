<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

use Weline\Eav\Api\Entity\EntityDefinitionInterface;

/**
 * Read-only public hierarchy of Eav attribute definitions.
 */
interface AttributeMetadataCatalogInterface
{
    /**
     * @return list<AttributeSetMetadata>
     */
    public function catalog(EntityDefinitionInterface $entity): array;

    /**
     * @return array<string, AttributeMetadata> attribute code => metadata
     */
    public function attributeIndexByEntityCode(string $entityCode): array;
}
