<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * EAV attribute filter/search metadata service.
 */
class AttributeFilterService
{
    /**
     * @var array<string, mixed>
     */
    private array $attributeCache = [];

    /**
     * @var array<string, mixed>
     */
    private array $optionCache = [];

    public function __construct(
        private readonly EventsManager $eventsManager
    ) {
    }

    /**
     * @param array<int, int|string> $entityIds
     * @param array<int, string> $attributeCodes
     * @return array<string, array<string, mixed>>
     */
    public function getFilterableAttributes(
        string $entityCode,
        array $entityIds,
        array $attributeCodes = []
    ): array {
        if ($entityIds === []) {
            return $this->getFilterableAttributeMetadata($entityCode, $attributeCodes);
        }

        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return [];
        }

        $attributes = $this->getEntityAttributes($entity, $attributeCodes, true, false);
        if ($attributes === []) {
            return [];
        }

        $result = $this->buildAttributeDataWithValues($attributes, $entityIds);

        $eventData = [
            'entity_code' => $entityCode,
            'entity_ids' => $entityIds,
            'attribute_codes' => $attributeCodes,
            'options' => &$result,
        ];
        $this->eventsManager->dispatch('Weline_Eav::attribute_filter_options', $eventData);

        return $result;
    }

    /**
     * @param array<int, string> $attributeCodes
     * @return array<string, array<string, mixed>>
     */
    public function getFilterableAttributeMetadata(
        string $entityCode,
        array $attributeCodes = [],
        ?int $setId = null
    ): array {
        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return [];
        }

        return $this->buildAttributeMetadataResult(
            $this->getEntityAttributes($entity, $attributeCodes, true, false, $setId)
        );
    }

    /**
     * @param array<int, string> $attributeCodes
     * @return array<string, array<string, mixed>>
     */
    public function getSearchableAttributeMetadata(
        string $entityCode,
        array $attributeCodes = [],
        ?int $setId = null
    ): array {
        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return [];
        }

        return $this->buildAttributeMetadataResult(
            $this->getEntityAttributes($entity, $attributeCodes, false, true, $setId)
        );
    }

    /**
     * @param array<int, int|string> $entityIds
     * @param array<string, array<int|string>|int|string> $filters
     * @return array<int, int>
     */
    public function filterByAttributes(
        string $entityCode,
        array $entityIds,
        array $filters,
        string $logic = 'AND'
    ): array {
        if ($entityIds === [] || $filters === []) {
            return array_values(array_map('intval', $entityIds));
        }

        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return array_values(array_map('intval', $entityIds));
        }

        $filteredIds = array_values(array_map('intval', $entityIds));
        $matchedIdsByFilter = [];

        foreach ($filters as $attributeCode => $values) {
            if ($values === [] || $values === '' || $values === null) {
                continue;
            }

            if (!is_array($values)) {
                $values = [$values];
            }

            $attribute = $this->getAttribute($entity, (string) $attributeCode);
            if (!$attribute || !$attribute->getId()) {
                continue;
            }

            $matchedIds = $this->getEntitiesByAttributeValues($attribute, $filteredIds, $values);

            if (strtoupper($logic) === 'AND') {
                $filteredIds = $matchedIds;
                if ($filteredIds === []) {
                    break;
                }
            } else {
                $matchedIdsByFilter[] = $matchedIds;
            }
        }

        if (strtoupper($logic) === 'OR' && $matchedIdsByFilter !== []) {
            $allMatchedIds = array_merge(...$matchedIdsByFilter);
            $filteredIds = array_values(array_unique(array_intersect(
                array_values(array_map('intval', $entityIds)),
                array_map('intval', $allMatchedIds)
            )));
        }

        $eventData = [
            'entity_code' => $entityCode,
            'original_ids' => $entityIds,
            'filtered_ids' => &$filteredIds,
            'filters' => $filters,
        ];
        $this->eventsManager->dispatch('Weline_Eav::attribute_filter_apply', $eventData);

        return $filteredIds;
    }

    /**
     * @param array<int, int|string> $entityIds
     * @param array<int, string> $attributeCodes
     * @return array<string, array<string, int>>
     */
    public function getAttributeValueCounts(
        string $entityCode,
        array $entityIds,
        array $attributeCodes
    ): array {
        if ($entityIds === [] || $attributeCodes === []) {
            return [];
        }

        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return [];
        }

        $result = [];

        foreach ($attributeCodes as $attributeCode) {
            $attribute = $this->getAttribute($entity, $attributeCode);
            if (!$attribute || !$attribute->getId()) {
                continue;
            }

            $valuesData = $this->getAttributeValuesWithCounts($attribute, $entityIds);
            $result[$attributeCode] = $valuesData['counts'];
        }

        return $result;
    }

    /**
     * @param array<int, int|string> $entityIds
     * @return array<int, array<string, mixed>>
     */
    public function getFilterableAttributesByGroup(
        string $entityCode,
        array $entityIds,
        ?int $setId = null
    ): array {
        $filterableAttributes = $entityIds === []
            ? $this->getFilterableAttributeMetadata($entityCode, [], $setId)
            : $this->getFilterableAttributes($entityCode, $entityIds);

        if ($filterableAttributes === []) {
            return [];
        }

        $grouped = [];

        foreach ($filterableAttributes as $code => $data) {
            $groupId = (int) ($data['attribute']['group_id'] ?? 0);
            $attributeSetId = (int) ($data['attribute']['set_id'] ?? 0);

            if ($setId !== null && $attributeSetId !== $setId) {
                continue;
            }

            if (!isset($grouped[$groupId])) {
                $group = $this->getAttributeGroup($groupId);
                $grouped[$groupId] = [
                    'group_id' => $groupId,
                    'group_name' => $group ? (string) $group->getData('name') : (string) __('鍏朵粬'),
                    'group_code' => $group ? (string) $group->getData('code') : 'other',
                    'attributes' => [],
                ];
            }

            $grouped[$groupId]['attributes'][$code] = $data;
        }

        return array_values($grouped);
    }

    private function getEntity(string $entityCode): ?EavEntity
    {
        try {
            /** @var EavEntity $entityModel */
            $entityModel = ObjectManager::getInstance(EavEntity::class);
            $entityModel->load('code', $entityCode);

            return $entityModel->getId() ? $entityModel : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, string> $attributeCodes
     * @return array<int, EavAttribute>
     */
    private function getEntityAttributes(
        EavEntity $entity,
        array $attributeCodes = [],
        bool $filterableOnly = false,
        bool $searchableOnly = false,
        ?int $setId = null
    ): array {
        $cacheKey = implode(':', [
            (string) $entity->getId(),
            implode(',', $attributeCodes),
            $filterableOnly ? '1' : '0',
            $searchableOnly ? '1' : '0',
            (string) ($setId ?? 0),
        ]);

        if (isset($this->attributeCache[$cacheKey])) {
            return $this->attributeCache[$cacheKey];
        }

        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $attributeModel->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entity->getId())
            ->where(EavAttribute::schema_fields_is_enable, 1);

        if ($setId !== null) {
            $attributeModel->where(EavAttribute::schema_fields_set_id, $setId);
        }

        if ($filterableOnly) {
            $attributeModel->where(EavAttribute::schema_fields_is_filterable, 1);
        }

        if ($searchableOnly) {
            $attributeModel->where(EavAttribute::schema_fields_is_searchable, 1);
        }

        if ($attributeCodes !== []) {
            $attributeModel->where(EavAttribute::schema_fields_code, $attributeCodes, 'in');
        }

        $attributeModel->order(EavAttribute::schema_fields_group_id)
            ->order(EavAttribute::schema_fields_attribute_id);

        $results = $attributeModel->select()->fetch();
        $attributes = [];

        foreach ($results as $item) {
            if ($item instanceof EavAttribute) {
                $attributes[] = $item;
                continue;
            }

            if (is_array($item)) {
                /** @var EavAttribute $attribute */
                $attribute = ObjectManager::getInstance(EavAttribute::class);
                $attribute->setData($item);
                $attributes[] = $attribute;
            }
        }

        $this->attributeCache[$cacheKey] = $attributes;

        return $attributes;
    }

    private function getAttribute(EavEntity $entity, string $attributeCode): ?EavAttribute
    {
        $cacheKey = $entity->getId() . '_' . $attributeCode;

        if (isset($this->attributeCache[$cacheKey])) {
            $cached = $this->attributeCache[$cacheKey];
            return $cached instanceof EavAttribute ? $cached : null;
        }

        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $attributeModel->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entity->getId())
            ->where(EavAttribute::schema_fields_code, $attributeCode)
            ->where(EavAttribute::schema_fields_is_enable, 1);

        $attribute = $attributeModel->find()->fetch();

        if ($attribute instanceof EavAttribute && $attribute->getId()) {
            $this->attributeCache[$cacheKey] = $attribute;
            return $attribute;
        }

        return null;
    }

    /**
     * @param array<int, EavAttribute> $attributes
     * @return array<string, array<string, mixed>>
     */
    private function buildAttributeMetadataResult(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof EavAttribute || !$attribute->getId()) {
                continue;
            }

            $attributeCode = $attribute->getCode();
            $result[$attributeCode] = [
                'attribute' => $this->mapAttribute($attribute),
                'options' => $attribute->hasOption() ? $this->getAttributeOptions($attribute) : [],
                'values' => [],
                'counts' => [],
            ];
        }

        return $result;
    }

    /**
     * @param array<int, EavAttribute> $attributes
     * @param array<int, int|string> $entityIds
     * @return array<string, array<string, mixed>>
     */
    private function buildAttributeDataWithValues(array $attributes, array $entityIds): array
    {
        $result = [];

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof EavAttribute || !$attribute->getId()) {
                continue;
            }

            $valuesData = $this->getAttributeValuesWithCounts($attribute, $entityIds);
            if ($valuesData['values'] === []) {
                continue;
            }

            $attributeCode = $attribute->getCode();
            $result[$attributeCode] = [
                'attribute' => $this->mapAttribute($attribute),
                'options' => $attribute->hasOption() ? $this->getAttributeOptions($attribute) : [],
                'values' => $valuesData['values'],
                'counts' => $valuesData['counts'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAttribute(EavAttribute $attribute): array
    {
        $typeCode = '';
        $typeElement = '';
        $isSwatch = false;
        $swatchColor = false;
        $swatchImage = false;
        $swatchText = false;

        try {
            $type = $attribute->getTypeModel();
            $typeCode = $type->getCode();
            $typeElement = $type->getElement();
            $isSwatch = $type->isSwatch();
            $swatchColor = $type->hasSwatchColor();
            $swatchImage = $type->hasSwatchImage();
            $swatchText = $type->hasSwatchText();
        } catch (\Throwable) {
        }

        return [
            'attribute_id' => (int) $attribute->getId(),
            'code' => $attribute->getCode(),
            'name' => $attribute->getName(),
            'type_id' => $attribute->getTypeId(),
            'type_code' => $typeCode,
            'type_element' => $typeElement,
            'set_id' => $attribute->getSetId(),
            'group_id' => $attribute->getGroupId(),
            'frontend_is_visible' => $attribute->isVisibleOnFront(),
            'frontend_is_filterable' => $attribute->isFilterable(),
            'frontend_is_searchable' => $attribute->isSearchable(),
            'data_has_option' => $attribute->hasOption(),
            'data_is_multiple' => $attribute->getMultipleValued(),
            'has_option' => $attribute->hasOption(),
            'is_multiple' => $attribute->getMultipleValued(),
            'is_swatch' => $isSwatch,
            'swatch_color' => $swatchColor,
            'swatch_image' => $swatchImage,
            'swatch_text' => $swatchText,
        ];
    }

    /**
     * @param array<int, int|string> $entityIds
     * @return array{values:array<int, string>, counts:array<string, int>}
     */
    private function getAttributeValuesWithCounts(EavAttribute $attribute, array $entityIds): array
    {
        if (!$attribute->getId() || $entityIds === []) {
            return ['values' => [], 'counts' => []];
        }

        $valueModel = $attribute->w_getValueModel();
        $valueModel->reset()
            ->fields(['value', 'COUNT(DISTINCT entity_id) as count'])
            ->where('attribute_id', $attribute->getId())
            ->where('entity_id', array_values(array_map('intval', $entityIds)), 'in')
            ->where('value', null, 'IS NOT NULL')
            ->where('value', '', '!=')
            ->groupBy('value');

        $results = $valueModel->select()->fetchArray();
        $values = [];
        $counts = [];

        foreach ($results as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $values[] = $value;
            $counts[$value] = (int) ($row['count'] ?? 0);
        }

        return ['values' => $values, 'counts' => $counts];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAttributeOptions(EavAttribute $attribute): array
    {
        if (!$attribute->getId()) {
            return [];
        }

        $cacheKey = 'options_' . $attribute->getId();

        if (isset($this->optionCache[$cacheKey])) {
            return $this->optionCache[$cacheKey];
        }

        /** @var Option $optionModel */
        $optionModel = ObjectManager::getInstance(Option::class);
        $optionModel->reset()
            ->where(Option::schema_fields_attribute_id, $attribute->getId())
            ->order(Option::schema_fields_option_id);

        $results = $optionModel->select()->fetchArray();
        $options = [];

        foreach ($results as $row) {
            $optionId = (string) ($row[Option::schema_fields_option_id] ?? '');
            if ($optionId === '') {
                continue;
            }

            $options[$optionId] = [
                'option_id' => $row[Option::schema_fields_option_id],
                'code' => $row[Option::schema_fields_code] ?? '',
                'value' => $row[Option::schema_fields_value] ?? '',
                'swatch_image' => $row[Option::schema_fields_swatch_image] ?? null,
                'swatch_color' => $row[Option::schema_fields_swatch_color] ?? null,
                'swatch_text' => $row[Option::schema_fields_swatch_text] ?? null,
            ];
        }

        $this->optionCache[$cacheKey] = $options;

        return $options;
    }

    /**
     * @param array<int, int> $entityIds
     * @param array<int, int|string> $values
     * @return array<int, int>
     */
    private function getEntitiesByAttributeValues(
        EavAttribute $attribute,
        array $entityIds,
        array $values
    ): array {
        if ($entityIds === [] || $values === []) {
            return [];
        }

        $valueModel = $attribute->w_getValueModel();
        $valueModel->reset()
            ->fields('DISTINCT entity_id')
            ->where('attribute_id', $attribute->getId())
            ->where('entity_id', array_values(array_map('intval', $entityIds)), 'in')
            ->where('value', array_values(array_map('strval', $values)), 'in');

        $results = $valueModel->select()->fetchArray();

        return array_values(array_unique(array_map('intval', array_column($results, 'entity_id'))));
    }

    private function getAttributeGroup(int $groupId): ?Group
    {
        if ($groupId <= 0) {
            return null;
        }

        try {
            /** @var Group $groupModel */
            $groupModel = ObjectManager::getInstance(Group::class);
            $groupModel->load($groupId);

            return $groupModel->getId() ? $groupModel : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function clearCache(): void
    {
        $this->attributeCache = [];
        $this->optionCache = [];
    }
}
