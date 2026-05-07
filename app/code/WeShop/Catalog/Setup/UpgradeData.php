<?php

declare(strict_types=1);

namespace WeShop\Catalog\Setup;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Catalog\Model\Category;

/**
 * 分类 EAV 升级数据
 * 注册分类 EAV 实体和属性
 */
class UpgradeData
{
    /**
     * 安装分类 EAV 实体和属性
     */
    public function install(): void
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        // 1. 注册 EAV 实体
        $this->registerEavEntity($category);
        
        // 2. 创建默认属性集
        $setId = $this->createDefaultAttributeSet($category);
        
        // 3. 创建默认属性组
        $groupId = $this->createDefaultAttributeGroup($category, $setId);
        
        // 4. 创建 is_right_menu 属性
        $this->createIsRightMenuAttribute($category, $setId, $groupId);
        
        // 5. 创建 icon 属性
        $this->createIconAttribute($category, $setId, $groupId);
        
        // 6. 创建 show_icon 属性
        $this->createShowIconAttribute($category, $setId, $groupId);
    }
    
    /**
     * 注册 EAV 实体
     */
    private function registerEavEntity(Category $category): void
    {
        /** @var EavEntity $eavEntity */
        $eavEntity = ObjectManager::getInstance(EavEntity::class);
        
        $eavEntity->clear()
            ->setCode($category::entity_code)
            ->setName($category::entity_name)
            ->setClass(Category::class)
            ->setEntityIdFieldType($category::eav_entity_id_field_type)
            ->setEntityIdFieldLength($category::eav_entity_id_field_length)
            ->setData(EavEntity::schema_fields_is_system, 0)
            ->forceCheck(true, [EavEntity::schema_fields_code])
            ->save();
    }
    
    /**
     * 创建默认属性集
     */
    private function createDefaultAttributeSet(Category $category): int
    {
        /** @var Set $setModel */
        $setModel = ObjectManager::getInstance(Set::class);
        
        $eavEntity = ObjectManager::getInstance(EavEntity::class)
            ->loadByCode($category::entity_code);
        
        if (!$eavEntity->getId()) {
            throw new \Exception(__('EAV 实体未注册: %{1}', [$category::entity_code]));
        }
        
        $setModel->clear()
            ->setCode('default')
            ->setEavEntityId($eavEntity->getId())
            ->setName(__('默认属性集'))
            ->forceCheck(true, [Set::schema_fields_code, Set::schema_fields_eav_entity_id])
            ->save();
        
        return (int) $setModel->getId();
    }
    
    /**
     * 创建默认属性组
     */
    private function createDefaultAttributeGroup(Category $category, int $setId): int
    {
        /** @var Group $groupModel */
        $groupModel = ObjectManager::getInstance(Group::class);
        
        $eavEntity = ObjectManager::getInstance(EavEntity::class)
            ->loadByCode($category::entity_code);
        
        $groupModel->clear()
            ->setCode('default')
            ->setEavEntityId($eavEntity->getId())
            ->setSetId($setId)
            ->setName(__('默认属性组'))
            ->forceCheck(true, $groupModel->getUnitPrimaryKeys())
            ->save();
        
        return (int) $groupModel->getId();
    }
    
    /**
     * 创建 is_right_menu 属性
     */
    private function createIsRightMenuAttribute(Category $category, int $setId, int $groupId): void
    {
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::make(EavAttribute::class);
        
        $eavEntity = ObjectManager::getInstance(EavEntity::class)
            ->loadByCode($category::entity_code);
        
        // 查找可用的布尔类型（优先使用 input_bool，因为它更简单且不需要选项表）
        /** @var Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $type = $typeModel->clear()
            ->where(Type::schema_fields_code, 'input_bool')
            ->find()
            ->fetch();
        
        if (!$type->getId()) {
            // 如果 input_bool 不存在，查找 select_yes_no
            $type = $typeModel->clear()
                ->where(Type::schema_fields_code, 'select_yes_no')
                ->find()
                ->fetch();
        }
        
        if (!$type->getId()) {
            // 如果都不存在，使用 select_option
            $type = $typeModel->clear()
                ->where(Type::schema_fields_code, 'select_option')
                ->find()
                ->fetch();
        }
        
        if (!$type->getId()) {
            throw new \Exception(__('EAV 属性类型不存在: input_bool、select_yes_no 或 select_option'));
        }
        
        // 检查属性是否已存在
        $existingAttribute = $attributeModel->clear()
            ->where(EavAttribute::schema_fields_code, 'is_right_menu')
            ->where(EavAttribute::schema_fields_eav_entity_id, $eavEntity->getId())
            ->find()
            ->fetch();
        
        if ($existingAttribute->getId()) {
            // 属性已存在，跳过创建
            return;
        }
        
        // 创建属性
        $attributeModel->clear()
            ->current_setEntity($category)
            ->setData([
                EavAttribute::schema_fields_code => 'is_right_menu',
                EavAttribute::schema_fields_name => __('右侧菜单分类'),
                EavAttribute::schema_fields_type_id => $type->getId(),
                EavAttribute::schema_fields_set_id => $setId,
                EavAttribute::schema_fields_group_id => $groupId,
                EavAttribute::schema_fields_eav_entity_id => $eavEntity->getId(),
                EavAttribute::schema_fields_multiple_valued => 0,
                EavAttribute::schema_fields_has_option => 0,
                EavAttribute::schema_fields_is_system => 0,
                EavAttribute::schema_fields_is_enable => 1,
                EavAttribute::schema_fields_default_value => '0',
            ])
            ->forceCheck(true, [EavAttribute::schema_fields_code, EavAttribute::schema_fields_eav_entity_id])
            ->save();
    }
    
    /**
     * 创建 icon 属性
     */
    private function createIconAttribute(Category $category, int $setId, int $groupId): void
    {
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::make(EavAttribute::class);
        
        $eavEntity = ObjectManager::getInstance(EavEntity::class)
            ->loadByCode($category::entity_code);
        
        // 查找文本类型（用于存储图标类名）
        /** @var Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $type = $typeModel->clear()
            ->where(Type::schema_fields_code, 'input_string')
            ->find()
            ->fetch();
        
        if (!$type->getId()) {
            throw new \Exception(__('EAV 属性类型不存在: input_string'));
        }
        
        // 检查属性是否已存在
        $existingAttribute = $attributeModel->clear()
            ->where(EavAttribute::schema_fields_code, 'icon')
            ->where(EavAttribute::schema_fields_eav_entity_id, $eavEntity->getId())
            ->find()
            ->fetch();
        
        if ($existingAttribute->getId()) {
            // 属性已存在，跳过创建
            return;
        }
        
        // 创建属性
        $attributeModel->clear()
            ->current_setEntity($category)
            ->setData([
                EavAttribute::schema_fields_code => 'icon',
                EavAttribute::schema_fields_name => __('图标'),
                EavAttribute::schema_fields_type_id => $type->getId(),
                EavAttribute::schema_fields_set_id => $setId,
                EavAttribute::schema_fields_group_id => $groupId,
                EavAttribute::schema_fields_eav_entity_id => $eavEntity->getId(),
                EavAttribute::schema_fields_multiple_valued => 0,
                EavAttribute::schema_fields_has_option => 0,
                EavAttribute::schema_fields_is_system => 0,
                EavAttribute::schema_fields_is_enable => 1,
                EavAttribute::schema_fields_default_value => '',
            ])
            ->forceCheck(true, [EavAttribute::schema_fields_code, EavAttribute::schema_fields_eav_entity_id])
            ->save();
    }
    
    /**
     * 创建 show_icon 属性
     */
    private function createShowIconAttribute(Category $category, int $setId, int $groupId): void
    {
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::make(EavAttribute::class);
        
        $eavEntity = ObjectManager::getInstance(EavEntity::class)
            ->loadByCode($category::entity_code);
        
        // 查找可用的布尔类型（优先使用 input_bool，因为它更简单且不需要选项表）
        /** @var Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $type = $typeModel->clear()
            ->where(Type::schema_fields_code, 'input_bool')
            ->find()
            ->fetch();
        
        if (!$type->getId()) {
            // 如果 input_bool 不存在，查找 select_yes_no
            $type = $typeModel->clear()
                ->where(Type::schema_fields_code, 'select_yes_no')
                ->find()
                ->fetch();
        }
        
        if (!$type->getId()) {
            // 如果都不存在，使用 select_option
            $type = $typeModel->clear()
                ->where(Type::schema_fields_code, 'select_option')
                ->find()
                ->fetch();
        }
        
        if (!$type->getId()) {
            throw new \Exception(__('EAV 属性类型不存在: input_bool、select_yes_no 或 select_option'));
        }
        
        // 检查属性是否已存在
        $existingAttribute = $attributeModel->clear()
            ->where(EavAttribute::schema_fields_code, 'show_icon')
            ->where(EavAttribute::schema_fields_eav_entity_id, $eavEntity->getId())
            ->find()
            ->fetch();
        
        if ($existingAttribute->getId()) {
            // 属性已存在，跳过创建
            return;
        }
        
        // 创建属性
        $attributeModel->clear()
            ->current_setEntity($category)
            ->setData([
                EavAttribute::schema_fields_code => 'show_icon',
                EavAttribute::schema_fields_name => __('显示图标'),
                EavAttribute::schema_fields_type_id => $type->getId(),
                EavAttribute::schema_fields_set_id => $setId,
                EavAttribute::schema_fields_group_id => $groupId,
                EavAttribute::schema_fields_eav_entity_id => $eavEntity->getId(),
                EavAttribute::schema_fields_multiple_valued => 0,
                EavAttribute::schema_fields_has_option => 0,
                EavAttribute::schema_fields_is_system => 0,
                EavAttribute::schema_fields_is_enable => 1,
                EavAttribute::schema_fields_default_value => '1', // 默认显示图标
            ])
            ->forceCheck(true, [EavAttribute::schema_fields_code, EavAttribute::schema_fields_eav_entity_id])
            ->save();
    }
}
