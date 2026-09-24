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
    /**
     * @var array<string, string>
     */
    private array $entityColumnCache = [];

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
        array $attributeCodes = [],
        bool $includeOptions = true,
    ): array {
        if ($entityIds === []) {
            return $this->getFilterableAttributeMetadata($entityCode, $attributeCodes, null, $includeOptions);
        }

        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return [];
        }

        $attributes = $this->getEntityAttributes($entity, $attributeCodes, true, false);
        if ($attributes === []) {
            return [];
        }

        $result = $this->buildAttributeDataWithValues($attributes, $entityIds, $includeOptions);

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
        ?int $setId = null,
        bool $includeOptions = true,
    ): array {
        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return [];
        }

        return $this->buildAttributeMetadataResult(
            $this->getEntityAttributes($entity, $attributeCodes, true, false, $setId),
            $includeOptions,
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

        $codes = [];
        foreach ($filters as $code => $values) {
            if ($values !== [] && $values !== '' && $values !== null) {
                $codes[] = (string)$code;
            }
        }
        $attributes = [];
        if ($codes !== []) {
            foreach ($this->getEntityAttributes($entity, $codes, false, false, null) as $attribute) {
                $attributes[$attribute->getCode()] ??= $attribute;
            }
        }
        $ordered = [];
        foreach ($filters as $code => $values) {
            if ($values === [] || $values === '' || $values === null || $attributes === []) {
                continue;
            }
            // A DB collation may match a differently cased caller code.
            $attribute = $attributes[$code] ?? $this->getAttribute($entity, (string)$code);
            if (!$attribute || $this->resolveAttributeId($attribute) <= 0) {
                continue;
            }
            $ordered[] = ['attribute' => $attribute, 'values' => is_array($values) ? $values : [$values]];
        }
        $filteredIds = $this->filterAttributeMatches($ordered, $entityIds, $logic);

        $eventData = [
            'entity_code' => $entityCode,
            'original_ids' => $entityIds,
            'filtered_ids' => &$filteredIds,
            'filters' => $filters,
        ];
        $this->eventsManager->dispatch('Weline_Eav::attribute_filter_apply', $eventData);

        return $filteredIds;
    }

    /** Execute contiguous physical-table groups, retaining AND short-circuit between groups. */
    private function filterAttributeMatches(array $filters, array $entityIds, string $logic): array
    {
        $original = array_values(array_map('intval', $entityIds));
        $filtered = $original;
        $union = [];
        $hasMatches = false;
        $logic = strtoupper($logic);
        $offset = 0;
        while ($offset < count($filters)) {
            $first = $filters[$offset]['attribute'];
            $model = clone $first->w_getValueModel();
            $key = $this->valueModelIdentity($model);
            $group = [];
            $seenIds = [];
            do {
                $seenIds[$this->resolveAttributeId($filters[$offset]['attribute'])] = true;
                $group[] = $filters[$offset++];
            } while ($offset < count($filters)
                && !isset($seenIds[$this->resolveAttributeId($filters[$offset]['attribute'])])
                && $this->valueModelIdentity($filters[$offset]['attribute']->w_getValueModel()) === $key);

            $model->reset();
            // Index-oriented condition reordering would split the OR arms below.
            $query = $model->getQuery();
            $query->_index_sort_keys = [];
            $query->fields(['attribute_id', 'entity_id'])->group('attribute_id, entity_id');
            foreach ($group as $filter) {
                $query->where('attribute_id', $this->resolveAttributeId($filter['attribute']))
                    ->where('entity_id', $filtered, 'in')
                    ->where('value', array_values(array_map('strval', $filter['values'])), 'in', 'OR');
            }
            $matches = [];
            foreach ($query->select()->fetchArray() as $row) {
                $matches[(int)$row['attribute_id']][] = (int)$row['entity_id'];
            }
            foreach ($group as $filter) {
                $matched = array_values(array_unique($matches[$this->resolveAttributeId($filter['attribute'])] ?? []));
                $hasMatches = true;
                if ($logic === 'AND') {
                    $filtered = array_values(array_intersect($matched, $filtered));
                    if ($filtered === []) {
                        return [];
                    }
                } else {
                    $union = array_merge($union, $matched);
                }
            }
        }
        return $logic === 'OR' && $hasMatches
            ? array_values(array_unique(array_intersect($original, $union)))
            : $filtered;
    }

    private function valueModelIdentity(object $model): string
    {
        $config = $model->getConnection()->getConnector()->getConfigProvider();
        return serialize([
            get_class($model), $model->getTable(),
            $config->getDbType(), $config->getHostName(), $config->getHostPort(),
            $config->getDatabase(),
            method_exists($config, 'getData') ? (string)$config->getData('path') : '',
            $config->getUsername(),
        ]);
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
                    'group_name' => $group ? (string) $group->getData('name') : (string) __('其他'),
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
            $entityModel = $this->freshModel(EavEntity::class);
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

        $results = $this->queryEntityAttributes(
            $entity,
            $attributeCodes,
            $filterableOnly,
            $searchableOnly,
            $setId
        );
        $attributes = [];

        foreach ($results as $item) {
            if (is_array($item)) {
                $attribute = $this->hydrateAttribute($item);
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

        $row = $this->querySingleAttribute($entity, $attributeCode);

        if (is_array($row) && $row !== []) {
            $attribute = $this->hydrateAttribute($row);
            $this->attributeCache[$cacheKey] = $attribute;
            return $attribute;
        }

        return null;
    }

    /**
     * Some legacy databases still use entity_id instead of eav_entity_id.
     * Retry with legacy column when first query fails.
     *
     * @param array<int, string> $attributeCodes
     * @return array<int, array<string, mixed>>
     */
    private function queryEntityAttributes(
        EavEntity $entity,
        array $attributeCodes,
        bool $filterableOnly,
        bool $searchableOnly,
        ?int $setId
    ): array {
        $entityColumn = $this->resolveEntityColumn('list');

        try {
            return $this->buildEntityAttributesQuery(
                $entity,
                $attributeCodes,
                $filterableOnly,
                $searchableOnly,
                $setId,
                $entityColumn
            )->select()->fetchArray();
        } catch (\Throwable $throwable) {
            $fallbackColumn = $entityColumn === 'entity_id'
                ? EavAttribute::schema_fields_eav_entity_id
                : 'entity_id';
            try {
                $result = $this->buildEntityAttributesQuery(
                    $entity,
                    $attributeCodes,
                    $filterableOnly,
                    $searchableOnly,
                    $setId,
                    $fallbackColumn
                )->select()->fetchArray();
                $this->entityColumnCache['list'] = $fallbackColumn;
                return $result;
            } catch (\Throwable) {
                throw $throwable;
            }
        }
    }

    private function querySingleAttribute(EavEntity $entity, string $attributeCode): array
    {
        $entityColumn = $this->resolveEntityColumn('single');

        try {
            return $this->buildSingleAttributeQuery($entity, $attributeCode, $entityColumn)
                ->find()
                ->fetchArray();
        } catch (\Throwable $throwable) {
            $fallbackColumn = $entityColumn === 'entity_id'
                ? EavAttribute::schema_fields_eav_entity_id
                : 'entity_id';
            try {
                $result = $this->buildSingleAttributeQuery($entity, $attributeCode, $fallbackColumn)
                    ->find()
                    ->fetchArray();
                $this->entityColumnCache['single'] = $fallbackColumn;
                return $result;
            } catch (\Throwable) {
                throw $throwable;
            }
        }
    }

    /**
     * @param array<int, string> $attributeCodes
     */
    private function buildEntityAttributesQuery(
        EavEntity $entity,
        array $attributeCodes,
        bool $filterableOnly,
        bool $searchableOnly,
        ?int $setId,
        string $entityColumn
    ): EavAttribute {
        /** @var EavAttribute $attributeModel */
        $attributeModel = $this->freshModel(EavAttribute::class);
        $attributeModel->fields('main_table.*')
            ->where('main_table.' . $entityColumn, $entity->getId())
            ->where('main_table.' . EavAttribute::schema_fields_is_enable, 1);

        if ($setId !== null) {
            $attributeModel->where('main_table.' . EavAttribute::schema_fields_set_id, $setId);
        }

        if ($filterableOnly) {
            $attributeModel->where('main_table.' . EavAttribute::schema_fields_is_filterable, 1);
        }

        if ($searchableOnly) {
            $attributeModel->where('main_table.' . EavAttribute::schema_fields_is_searchable, 1);
        }

        if ($attributeCodes !== []) {
            $attributeModel->where('main_table.' . EavAttribute::schema_fields_code, $attributeCodes, 'in');
        }

        $attributeModel->order('main_table.' . EavAttribute::schema_fields_group_id)
            ->order('main_table.' . EavAttribute::schema_fields_attribute_id);

        return $attributeModel;
    }

    private function buildSingleAttributeQuery(
        EavEntity $entity,
        string $attributeCode,
        string $entityColumn
    ): EavAttribute {
        /** @var EavAttribute $attributeModel */
        $attributeModel = $this->freshModel(EavAttribute::class);
        $attributeModel->fields('main_table.*')
            ->where('main_table.' . $entityColumn, $entity->getId())
            ->where('main_table.' . EavAttribute::schema_fields_code, $attributeCode)
            ->where('main_table.' . EavAttribute::schema_fields_is_enable, 1);

        return $attributeModel;
    }

    private function resolveEntityColumn(string $scope): string
    {
        return $this->entityColumnCache[$scope] ?? EavAttribute::schema_fields_eav_entity_id;
    }

    /**
     * @param array<int, EavAttribute> $attributes
     * @return array<string, array<string, mixed>>
     */
    private function buildAttributeMetadataResult(array $attributes, bool $includeOptions = true): array
    {
        $result = [];
        if ($includeOptions) {
            $this->preloadAttributeOptions($attributes);
        }

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof EavAttribute || $this->resolveAttributeId($attribute) <= 0) {
                continue;
            }

            $attributeCode = $attribute->getCode();
            $result[$attributeCode] = [
                'attribute' => $this->mapAttribute($attribute),
                'options' => $includeOptions && $attribute->hasOption()
                    ? $this->getAttributeOptions($attribute)
                    : [],
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
    private function buildAttributeDataWithValues(
        array $attributes,
        array $entityIds,
        bool $includeOptions = true,
    ): array
    {
        $result = [];
        $valuesByAttribute = $this->getAttributeValuesInBatches($attributes, $entityIds);
        if ($includeOptions) {
            $this->preloadAttributeOptions($attributes, $valuesByAttribute);
        }

        foreach ($attributes as $index => $attribute) {
            if (!$attribute instanceof EavAttribute || $this->resolveAttributeId($attribute) <= 0) {
                continue;
            }

            $valuesData = $valuesByAttribute[$index] ?? ['values' => [], 'counts' => []];
            if ($valuesData['values'] === []) {
                continue;
            }

            $attributeCode = $attribute->getCode();
            $result[$attributeCode] = [
                'attribute' => $this->mapAttribute($attribute),
                'options' => $includeOptions && $attribute->hasOption()
                    ? $this->getAttributeOptions($attribute)
                    : [],
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
        $attributeId = $this->resolveAttributeId($attribute);
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
            'attribute_id' => $attributeId,
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
     * @param array<int, EavAttribute> $attributes
     * @param array<int, int|string> $entityIds
     * @return array<int, array{values: list<string>, counts: array<string, int>}>
     */
    private function getAttributeValuesInBatches(array $attributes, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $groups = [];
        foreach ($attributes as $index => $attribute) {
            if (!$attribute instanceof EavAttribute || ($attributeId = $this->resolveAttributeId($attribute)) <= 0) {
                continue;
            }
            $valueModel = $attribute->w_getValueModel();
            $table = $valueModel->getTable();
            $config = $valueModel->getConnection()->getConnector()->getConfigProvider();
            // Match the configured database identity, not a transient model wrapper.
            $key = serialize([
                get_class($valueModel), $table,
                $config->getDbType(), $config->getHostName(), $config->getHostPort(),
                $config->getDatabase(),
                method_exists($config, 'getData') ? (string)$config->getData('path') : '',
                $config->getUsername(),
            ]);
            if (!isset($groups[$key])) {
                $groups[$key] = ['model' => clone $valueModel, 'attributes' => []];
            }
            $groups[$key]['attributes'][$index] = $attributeId;
        }

        $result = [];
        $entityIds = array_values(array_map('intval', $entityIds));
        foreach ($groups as $group) {
            $rows = $group['model']->reset()
                ->fields(['attribute_id', 'value', 'COUNT(DISTINCT entity_id) as count'])
                ->where('attribute_id', array_values(array_unique($group['attributes'])), 'in')
                ->where('entity_id', $entityIds, 'in')
                ->where('value', null, 'IS NOT NULL')
                ->where('value', '', '!=')
                ->group('attribute_id, value')
                ->select()->fetchArray();
            $byId = [];
            foreach ($rows as $row) {
                $value = (string)($row['value'] ?? '');
                if ($value === '') {
                    continue;
                }
                $attributeId = (int)($row['attribute_id'] ?? 0);
                $byId[$attributeId]['values'][] = $value;
                $byId[$attributeId]['counts'][$value] = (int)($row['count'] ?? 0);
            }
            // The same attribute ID can occur in another table or connection.
            foreach ($group['attributes'] as $index => $attributeId) {
                $result[$index] = $byId[$attributeId] ?? ['values' => [], 'counts' => []];
            }
        }

        return $result;
    }

    /**
     * @param array<int, int|string> $entityIds
     * @return array{values:array<int, string>, counts:array<string, int>}
     */
    private function getAttributeValuesWithCounts(EavAttribute $attribute, array $entityIds): array
    {
        $attributeId = $this->resolveAttributeId($attribute);
        if ($attributeId <= 0 || $entityIds === []) {
            return ['values' => [], 'counts' => []];
        }

        $valueModel = $attribute->w_getValueModel();
        $valueModel->reset()
            ->fields(['value', 'COUNT(DISTINCT entity_id) as count'])
            ->where('attribute_id', $attributeId)
            ->where('entity_id', array_values(array_map('intval', $entityIds)), 'in')
            ->where('value', null, 'IS NOT NULL')
            ->where('value', '', '!=')
            ->group('value');

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
        $attributeId = $this->resolveAttributeId($attribute);
        if ($attributeId <= 0) {
            return [];
        }

        $this->loadAttributeOptionsByIds([$attributeId]);
        return $this->optionCache['options_' . $attributeId];
    }

    /**
     * @param array<int, EavAttribute> $attributes
     * @param array<int, array{values: list<string>, counts: array<string, int>}>|null $valuesByAttribute
     */
    private function preloadAttributeOptions(array $attributes, ?array $valuesByAttribute = null): void
    {
        $attributeIds = [];
        foreach ($attributes as $index => $attribute) {
            if (!$attribute instanceof EavAttribute
                || ($valuesByAttribute !== null && empty($valuesByAttribute[$index]['values']))
                || !$attribute->hasOption()) {
                continue;
            }
            $attributeIds[] = $this->resolveAttributeId($attribute);
        }
        $this->loadAttributeOptionsByIds($attributeIds);
    }

    /** @param list<int> $attributeIds */
    private function loadAttributeOptionsByIds(array $attributeIds): void
    {
        $missing = [];
        foreach ($attributeIds as $attributeId) {
            if ($attributeId > 0 && !isset($this->optionCache['options_' . $attributeId])) {
                $missing[$attributeId] = $attributeId;
            }
        }
        if ($missing === []) {
            return;
        }

        /** @var Option $optionModel */
        $optionModel = $this->freshModel(Option::class);
        $optionModel->fields('main_table.*')
            ->where('main_table.' . Option::schema_fields_attribute_id, array_values($missing), 'in')
            ->where('main_table.' . Option::schema_fields_scope_instance_id, Option::SCOPE_SHARED)
            ->order('main_table.' . Option::schema_fields_option_id);

        $results = $optionModel->select()->fetchArray();
        $optionsByAttribute = array_fill_keys(array_keys($missing), []);
        foreach ($results as $row) {
            $attributeId = (int)($row[Option::schema_fields_attribute_id] ?? 0);
            $optionId = (string)($row[Option::schema_fields_option_id] ?? '');
            if ($optionId === '' || !isset($optionsByAttribute[$attributeId])) {
                continue;
            }
            $optionsByAttribute[$attributeId][$optionId] = [
                'option_id' => $row[Option::schema_fields_option_id],
                'code' => $row[Option::schema_fields_code] ?? '',
                'value' => $row[Option::schema_fields_value] ?? '',
                'swatch_image' => $row[Option::schema_fields_swatch_image] ?? null,
                'swatch_color' => $row[Option::schema_fields_swatch_color] ?? null,
                'swatch_text' => $row[Option::schema_fields_swatch_text] ?? null,
            ];
        }

        foreach ($optionsByAttribute as $attributeId => $options) {
            $this->optionCache['options_' . $attributeId] = $options;
        }
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
        $attributeId = $this->resolveAttributeId($attribute);
        if ($attributeId <= 0 || $entityIds === [] || $values === []) {
            return [];
        }

        $valueModel = $attribute->w_getValueModel();
        $valueModel->reset()
            ->fields('DISTINCT entity_id')
            ->where('attribute_id', $attributeId)
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
            $groupModel = $this->freshModel(Group::class);
            $groupModel->load($groupId);

            return $groupModel->getId() ? $groupModel : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * WLS keeps ObjectManager singletons alive across requests, so queryable models
     * must be cloned and reset before reuse to avoid leaking prior query state.
     */
    private function freshModel(string $class): object
    {
        $model = clone ObjectManager::getInstance($class);
        if (method_exists($model, 'reset')) {
            $model->reset();
        }
        if (method_exists($model, 'clearData')) {
            $model->clearData();
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAttribute(array $row): EavAttribute
    {
        /** @var EavAttribute $attribute */
        $attribute = $this->freshModel(EavAttribute::class);
        $attribute->setData($row);
        try {
            $entityId = (int) ($row[EavAttribute::schema_fields_eav_entity_id] ?? 0);
            if ($entityId > 0) {
                /** @var EavEntity $entity */
                $entity = $this->freshModel(EavEntity::class);
                $entity->load($entityId);
                if ($entity->getId()) {
                    $entityClass = (string) ($entity->getData('class') ?? '');
                    if ($entityClass !== '' && class_exists($entityClass)) {
                        $entityModel = $this->freshModel($entityClass);
                        if ($entityModel instanceof \Weline\Eav\EavModel) {
                            $attribute->current_setEntity($entityModel);
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $attribute;
    }

    private function resolveAttributeId(EavAttribute $attribute): int
    {
        return $attribute->getAttributeId();
    }

    public function clearCache(): void
    {
        $this->attributeCache = [];
        $this->optionCache = [];
        $this->entityColumnCache = [];
    }
}
