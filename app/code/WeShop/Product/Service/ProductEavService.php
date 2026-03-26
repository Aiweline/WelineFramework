<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;

/**
 * Product EAV attribute service.
 */
class ProductEavService
{
    private EavEntity $eavEntity;
    private EavAttribute $eavAttribute;
    private Set $attributeSet;
    private Group $attributeGroup;
    private Option $attributeOption;

    private ?EavEntity $productEntity = null;

    public function __construct(
        EavEntity $eavEntity,
        EavAttribute $eavAttribute,
        Set $attributeSet,
        Group $attributeGroup,
        Option $attributeOption
    ) {
        $this->eavEntity = $eavEntity;
        $this->eavAttribute = $eavAttribute;
        $this->attributeSet = $attributeSet;
        $this->attributeGroup = $attributeGroup;
        $this->attributeOption = $attributeOption;
    }

    public function getProductEntity(): ?EavEntity
    {
        if ($this->productEntity === null) {
            $this->productEntity = $this->eavEntity->reset()
                ->where(EavEntity::schema_fields_code, Product::entity_code)
                ->find()
                ->fetch();
        }

        return $this->productEntity->getId() ? $this->productEntity : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProductAttributes(int $productId, ?int $setId = null): array
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return [];
        }

        if ($setId === null) {
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            $setId = (int) ($product->getData(Product::schema_fields_set_id) ?? 0);
        }

        if (!$setId) {
            return [];
        }

        $groups = $this->getAttributeGroups($setId);
        $result = [];

        foreach ($groups as $group) {
            $groupId = (int) ($group[Group::schema_fields_ID] ?? $group['group_id'] ?? 0);
            $groupName = $group[Group::schema_fields_name] ?? $group['name'] ?? '';
            $attributes = $this->getGroupAttributes($productEntity->getId(), $setId, $groupId);

            if ($attributes === []) {
                continue;
            }

            $attributesWithValues = [];
            foreach ($attributes as $attribute) {
                $attributeData = $this->loadAttributeValue($attribute, $productId);
                if ($attributeData !== null) {
                    $attributesWithValues[] = $attributeData;
                }
            }

            if ($attributesWithValues !== []) {
                $result[] = [
                    'group_id' => $groupId,
                    'group_name' => $groupName,
                    'attributes' => $attributesWithValues,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilterableAttributes(?int $setId = null): array
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return [];
        }

        $query = $this->eavAttribute->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, $productEntity->getId())
            ->where(EavAttribute::schema_fields_is_enable, 1)
            ->where(EavAttribute::schema_fields_has_option, 1);

        if ($setId) {
            $query->where(EavAttribute::schema_fields_set_id, $setId);
        }

        $attributes = $query->select()->fetch();
        if (!is_array($attributes)) {
            return [];
        }

        $result = [];
        foreach ($attributes as $attribute) {
            $attributeId = (int) ($attribute[EavAttribute::schema_fields_attribute_id] ?? 0);
            $options = $this->getAttributeOptions($attributeId);

            $result[] = [
                'attribute_id' => $attributeId,
                'code' => $attribute[EavAttribute::schema_fields_code] ?? '',
                'name' => $attribute[EavAttribute::schema_fields_name] ?? '',
                'type_id' => (int) ($attribute[EavAttribute::schema_fields_type_id] ?? 0),
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAttributeOptions(int $attributeId): array
    {
        $options = $this->attributeOption->reset()
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->select()
            ->fetch();

        if (!is_array($options)) {
            return [];
        }

        return array_map(function ($option) {
            return [
                'option_id' => (int) ($option[Option::schema_fields_option_id] ?? 0),
                'code' => $option[Option::schema_fields_code] ?? '',
                'value' => $option[Option::schema_fields_value] ?? '',
                'swatch_image' => $option[Option::schema_fields_swatch_image] ?? null,
                'swatch_color' => $option[Option::schema_fields_swatch_color] ?? null,
                'swatch_text' => $option[Option::schema_fields_swatch_text] ?? null,
                'is_swatch' => !empty($option[Option::schema_fields_swatch_image])
                    || !empty($option[Option::schema_fields_swatch_color])
                    || !empty($option[Option::schema_fields_swatch_text]),
            ];
        }, $options);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAttributeSets(): array
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return [];
        }

        $sets = $this->attributeSet->reset()
            ->where(Set::schema_fields_eav_entity_id, $productEntity->getId())
            ->select()
            ->fetch();

        return is_array($sets) ? $sets : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAttributeGroups(int $setId): array
    {
        $groups = $this->attributeGroup->reset()
            ->where(Group::schema_fields_set_id, $setId)
            ->order(Group::schema_fields_ID)
            ->select()
            ->fetch();

        return is_array($groups) ? $groups : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getGroupAttributes(int $entityId, int $setId, int $groupId): array
    {
        $attributes = $this->eavAttribute->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_set_id, $setId)
            ->where(EavAttribute::schema_fields_group_id, $groupId)
            ->where(EavAttribute::schema_fields_is_enable, 1)
            ->select()
            ->fetch();

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * @param array<string, mixed> $attributeData
     * @return array<string, mixed>|null
     */
    private function loadAttributeValue(array $attributeData, int $productId): ?array
    {
        $attributeId = (int) ($attributeData[EavAttribute::schema_fields_attribute_id] ?? 0);
        $hasOption = (bool) ($attributeData[EavAttribute::schema_fields_has_option] ?? false);
        $multipleValued = (bool) ($attributeData[EavAttribute::schema_fields_multiple_valued] ?? false);
        $typeId = (int) ($attributeData[EavAttribute::schema_fields_type_id] ?? 0);

        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->load($attributeId);

        if (!$attribute->getId()) {
            return null;
        }

        try {
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            $attribute->current_setEntity($product);
            $typeModel = $attribute->getTypeModel();

            $value = $attribute->getValue($productId);
            $displayValue = $value;
            $options = [];

            if ($hasOption && $value !== null && $value !== '') {
                $options = $attribute->getOptionsWithValue(true);

                if (is_array($options)) {
                    $displayValues = [];
                    foreach ($options as $option) {
                        if (isset($option['selected']) && $option['selected']) {
                            $displayValues[] = $option[Option::schema_fields_value] ?? $option['value'] ?? '';
                        }
                    }
                    $displayValue = implode(', ', $displayValues);
                }
            }

            return [
                'attribute_id' => $attributeId,
                'code' => $attributeData[EavAttribute::schema_fields_code] ?? '',
                'name' => $attributeData[EavAttribute::schema_fields_name] ?? '',
                'type_id' => $typeId,
                'type_code' => $typeModel->getCode(),
                'type_element' => $typeModel->getElement(),
                'has_option' => $hasOption,
                'multiple_valued' => $multipleValued,
                'frontend_is_filterable' => $attribute->isFilterable(),
                'frontend_is_searchable' => $attribute->isSearchable(),
                'frontend_is_visible' => $attribute->isVisibleOnFront(),
                'is_swatch' => $typeModel->isSwatch(),
                'swatch_color' => $typeModel->hasSwatchColor(),
                'swatch_image' => $typeModel->hasSwatchImage(),
                'swatch_text' => $typeModel->hasSwatchText(),
                'value' => $value,
                'display_value' => $displayValue,
                'options' => $hasOption ? $attribute->getOptions() : [],
                'selected_options' => $options,
            ];
        } catch (\Exception $e) {
            return [
                'attribute_id' => $attributeId,
                'code' => $attributeData[EavAttribute::schema_fields_code] ?? '',
                'name' => $attributeData[EavAttribute::schema_fields_name] ?? '',
                'type_id' => $typeId,
                'type_code' => '',
                'type_element' => '',
                'has_option' => $hasOption,
                'multiple_valued' => $multipleValued,
                'frontend_is_filterable' => (bool) ($attributeData[EavAttribute::schema_fields_frontend_is_filterable] ?? false),
                'frontend_is_searchable' => (bool) ($attributeData[EavAttribute::schema_fields_frontend_is_searchable] ?? false),
                'frontend_is_visible' => (bool) ($attributeData[EavAttribute::schema_fields_frontend_is_visible] ?? false),
                'is_swatch' => false,
                'swatch_color' => false,
                'swatch_image' => false,
                'swatch_text' => false,
                'value' => null,
                'display_value' => '',
                'options' => [],
                'selected_options' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{eav_search_text:array<int,string>,eav_facets:array<int,array<string,mixed>>}
     */
    public function getSearchIndexData(int $productId, ?int $setId = null): array
    {
        $groups = $this->getProductAttributes($productId, $setId);
        $searchTexts = [];
        $facets = [];

        foreach ($groups as $group) {
            foreach (($group['attributes'] ?? []) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }

                $displayValue = trim((string) ($attribute['display_value'] ?? ''));
                $attributeName = trim((string) ($attribute['name'] ?? ''));

                if (!empty($attribute['frontend_is_searchable']) && $displayValue !== '') {
                    $searchTexts[] = trim($attributeName . ' ' . $displayValue);
                }

                if (!empty($attribute['frontend_is_filterable'])) {
                    $facets = array_merge($facets, $this->buildFacetEntries($attribute));
                }
            }
        }

        return [
            'eav_search_text' => array_values(array_filter(array_unique($searchTexts))),
            'eav_facets' => array_values($facets),
        ];
    }

    /**
     * @param array<string, mixed> $attribute
     * @return array<int, array<string, mixed>>
     */
    private function buildFacetEntries(array $attribute): array
    {
        $base = [
            'attribute_id' => (int) ($attribute['attribute_id'] ?? 0),
            'attribute_code' => (string) ($attribute['code'] ?? ''),
            'attribute_label' => (string) ($attribute['name'] ?? ''),
            'display_type' => !empty($attribute['is_swatch']) ? 'swatch' : 'list',
            'has_option' => !empty($attribute['has_option']),
            'is_multiple' => !empty($attribute['multiple_valued']),
            'swatch_color' => null,
            'swatch_image' => null,
            'swatch_text' => null,
        ];

        $entries = [];

        if (!empty($attribute['has_option']) && is_array($attribute['selected_options'] ?? null)) {
            foreach ($attribute['selected_options'] as $option) {
                if (!is_array($option) || empty($option['selected'])) {
                    continue;
                }

                $valueKeyword = (string) ($option[Option::schema_fields_option_id] ?? $option['option_id'] ?? '');
                $valueText = trim((string) ($option[Option::schema_fields_value] ?? $option['value'] ?? $valueKeyword));
                if ($valueKeyword === '' && $valueText === '') {
                    continue;
                }

                $entries[] = array_merge($base, [
                    'value_keyword' => $valueKeyword !== '' ? $valueKeyword : $valueText,
                    'value_text' => $valueText !== '' ? $valueText : $valueKeyword,
                    'value_number' => is_numeric($valueKeyword) ? (float) $valueKeyword : null,
                    'swatch_color' => $option['swatch_color'] ?? null,
                    'swatch_image' => $option['swatch_image'] ?? null,
                    'swatch_text' => $option['swatch_text'] ?? null,
                ]);
            }

            return $entries;
        }

        foreach ($this->normalizeScalarValues($attribute['value'] ?? null) as $value) {
            $valueText = trim((string) ($attribute['display_value'] ?? $value));
            $entries[] = array_merge($base, [
                'value_keyword' => $value,
                'value_text' => $valueText !== '' ? $valueText : $value,
                'value_number' => is_numeric($value) ? (float) $value : null,
            ]);
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeScalarValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value)));
        }

        if (is_string($value) && str_contains($value, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [trim((string) $value)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProductAttributesViewModel(int $productId, ?int $setId = null, bool $includeEmptyValues = false): array
    {
        $attributes = $this->getProductAttributes($productId, $setId);
        $viewModel = [];

        foreach ($attributes as $group) {
            $groupAttributes = [];

            foreach ($group['attributes'] as $attribute) {
                if (!$includeEmptyValues && ($attribute['value'] === null || $attribute['value'] === '')) {
                    continue;
                }

                $groupAttributes[] = [
                    'label' => $attribute['name'],
                    'value' => $attribute['display_value'] ?: $attribute['value'],
                    'code' => $attribute['code'],
                    'is_swatch' => $this->hasSwatchOptions($attribute['selected_options']),
                    'swatch_data' => $this->getSwatchData($attribute['selected_options']),
                ];
            }

            if ($groupAttributes !== []) {
                $viewModel[] = [
                    'group_name' => $group['group_name'],
                    'group_id' => $group['group_id'],
                    'items' => $groupAttributes,
                ];
            }
        }

        return $viewModel;
    }

    private function hasSwatchOptions(array $options): bool
    {
        foreach ($options as $option) {
            if (!empty($option['swatch_image']) || !empty($option['swatch_color']) || !empty($option['swatch_text'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getSwatchData(array $options): array
    {
        $swatchData = [];

        foreach ($options as $option) {
            if (isset($option['selected']) && $option['selected']) {
                $swatchData[] = [
                    'type' => !empty($option['swatch_image']) ? 'image' : (!empty($option['swatch_color']) ? 'color' : 'text'),
                    'value' => $option['swatch_image'] ?? $option['swatch_color'] ?? $option['swatch_text'] ?? '',
                    'label' => $option[Option::schema_fields_value] ?? $option['value'] ?? '',
                ];
            }
        }

        return $swatchData;
    }

    public function getProductAttributeValue(int $productId, string $attributeCode)
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return null;
        }

        try {
            $attribute = $this->eavAttribute->reset()
                ->where(EavAttribute::schema_fields_eav_entity_id, $productEntity->getId())
                ->where(EavAttribute::schema_fields_code, $attributeCode)
                ->where(EavAttribute::schema_fields_is_enable, 1)
                ->find()
                ->fetch();

            if (!$attribute->getId()) {
                return null;
            }

            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            $attribute->current_setEntity($product);

            return $attribute->getValue($productId);
        } catch (\Exception) {
            return null;
        }
    }
}
