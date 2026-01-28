<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavEntity;
use Weline\Eav\EavModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;

/**
 * EAV属性筛选服务
 * 
 * 提供基于EAV属性的筛选功能
 */
class AttributeFilterService
{
    /**
     * @var EventsManager
     */
    private EventsManager $eventsManager;
    
    /**
     * @var array 缓存的属性数据
     */
    private array $attributeCache = [];
    
    /**
     * @var array 缓存的选项数据
     */
    private array $optionCache = [];
    
    public function __construct(EventsManager $eventsManager)
    {
        $this->eventsManager = $eventsManager;
    }
    
    /**
     * 获取实体的可筛选属性
     * 
     * @param string $entityCode 实体代码(如 'product')
     * @param array $entityIds 实体ID列表
     * @param array $attributeCodes 指定属性代码(可选)
     * @return array 返回属性及其选项
     * [
     *     'attribute_code' => [
     *         'attribute' => [...], // 属性信息
     *         'options' => [...], // 可选值
     *         'values' => [...], // 当前实体的值
     *         'counts' => [...], // 每个值的实体计数
     *     ],
     *     ...
     * ]
     */
    public function getFilterableAttributes(
        string $entityCode,
        array $entityIds,
        array $attributeCodes = []
    ): array {
        if (empty($entityIds)) {
            return [];
        }
        
        // 获取实体
        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return [];
        }
        
        // 获取实体的属性
        $attributes = $this->getEntityAttributes($entity, $attributeCodes);
        
        if (empty($attributes)) {
            return [];
        }
        
        $result = [];
        
        foreach ($attributes as $attribute) {
            $attributeCode = $attribute->getCode();
            
            // 获取属性值和计数
            $valuesData = $this->getAttributeValuesWithCounts($attribute, $entityIds);
            
            if (empty($valuesData['values'])) {
                continue;
            }
            
            // 获取属性选项（如果有）
            $options = [];
            if ($attribute->hasOption()) {
                $options = $this->getAttributeOptions($attribute);
            }
            
            $result[$attributeCode] = [
                'attribute' => [
                    'attribute_id' => $attribute->getId(),
                    'code' => $attributeCode,
                    'name' => $attribute->getName(),
                    'type_id' => $attribute->getTypeId(),
                    'set_id' => $attribute->getSetId(),
                    'group_id' => $attribute->getGroupId(),
                    'has_option' => $attribute->hasOption(),
                    'multiple_valued' => $attribute->getMultipleValued(),
                ],
                'options' => $options,
                'values' => $valuesData['values'],
                'counts' => $valuesData['counts'],
            ];
        }
        
        // 触发事件允许其他模块修改
        $this->eventsManager->dispatch('Weline_Eav::attribute_filter_options', [
            'entity_code' => $entityCode,
            'entity_ids' => $entityIds,
            'attribute_codes' => $attributeCodes,
            'options' => &$result,
        ]);
        
        return $result;
    }
    
    /**
     * 按属性值筛选实体
     * 
     * @param string $entityCode
     * @param array $entityIds
     * @param array $filters ['color' => ['red', 'blue'], 'size' => ['M']]
     * @param string $logic 多个属性之间的逻辑关系 'AND'|'OR'
     * @return array 筛选后的实体ID
     */
    public function filterByAttributes(
        string $entityCode,
        array $entityIds,
        array $filters,
        string $logic = 'AND'
    ): array {
        if (empty($entityIds) || empty($filters)) {
            return $entityIds;
        }
        
        // 获取实体
        $entity = $this->getEntity($entityCode);
        if (!$entity) {
            return $entityIds;
        }
        
        $filteredIds = $entityIds;
        $matchedIdsByFilter = [];
        
        foreach ($filters as $attributeCode => $values) {
            if (empty($values)) {
                continue;
            }
            
            if (!is_array($values)) {
                $values = [$values];
            }
            
            // 获取属性
            $attribute = $this->getAttribute($entity, $attributeCode);
            if (!$attribute || !$attribute->getId()) {
                continue;
            }
            
            // 查询匹配的实体ID
            $matchedIds = $this->getEntitiesByAttributeValues($attribute, $filteredIds, $values);
            
            if ($logic === 'AND') {
                // AND 逻辑：逐步缩小范围
                $filteredIds = $matchedIds;
                if (empty($filteredIds)) {
                    break;
                }
            } else {
                // OR 逻辑：收集所有匹配
                $matchedIdsByFilter[] = $matchedIds;
            }
        }
        
        if ($logic === 'OR' && !empty($matchedIdsByFilter)) {
            // 合并所有匹配的ID
            $allMatchedIds = array_merge(...$matchedIdsByFilter);
            $filteredIds = array_values(array_unique(array_intersect($entityIds, $allMatchedIds)));
        }
        
        // 触发事件
        $this->eventsManager->dispatch('Weline_Eav::attribute_filter_apply', [
            'entity_code' => $entityCode,
            'original_ids' => $entityIds,
            'filtered_ids' => &$filteredIds,
            'filters' => $filters,
        ]);
        
        return $filteredIds;
    }
    
    /**
     * 获取属性值的实体计数
     * 
     * @param string $entityCode
     * @param array $entityIds
     * @param array $attributeCodes
     * @return array
     */
    public function getAttributeValueCounts(
        string $entityCode,
        array $entityIds,
        array $attributeCodes
    ): array {
        if (empty($entityIds) || empty($attributeCodes)) {
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
     * 获取按属性组分组的可筛选属性
     * 
     * @param string $entityCode
     * @param array $entityIds
     * @param int|null $setId 属性集ID（可选）
     * @return array
     */
    public function getFilterableAttributesByGroup(
        string $entityCode,
        array $entityIds,
        ?int $setId = null
    ): array {
        $filterableAttributes = $this->getFilterableAttributes($entityCode, $entityIds);
        
        if (empty($filterableAttributes)) {
            return [];
        }
        
        // 按属性组分组
        $grouped = [];
        
        foreach ($filterableAttributes as $code => $data) {
            $groupId = $data['attribute']['group_id'] ?? 0;
            $attributeSetId = $data['attribute']['set_id'] ?? 0;
            
            // 如果指定了属性集，过滤不属于该集的属性
            if ($setId !== null && $attributeSetId !== $setId) {
                continue;
            }
            
            if (!isset($grouped[$groupId])) {
                $group = $this->getAttributeGroup($groupId);
                $grouped[$groupId] = [
                    'group_id' => $groupId,
                    'group_name' => $group ? $group->getData('name') : __('其他'),
                    'group_code' => $group ? $group->getData('code') : 'other',
                    'attributes' => [],
                ];
            }
            
            $grouped[$groupId]['attributes'][$code] = $data;
        }
        
        return array_values($grouped);
    }
    
    /**
     * 获取实体
     */
    private function getEntity(string $entityCode): ?EavEntity
    {
        try {
            /** @var EavEntity $entityModel */
            $entityModel = ObjectManager::getInstance(EavEntity::class);
            $entityModel->load('code', $entityCode);
            
            return $entityModel->getId() ? $entityModel : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 获取实体的属性列表
     */
    private function getEntityAttributes(EavEntity $entity, array $attributeCodes = []): array
    {
        $cacheKey = $entity->getId() . '_' . implode(',', $attributeCodes);
        
        if (isset($this->attributeCache[$cacheKey])) {
            return $this->attributeCache[$cacheKey];
        }
        
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $attributeModel->reset()
            ->where(EavAttribute::fields_eav_entity_id, $entity->getId())
            ->where(EavAttribute::fields_is_enable, 1)
            ->where(EavAttribute::fields_is_filterable, 1); // 只获取可筛选的属性
        
        if (!empty($attributeCodes)) {
            $attributeModel->where(EavAttribute::fields_code, $attributeCodes, 'in');
        }
        
        $attributeModel->order(EavAttribute::fields_group_id)
            ->order(EavAttribute::fields_attribute_id);
        
        $results = $attributeModel->select()->fetch();
        
        $attributes = [];
        foreach ($results as $attr) {
            if ($attr instanceof EavAttribute) {
                $attributes[] = $attr;
            }
        }
        
        $this->attributeCache[$cacheKey] = $attributes;
        
        return $attributes;
    }
    
    /**
     * 获取单个属性
     */
    private function getAttribute(EavEntity $entity, string $attributeCode): ?EavAttribute
    {
        $cacheKey = $entity->getId() . '_' . $attributeCode;
        
        if (isset($this->attributeCache[$cacheKey])) {
            return $this->attributeCache[$cacheKey];
        }
        
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $attributeModel->reset()
            ->where(EavAttribute::fields_eav_entity_id, $entity->getId())
            ->where(EavAttribute::fields_code, $attributeCode)
            ->where(EavAttribute::fields_is_enable, 1);
        
        $attribute = $attributeModel->find()->fetch();
        
        if ($attribute instanceof EavAttribute && $attribute->getId()) {
            $this->attributeCache[$cacheKey] = $attribute;
            return $attribute;
        }
        
        return null;
    }
    
    /**
     * 获取属性值和计数
     */
    private function getAttributeValuesWithCounts(EavAttribute $attribute, array $entityIds): array
    {
        if (empty($entityIds)) {
            return ['values' => [], 'counts' => []];
        }
        
        $valueModel = $attribute->w_getValueModel();
        $valueModel->reset()
            ->fields(['value', 'COUNT(DISTINCT entity_id) as count'])
            ->where('attribute_id', $attribute->getId())
            ->where('entity_id', $entityIds, 'in')
            ->where("value != '' AND value IS NOT NULL")
            ->groupBy('value');
        
        $results = $valueModel->select()->fetchArray();
        
        $values = [];
        $counts = [];
        
        foreach ($results as $row) {
            $value = $row['value'];
            $count = (int)$row['count'];
            
            $values[] = $value;
            $counts[$value] = $count;
        }
        
        return ['values' => $values, 'counts' => $counts];
    }
    
    /**
     * 获取属性选项
     */
    private function getAttributeOptions(EavAttribute $attribute): array
    {
        $cacheKey = 'options_' . $attribute->getId();
        
        if (isset($this->optionCache[$cacheKey])) {
            return $this->optionCache[$cacheKey];
        }
        
        /** @var Option $optionModel */
        $optionModel = ObjectManager::getInstance(Option::class);
        $optionModel->reset()
            ->where(Option::fields_attribute_id, $attribute->getId())
            ->order(Option::fields_option_id);
        
        $results = $optionModel->select()->fetchArray();
        
        $options = [];
        foreach ($results as $row) {
            $options[$row[Option::fields_option_id]] = [
                'option_id' => $row[Option::fields_option_id],
                'code' => $row[Option::fields_code] ?? '',
                'value' => $row[Option::fields_value] ?? '',
                'swatch_image' => $row[Option::fields_swatch_image] ?? null,
                'swatch_color' => $row[Option::fields_swatch_color] ?? null,
                'swatch_text' => $row[Option::fields_swatch_text] ?? null,
            ];
        }
        
        $this->optionCache[$cacheKey] = $options;
        
        return $options;
    }
    
    /**
     * 根据属性值获取实体ID
     */
    private function getEntitiesByAttributeValues(
        EavAttribute $attribute,
        array $entityIds,
        array $values
    ): array {
        if (empty($entityIds) || empty($values)) {
            return [];
        }
        
        $valueModel = $attribute->w_getValueModel();
        $valueModel->reset()
            ->fields('DISTINCT entity_id')
            ->where('attribute_id', $attribute->getId())
            ->where('entity_id', $entityIds, 'in')
            ->where('value', $values, 'in');
        
        $results = $valueModel->select()->fetchArray();
        
        return array_column($results, 'entity_id');
    }
    
    /**
     * 获取属性组
     */
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
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->attributeCache = [];
        $this->optionCache = [];
    }
}
