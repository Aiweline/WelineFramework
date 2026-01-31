<?php

declare(strict_types=1);

/*
 * 实体属性展示组件
 * 供其他模块使用，展示指定实体的所有属性
 */

namespace Weline\Eav\Block\Widget;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\View\Block;

/**
 * 实体属性展示组件
 * 
 * 使用方式：
 * ```php
 * // 在模板中
 * <w:block class="Weline\Eav\Block\Widget\EntityAttributes" 
 *          entity_code="product" 
 *          entity_id="123" 
 *          set_id="1" />
 * 
 * // 或在PHP中
 * $block = ObjectManager::getInstance(EntityAttributes::class);
 * $block->setData('entity_code', 'product');
 * $block->setData('entity_id', $productId);
 * echo $block->toHtml();
 * ```
 */
class EntityAttributes extends Block
{
    protected string $_template = 'Weline_Eav::Widget/entity-attributes.phtml';

    private EavEntity $eavEntity;
    private EavAttribute $eavAttribute;
    private Set $eavSet;
    private Group $eavGroup;

    public function __construct(
        EavEntity $eavEntity,
        EavAttribute $eavAttribute,
        Set $eavSet,
        Group $eavGroup
    ) {
        $this->eavEntity = $eavEntity;
        $this->eavAttribute = $eavAttribute;
        $this->eavSet = $eavSet;
        $this->eavGroup = $eavGroup;
        parent::__construct();
    }

    /**
     * 获取实体信息
     *
     * @return EavEntity|null
     */
    public function getEntity(): ?EavEntity
    {
        $entityCode = $this->getData('entity_code');
        if (!$entityCode) {
            return null;
        }

        $entity = clone $this->eavEntity;
        $entity->loadByCode($entityCode);
        
        return $entity->getId() ? $entity : null;
    }

    /**
     * 获取属性集列表
     *
     * @return array
     */
    public function getAttributeSets(): array
    {
        $entity = $this->getEntity();
        if (!$entity) {
            return [];
        }

        $setId = $this->getData('set_id');
        
        $query = clone $this->eavSet;
        $query->loadLocalDescription();
        $query->where('main_table.eav_entity_id', $entity->getId());
        
        if ($setId) {
            $query->where('main_table.set_id', $setId);
        }
        
        return $query->select()->fetchArray();
    }

    /**
     * 获取属性组列表（按属性集分组）
     *
     * @param int $setId
     * @return array
     */
    public function getAttributeGroups(int $setId): array
    {
        $entity = $this->getEntity();
        if (!$entity) {
            return [];
        }

        $query = clone $this->eavGroup;
        $query->loadLocalDescription();
        $query->where('main_table.eav_entity_id', $entity->getId());
        $query->where('main_table.set_id', $setId);
        
        return $query->select()->fetchArray();
    }

    /**
     * 获取属性列表（按属性组分组）
     *
     * @param int $groupId
     * @return array
     */
    public function getAttributes(int $groupId): array
    {
        $entity = $this->getEntity();
        if (!$entity) {
            return [];
        }

        $query = clone $this->eavAttribute;
        $query->loadLocalDescription();
        $query->joinModel(
            Type::class,
            'type',
            'main_table.type_id=type.type_id',
            'left',
            'type.name as type_name, type.code as type_code, type.element, type.is_swatch'
        );
        $query->where('main_table.eav_entity_id', $entity->getId());
        $query->where('main_table.group_id', $groupId);
        $query->where('main_table.basic_is_enable', 1);
        
        // 是否只显示前端可见的属性
        if ($this->getData('visible_only')) {
            $query->where('main_table.frontend_is_visible', 1);
        }
        
        return $query->select()->fetchArray();
    }

    /**
     * 获取属性值
     *
     * @param array $attribute
     * @return mixed
     */
    public function getAttributeValue(array $attribute): mixed
    {
        $entityId = $this->getData('entity_id');
        if (!$entityId) {
            return null;
        }

        $entity = $this->getEntity();
        if (!$entity) {
            return null;
        }

        // 从EAV值表获取值
        $typeCode = $attribute['type_code'] ?? 'varchar';
        $tableName = 'eav_' . $entity->getData('code') . '_' . $typeCode;
        
        try {
            $connection = $entity->getQuery()->getConnection();
            $sql = "SELECT value FROM {$tableName} WHERE attribute_id = ? AND entity_id = ?";
            $result = $connection->query($sql, [$attribute['attribute_id'], $entityId])->fetch();
            return $result['value'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取属性选项
     *
     * @param int $attributeId
     * @return array
     */
    public function getAttributeOptions(int $attributeId): array
    {
        $optionModel = \Weline\Framework\Manager\ObjectManager::getInstance(Option::class);
        return $optionModel->where('attribute_id', $attributeId)->select()->fetchArray();
    }

    /**
     * 获取完整的属性数据结构（包含值和选项）
     *
     * @return array
     */
    public function getFullAttributeData(): array
    {
        $result = [];
        
        foreach ($this->getAttributeSets() as $set) {
            $setData = [
                'set' => $set,
                'groups' => []
            ];
            
            foreach ($this->getAttributeGroups((int)$set['set_id']) as $group) {
                $groupData = [
                    'group' => $group,
                    'attributes' => []
                ];
                
                foreach ($this->getAttributes((int)$group['group_id']) as $attribute) {
                    $attrData = $attribute;
                    $attrData['value'] = $this->getAttributeValue($attribute);
                    
                    if ($attribute['data_has_option'] ?? false) {
                        $attrData['options'] = $this->getAttributeOptions((int)$attribute['attribute_id']);
                    }
                    
                    $groupData['attributes'][] = $attrData;
                }
                
                $setData['groups'][] = $groupData;
            }
            
            $result[] = $setData;
        }
        
        return $result;
    }

    /**
     * 获取显示模式
     *
     * @return string view|edit
     */
    public function getMode(): string
    {
        return $this->getData('mode') ?: 'view';
    }

    /**
     * 是否为编辑模式
     *
     * @return bool
     */
    public function isEditMode(): bool
    {
        return $this->getMode() === 'edit';
    }
}
