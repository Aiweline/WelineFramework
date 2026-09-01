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
     * 全局属性集 + 指定商品实例的自由属性（scope_product_id）。
     *
     * @return list<AttributeSetMetadata>
     */
    public function catalogForProduct(
        EntityDefinitionInterface $entity,
        int $productId,
        string $freeSetCode = '__product_free',
    ): array;

    /**
     * @return array<string, AttributeMetadata> attribute code => metadata
     */
    public function attributeIndexByEntityCode(string $entityCode): array;
}
