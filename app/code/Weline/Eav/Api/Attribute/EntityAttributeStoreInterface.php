<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute;

use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Public EAV boundary for entity schema provisioning and attribute values.
 *
 * Implementations own every ORM model and dynamic value-table detail. Consumers
 * exchange only entity definitions, immutable metadata records, and scalar data.
 */
interface EntityAttributeStoreInterface
{
    public function provisionValueTables(EntityDefinitionInterface $entity, ModelSetup $setup): void;

    public function syncAttributeSequence(): void;

    public function declareAttribute(
        EntityDefinitionInterface $entity,
        AttributeDefinition $definition,
    ): AttributeRecord;

    public function getAttribute(
        EntityDefinitionInterface $entity,
        string $attributeCode,
    ): ?AttributeRecord;

    /**
     * @return list<AttributeRecord>
     */
    public function getAttributes(EntityDefinitionInterface $entity): array;

    public function readValue(
        EntityDefinitionInterface $entity,
        int|string $ownerId,
        AttributeRecord $attribute,
    ): mixed;

    /**
     * @param string|int|list<string|int> $value
     */
    public function replaceValue(
        EntityDefinitionInterface $entity,
        int|string $ownerId,
        AttributeRecord $attribute,
        string|int|array $value,
    ): void;

    /**
     * 按 typed Scope + locale 解析（含四层 fallback；cleared 阻断）。
     */
    public function readScopedValue(
        EntityDefinitionInterface $entity,
        int|string $ownerId,
        AttributeRecord $attribute,
        \Weline\Framework\Runtime\ScopeIdentity $scope,
        string $locale = '',
    ): \Weline\Eav\Api\Scope\EavScopeValue;

    /**
     * 在目标 Scope/locale 写 explicit 值（不删遗留行）。
     *
     * @param string|int|list<string|int> $value
     */
    public function writeScopedValue(
        EntityDefinitionInterface $entity,
        int|string $ownerId,
        AttributeRecord $attribute,
        \Weline\Framework\Runtime\ScopeIdentity $scope,
        string|int|array $value,
        string $locale = '',
    ): void;

    /**
     * 在目标 Scope/locale 写 cleared（阻断父 Scope/locale/默认回退）。
     */
    public function clearScopedValue(
        EntityDefinitionInterface $entity,
        int|string $ownerId,
        AttributeRecord $attribute,
        \Weline\Framework\Runtime\ScopeIdentity $scope,
        string $locale = '',
    ): void;
}
