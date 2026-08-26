<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Product-owned adapter from Eav definitions to concrete Product value rows.
 */
final class ProductAttributeMetadataCatalog
{
    private const SCOPE_STATES = ['explicit', 'cleared', 'inherit'];

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $metadata,
        private readonly ProductCatalogAttributeEntity $entity,
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
                throw new \UnexpectedValueException('product_attribute_metadata_set_invalid');
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
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeRows(array $rows): array
    {
        $metadata = $this->metadataIndex();
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('product_attribute_invalid');
            }
            $code = trim((string)($row['attribute_code'] ?? ''));
            if ($code === '' || strlen($code) > 128) {
                throw new \InvalidArgumentException('product_attribute_code_invalid');
            }
            $scopeState = strtolower(trim((string)($row['scope_state'] ?? 'explicit')));
            if (!in_array($scopeState, self::SCOPE_STATES, true)) {
                throw new \InvalidArgumentException('product_attribute_scope_state_invalid');
            }
            $storeId = (int)($row['store_id'] ?? 0);
            if ($storeId < 0) {
                throw new \InvalidArgumentException('product_attribute_store_invalid');
            }
            $entityType = strtolower(trim((string)($row['entity_type'] ?? 'product')));
            if ($entityType === '') {
                throw new \InvalidArgumentException('product_attribute_entity_type_invalid');
            }
            $entityId = (int)($row['entity_id'] ?? 0);
            if ($entityId < 0) {
                throw new \InvalidArgumentException('product_attribute_entity_id_invalid');
            }
            $locale = trim((string)($row['locale'] ?? ''));
            $key = implode('|', [$entityType, $entityId, $storeId, $locale, $code]);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('product_attribute_duplicate');
            }
            $seen[$key] = true;

            // Keep migration-only keys and unknown value representations intact.
            $normalized = $row;
            $normalized['attribute_code'] = $code;
            $normalized['scope_state'] = $scopeState;
            $normalized['store_id'] = $storeId;
            $normalized['entity_type'] = $entityType;
            $normalized['locale'] = $locale;

            $definition = $metadata[$code] ?? null;
            if (!is_array($definition)) {
                $result[] = $normalized;
                continue;
            }

            $valueType = (string)$definition['value_type'];
            $normalized['value_type'] = $valueType;
            $normalized['is_required'] = (bool)($definition['required'] ?? false);
            if ($scopeState === 'explicit') {
                $normalized['value'] = $this->normalizeExplicitValue(
                    $valueType,
                    $row['value'] ?? null,
                    $definition,
                );
            } elseif ($scopeState === 'cleared') {
                $normalized['value'] = null;
            }
            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function metadataIndex(): array
    {
        $index = [];
        foreach ($this->editorCatalog() as $set) {
            foreach ($set['groups'] as $group) {
                foreach ($group['attributes'] as $attribute) {
                    $code = (string)($attribute['code'] ?? '');
                    if ($code !== '') {
                        $index[$code] = $attribute;
                    }
                }
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $attribute
     */
    private function valueType(array $attribute): string
    {
        $typeCode = strtolower((string)($attribute['type_code'] ?? ''));
        $fieldType = strtolower((string)($attribute['field_type'] ?? ''));
        $element = strtolower((string)($attribute['element'] ?? ''));
        $multiple = (bool)($attribute['multiple'] ?? false);
        $hasOption = (bool)($attribute['has_option'] ?? false);

        if ($multiple) {
            return 'multiselect';
        }
        if ($hasOption || in_array($element, ['select', 'radio'], true)) {
            return 'select';
        }
        if (in_array($typeCode, ['bool', 'boolean'], true)
            || in_array($fieldType, ['bool', 'boolean'], true)
            || $element === 'checkbox'
        ) {
            return 'boolean';
        }
        if (in_array($typeCode, ['int', 'integer', 'smallint', 'bigint', 'decimal', 'numeric', 'float', 'double', 'number'], true)
            || in_array($fieldType, ['int', 'integer', 'smallint', 'bigint', 'decimal', 'numeric', 'float', 'double', 'number'], true)
        ) {
            return 'number';
        }
        if (in_array($typeCode, ['date', 'datetime', 'timestamp', 'time'], true)
            || in_array($fieldType, ['date', 'datetime', 'datetime-local', 'timestamp', 'time'], true)
        ) {
            return 'date';
        }
        if (in_array($typeCode, ['json', 'array', 'serialized'], true)
            || in_array($fieldType, ['json', 'array', 'serialized'], true)
        ) {
            return 'json';
        }

        return 'string';
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function normalizeExplicitValue(string $valueType, mixed $value, array $definition): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($valueType) {
            'number' => $this->numberValue($value),
            'boolean' => $this->booleanValue($value),
            'select' => $this->selectValue($value, $definition),
            'multiselect' => $this->multiselectValue($value, $definition),
            'date' => $this->scalarValue($value, 'product_attribute_date_invalid'),
            'json' => $value,
            default => $this->scalarValue($value, 'product_attribute_string_invalid'),
        };
    }

    private function numberValue(mixed $value): int|float|null
    {
        if ($value === '') {
            return null;
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new \InvalidArgumentException('product_attribute_number_invalid');
        }
        $number = (float)$value;

        return floor($number) === $number ? (int)$number : $number;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if ($value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \InvalidArgumentException('product_attribute_boolean_invalid');
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function selectValue(mixed $value, array $definition): ?string
    {
        if ($value === '') {
            return null;
        }
        $value = $this->scalarValue($value, 'product_attribute_option_invalid');

        return $this->canonicalOption($value, $definition);
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    private function multiselectValue(mixed $value, array $definition): array
    {
        if ($value === '' || $value === null) {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $item) {
            $canonical = $this->canonicalOption(
                $this->scalarValue($item, 'product_attribute_option_invalid'),
                $definition,
            );
            $result[$canonical] = $canonical;
        }

        return array_values($result);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function canonicalOption(string $value, array $definition): string
    {
        $options = $definition['options'] ?? [];
        if (!is_array($options) || $options === []) {
            return $value;
        }
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $canonical = (string)($option['value'] ?? '');
            if ($value === $canonical || $value === (string)($option['code'] ?? '')) {
                return $canonical;
            }
        }

        throw new \InvalidArgumentException('product_attribute_option_invalid');
    }

    private function scalarValue(mixed $value, string $errorCode): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new \InvalidArgumentException($errorCode);
        }

        return trim((string)$value);
    }
}
