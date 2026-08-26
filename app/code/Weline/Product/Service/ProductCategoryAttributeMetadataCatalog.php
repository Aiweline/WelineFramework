<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\CategoryAttributeEntity;

/**
 * Category-owned adapter from Eav definitions to Product shard category values.
 */
final class ProductCategoryAttributeMetadataCatalog
{
    private const SCOPE_STATES = ['explicit', 'cleared', 'inherit'];

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $metadata,
        private readonly CategoryAttributeEntity $entity,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editorCatalog(): array
    {
        $result = [];
        foreach ($this->metadata->catalog($this->entity) as $set) {
            if (!$set instanceof AttributeSetMetadata) {
                throw new \UnexpectedValueException('category_attribute_metadata_set_invalid');
            }
            $row = $set->toArray();
            foreach ($row['groups'] as &$group) {
                foreach ($group['attributes'] as &$attribute) {
                    $attribute['value_type'] = $this->valueType($attribute);
                    $attribute['scope_states'] = self::SCOPE_STATES;
                }
                unset($attribute);
            }
            unset($group);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $attribute
     */
    private function valueType(array $attribute): string
    {
        $typeCode = strtolower((string)($attribute['type_code'] ?? ''));
        $fieldType = strtolower((string)($attribute['field_type'] ?? ''));
        $element = strtolower((string)($attribute['element'] ?? ''));

        if (in_array($typeCode, ['int', 'integer', 'decimal', 'numeric', 'float', 'double', 'number'], true)
            || in_array($fieldType, ['int', 'integer', 'decimal', 'numeric', 'float', 'double', 'number'], true)
        ) {
            return 'number';
        }
        if (in_array($typeCode, ['bool', 'boolean'], true) || $element === 'checkbox') {
            return 'boolean';
        }

        return 'string';
    }
}
