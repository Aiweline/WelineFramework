<?php

declare(strict_types=1);

/*
 * 属性集管理组件
 * 供其他模块使用，管理指定实体的属性集
 */

namespace Weline\Eav\Block\Widget;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\View\Block;

/**
 * 属性集管理组件
 * 
 * 使用方式：
 * ```php
 * // 在模板中
 * <w:block class="Weline\Eav\Block\Widget\AttributeSetManager" 
 *          entity_code="product" 
 *          mode="manage" />
 * 
 * // 或在PHP中
 * $block = ObjectManager::getInstance(AttributeSetManager::class);
 * $block->setData('entity_code', 'product');
 * $block->setData('mode', 'manage'); // manage|select
 * echo $block->toHtml();
 * ```
 */
class AttributeSetManager extends Block
{
    protected string $_template = 'Weline_Eav::Widget/attribute-set-manager.phtml';

    private EavEntity $eavEntity;
    private Set $eavSet;
    private Group $eavGroup;
    private EavAttribute $eavAttribute;

    public function __construct(
        EavEntity $eavEntity,
        Set $eavSet,
        Group $eavGroup,
        EavAttribute $eavAttribute
    ) {
        $this->eavEntity = $eavEntity;
        $this->eavSet = $eavSet;
        $this->eavGroup = $eavGroup;
        $this->eavAttribute = $eavAttribute;
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

        $query = clone $this->eavSet;
        $query->loadLocalDescription();
        $query->where('main_table.eav_entity_id', $entity->getId());
        
        return $query->select()->fetchArray();
    }

    /**
     * 获取属性集详情（包含属性组和属性）
     *
     * @param int $setId
     * @return array
     */
    public function getSetDetail(int $setId): array
    {
        $entity = $this->getEntity();
        if (!$entity) {
            return [];
        }

        // 获取属性集
        $set = clone $this->eavSet;
        $set->loadLocalDescription();
        $set->where('main_table.set_id', $setId);
        $setData = $set->find()->fetchArray();
        
        if (empty($setData)) {
            return [];
        }

        // 获取属性组
        $groups = clone $this->eavGroup;
        $groups->loadLocalDescription();
        $groups->where('main_table.set_id', $setId);
        $groupsData = $groups->select()->fetchArray();

        // 获取每个组的属性
        foreach ($groupsData as &$group) {
            $attrs = clone $this->eavAttribute;
            $attrs->loadLocalDescription();
            $attrs->joinModel(
                Type::class,
                'type',
                'main_table.type_id=type.type_id',
                'left',
                'type.name as type_name, type.code as type_code'
            );
            $attrs->where('main_table.group_id', $group['group_id']);
            $attrs->where('main_table.basic_is_enable', 1);
            $group['attributes'] = $attrs->select()->fetchArray();
        }

        $setData['groups'] = $groupsData;
        
        return $setData;
    }

    /**
     * 获取所有属性集（包含详细信息）
     *
     * @return array
     */
    public function getAllSetsWithDetail(): array
    {
        $sets = $this->getAttributeSets();
        $result = [];
        
        foreach ($sets as $set) {
            $result[] = $this->getSetDetail((int)$set['set_id']);
        }
        
        return $result;
    }

    /**
     * 获取可用的属性类型
     *
     * @return array
     */
    public function getAttributeTypes(): array
    {
        $typeModel = \Weline\Framework\Manager\ObjectManager::getInstance(Type::class);
        return $typeModel->select()->fetchArray();
    }

    /**
     * 获取显示模式
     *
     * @return string manage|select
     */
    public function getMode(): string
    {
        return $this->getData('mode') ?: 'manage';
    }

    /**
     * 是否为管理模式
     *
     * @return bool
     */
    public function isManageMode(): bool
    {
        return $this->getMode() === 'manage';
    }

    /**
     * 是否为选择模式
     *
     * @return bool
     */
    public function isSelectMode(): bool
    {
        return $this->getMode() === 'select';
    }

    /**
     * 获取API基础URL
     *
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        return $this->getUrl('eav/backend/api');
    }

    /**
     * 获取当前选中的属性集ID
     *
     * @return int|null
     */
    public function getSelectedSetId(): ?int
    {
        $setId = $this->getData('selected_set_id');
        return $setId ? (int)$setId : null;
    }
}
