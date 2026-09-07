<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Product-owned adapter from Eav definitions to concrete Product value rows.
 */
final class ProductAttributeMetadataCatalog
{
    private const SCOPE_STATES = ['explicit', 'cleared', 'inherit'];

    /**
     * Codes owned by dedicated product basics / system plumbing — never shown in EAV editor groups.
     *
     * @var array<string, true>
     */
    private const EDITOR_SKIP_CODES = [
        'attribute_set' => true,
        'attribute_set_label' => true,
        'brand' => true,
        'brand_code' => true,
        'name' => true,
        'type_configuration' => true,
    ];

    /**
     * Input aliases (option code / prior value) resolved during ensureAndCanonicalizeVariantAxes
     * for the current request — keeps combination remap in sync when ensure matched by label.
     *
     * @var array<int, array<string, array<string, string>>>
     */
    private array $ensuredOptionAliases = [];

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $metadata,
        private readonly ProductCatalogAttributeEntity $entity,
        private readonly AttributeOptionStoreInterface $optionStore,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editorCatalog(?int $productId = null): array
    {
        $sets = $productId > 0
            ? $this->metadata->catalogForProduct($this->entity, $productId)
            : $this->metadata->catalog($this->entity);
        $result = [];
        foreach ($sets as $set) {
            if (!$set instanceof AttributeSetMetadata) {
                throw new \UnexpectedValueException('product_attribute_metadata_set_invalid');
            }
            $row = $set->toArray();
            foreach ($row['groups'] as &$group) {
                $filtered = [];
                foreach ($group['attributes'] as $attribute) {
                    $code = strtolower(trim((string)($attribute['code'] ?? $attribute['attribute_code'] ?? '')));
                    if ($code !== '' && isset(self::EDITOR_SKIP_CODES[$code])) {
                        continue;
                    }
                    $attribute['value_type'] = $this->valueType($attribute);
                    $attribute['scope_states'] = self::SCOPE_STATES;
                    $attribute['swatch_capabilities'] = $this->swatchCapabilities($attribute);
                    $filtered[] = $attribute;
                }
                $group['attributes'] = $filtered;
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
    public function normalizeRows(array $rows, ?int $productId = null): array
    {
        $metadata = $this->metadataIndex($productId);
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
                    $productId,
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
    private function metadataIndex(?int $productId = null): array
    {
        $index = [];
        foreach ($this->editorCatalog($productId) as $set) {
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
     * Ensure custom axis options exist as shared-or-instance-private rows, then canonicalize.
     *
     * @param list<array{code:string,label?:string,options:list<mixed>}> $axes
     * @return list<array{code:string,label:string,options:list<array{value:string,label:string}>}>
     */
    public function ensureAndCanonicalizeVariantAxes(int $productId, array $axes): array
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('product_id_required');
        }
        $metadata = $this->metadataIndex($productId);
        $result = [];
        foreach ($axes as $axis) {
            if (!is_array($axis)) {
                throw new \InvalidArgumentException('variant_axes_invalid');
            }
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            $definition = $metadata[$code] ?? null;
            if ($code === '' || !is_array($definition)) {
                throw new \InvalidArgumentException('product_attribute_unknown');
            }
            $attributeId = (int)($definition['id'] ?? 0);
            $eavEntityId = (int)($definition['entity_id'] ?? 0);
            if ($attributeId <= 0 || $eavEntityId <= 0) {
                throw new \InvalidArgumentException('product_attribute_unknown');
            }
            $options = [];
            foreach (is_array($axis['options'] ?? null) ? $axis['options'] : [] as $option) {
                $input = is_array($option)
                    ? trim((string)($option['value'] ?? $option['code'] ?? ''))
                    : trim((string)$option);
                $label = is_array($option)
                    ? trim((string)($option['label'] ?? $option['name'] ?? ''))
                    : '';
                if ($input === '') {
                    throw new \InvalidArgumentException('variant_option_invalid');
                }
                $optionCode = is_array($option)
                    ? strtolower(trim((string)($option['code'] ?? '')))
                    : '';
                if ($optionCode === '') {
                    $optionCode = preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', strtolower($input)) === 1
                        ? strtolower($input)
                        : 'o' . substr(hash('sha256', $input), 0, 10);
                }
                if ($label === '') {
                    $label = $input;
                }
                $swatchColor = is_array($option)
                    ? trim((string)($option['swatch_color'] ?? $option['swatch'] ?? ''))
                    : '';
                $swatchImage = is_array($option)
                    ? trim((string)($option['swatch_image'] ?? ''))
                    : '';
                $record = $this->optionStore->ensureInScope(
                    $eavEntityId,
                    $attributeId,
                    $productId,
                    $optionCode,
                    $label,
                    $swatchColor,
                    $swatchImage,
                );
                $canonical = trim($record->value) !== '' ? $record->value : $record->code;
                $options[] = [
                    'value' => $canonical,
                    'label' => $label !== '' ? $label : $canonical,
                ];
                foreach ([$input, $optionCode, $record->code, $canonical] as $alias) {
                    $alias = trim((string)$alias);
                    if ($alias === '') {
                        continue;
                    }
                    $this->ensuredOptionAliases[$productId][$code][$alias] = $canonical;
                    $this->ensuredOptionAliases[$productId][$code][strtolower($alias)] = $canonical;
                }
            }
            $result[] = [
                'code' => $code,
                'label' => trim((string)($axis['label'] ?? $definition['label'] ?? $code)),
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * @param list<array{code:string,label?:string,options:list<mixed>}> $axes
     */
    public function ensureVariantAxisOptions(int $productId, array $axes): void
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('product_id_required');
        }
        $metadata = $this->metadataIndex($productId);
        foreach ($axes as $axis) {
            if (!is_array($axis)) {
                throw new \InvalidArgumentException('variant_axes_invalid');
            }
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            $definition = $metadata[$code] ?? null;
            if ($code === '' || !is_array($definition)) {
                throw new \InvalidArgumentException('product_attribute_unknown');
            }
            $attributeId = (int)($definition['id'] ?? 0);
            $eavEntityId = (int)($definition['entity_id'] ?? 0);
            if ($attributeId <= 0 || $eavEntityId <= 0) {
                throw new \InvalidArgumentException('product_attribute_unknown');
            }
            foreach (is_array($axis['options'] ?? null) ? $axis['options'] : [] as $option) {
                $input = is_array($option)
                    ? trim((string)($option['value'] ?? $option['code'] ?? ''))
                    : trim((string)$option);
                $label = is_array($option)
                    ? trim((string)($option['label'] ?? $option['name'] ?? ''))
                    : '';
                if ($input === '') {
                    throw new \InvalidArgumentException('variant_option_invalid');
                }
                $optionCode = is_array($option)
                    ? strtolower(trim((string)($option['code'] ?? '')))
                    : '';
                if ($optionCode === '') {
                    $optionCode = preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', strtolower($input)) === 1
                        ? strtolower($input)
                        : 'o' . substr(hash('sha256', $input), 0, 10);
                }
                if ($label === '') {
                    $label = $input;
                }
                $swatchColor = is_array($option)
                    ? trim((string)($option['swatch_color'] ?? $option['swatch'] ?? ''))
                    : '';
                $swatchImage = is_array($option)
                    ? trim((string)($option['swatch_image'] ?? ''))
                    : '';
                $this->optionStore->ensureInScope(
                    $eavEntityId,
                    $attributeId,
                    $productId,
                    $optionCode,
                    $label,
                    $swatchColor,
                    $swatchImage,
                );
            }
        }
    }

    /**
     * Convert configurable axes from option codes or IDs to the canonical EAV
     * option values stored by Product and Offer attribute rows.
     *
     * @param list<array{code:string,label?:string,options:list<mixed>}> $axes
     * @return list<array{code:string,label:string,options:list<array{value:string,label:string}>}>
     */
    public function canonicalizeVariantAxes(array $axes, ?int $productId = null): array
    {
        $metadata = $this->metadataIndex($productId);
        $result = [];
        foreach ($axes as $axis) {
            if (!is_array($axis)) {
                throw new \InvalidArgumentException('variant_axes_invalid');
            }
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            $definition = $metadata[$code] ?? null;
            if ($code === '' || !is_array($definition)) {
                throw new \InvalidArgumentException('product_attribute_unknown');
            }
            $options = [];
            foreach (is_array($axis['options'] ?? null) ? $axis['options'] : [] as $option) {
                $input = is_array($option)
                    ? trim((string)($option['value'] ?? $option['code'] ?? ''))
                    : trim((string)$option);
                if ($input === '') {
                    throw new \InvalidArgumentException('variant_option_invalid');
                }
                $canonical = $this->canonicalOption($input, $definition, $productId);
                $label = is_array($option)
                    ? trim((string)($option['label'] ?? $option['name'] ?? ''))
                    : '';
                $options[] = [
                    'value' => $canonical,
                    'label' => $label !== '' ? $label : $canonical,
                ];
            }
            $result[] = [
                'code' => $code,
                'label' => trim((string)($axis['label'] ?? $definition['label'] ?? $code)),
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $combination
     * @return array<string,string>
     */
    public function canonicalizeVariantCombination(array $combination, ?int $productId = null): array
    {
        $metadata = $this->metadataIndex($productId);
        $result = [];
        foreach ($combination as $code => $value) {
            $code = strtolower(trim((string)$code));
            $definition = $metadata[$code] ?? null;
            if ($code === '' || !is_array($definition)) {
                throw new \InvalidArgumentException('product_attribute_unknown');
            }
            $input = $this->scalarValue($value, 'product_attribute_option_invalid');
            $aliases = ($productId !== null)
                ? ($this->ensuredOptionAliases[$productId][$code] ?? [])
                : [];
            if (isset($aliases[$input])) {
                $result[$code] = $aliases[$input];
                continue;
            }
            $lower = strtolower($input);
            if (isset($aliases[$lower])) {
                $result[$code] = $aliases[$lower];
                continue;
            }
            $result[$code] = $this->canonicalOption($input, $definition, $productId);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param array<string, mixed> $attribute
     * @return array{color:bool,image:bool,text:bool}
     */
    private function swatchCapabilities(array $attribute): array
    {
        $options = is_array($attribute['options'] ?? null) ? $attribute['options'] : [];
        $hasSwatchColor = false;
        $hasSwatchImage = false;
        $hasSwatchText = false;
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            if (trim((string)($option['swatch_color'] ?? '')) !== '') {
                $hasSwatchColor = true;
            }
            if (trim((string)($option['swatch_image'] ?? '')) !== '') {
                $hasSwatchImage = true;
            }
            if (trim((string)($option['swatch_text'] ?? '')) !== '') {
                $hasSwatchText = true;
            }
        }

        $code = strtolower(trim((string)($attribute['code'] ?? '')));
        $supportsImage = $hasSwatchImage
            || $hasSwatchColor
            || in_array($code, ['color', 'style_type'], true);

        return [
            'color' => $hasSwatchColor,
            'image' => $supportsImage,
            'text' => $hasSwatchText || (!$hasSwatchColor && !$hasSwatchImage),
        ];
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
    private function normalizeExplicitValue(
        string $valueType,
        mixed $value,
        array $definition,
        ?int $productId = null,
    ): mixed {
        if ($value === null) {
            return null;
        }

        return match ($valueType) {
            'number' => $this->numberValue($value),
            'boolean' => $this->booleanValue($value),
            'select' => $this->selectValue($value, $definition, $productId),
            'multiselect' => $this->multiselectValue($value, $definition, $productId),
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
    private function selectValue(mixed $value, array $definition, ?int $productId = null): ?string
    {
        if ($value === '') {
            return null;
        }
        $value = $this->scalarValue($value, 'product_attribute_option_invalid');

        return $this->canonicalOption($value, $definition, $productId);
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    private function multiselectValue(mixed $value, array $definition, ?int $productId = null): array
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
                $productId,
            );
            $result[$canonical] = $canonical;
        }

        return array_values($result);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function canonicalOption(string $value, array $definition, ?int $productId = null): string
    {
        $attributeCode = strtolower(trim((string)($definition['code'] ?? '')));
        if ($productId !== null && $attributeCode !== '') {
            $aliases = $this->ensuredOptionAliases[$productId][$attributeCode] ?? [];
            if (isset($aliases[$value])) {
                return $aliases[$value];
            }
            $lower = strtolower($value);
            if (isset($aliases[$lower])) {
                return $aliases[$lower];
            }
        }
        $options = $definition['options'] ?? [];
        if (!is_array($options) || $options === []) {
            return $value;
        }
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $canonical = (string)($option['value'] ?? '');
            $optionId = (string)($option['id'] ?? $option['option_id'] ?? '');
            $optionCode = (string)($option['code'] ?? '');
            $optionLabel = trim((string)($option['label'] ?? $option['name'] ?? ''));
            // Truncated import codes (ju-zhi-xian-wei-di-lun) often differ from the
            // shared option code (ju-zhi-xian) while the Chinese label matches.
            if ($value === $canonical
                || $value === $optionCode
                || ($optionId !== '' && $value === $optionId)
                || ($optionLabel !== '' && $value === $optionLabel)
            ) {
                return $canonical !== '' ? $canonical : ($optionId !== '' ? $optionId : $value);
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
