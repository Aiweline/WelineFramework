<?php

declare(strict_types=1);

/*
 * EAV属性API控制器
 * 提供属性的CRUD操作
 */

namespace Weline\Eav\Controller\Backend\Api;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

/**
 * 属性CRUD API
 */
class Attribute extends ApiController
{
    private EavAttribute $eavAttribute;
    private Type $eavType;

    public function __construct(EavAttribute $eavAttribute, Type $eavType)
    {
        $this->eavAttribute = $eavAttribute;
        $this->eavType = $eavType;
    }

    /**
     * 获取属性列表
     * 
     * GET /eav/backend/api/attribute
     * 
     * @return string JSON响应
     */
    public function getIndex(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $entityId = $this->getIntParam('entity_id');
            $setId = $this->getIntParam('set_id');
            $groupId = $this->getIntParam('group_id');
            $page = $this->getIntParam('page', 1);
            $pageSize = $this->getIntParam('pageSize', 20);
            
            $query = clone $this->eavAttribute;
            $query->loadLocalDescription();
            
            // 关联实体信息
            $query->joinModel(
                EavEntity::class,
                'entity',
                'main_table.eav_entity_id=entity.eav_entity_id',
                'left',
                'entity.name as entity_name, entity.code as entity_code'
            );
            
            // 关联类型信息
            $query->joinModel(
                Type::class,
                'type',
                'main_table.type_id=type.type_id',
                'left',
                'type.name as type_name, type.code as type_code, type.element'
            );
            
            if ($entityId) {
                $query->where('main_table.eav_entity_id', $entityId);
            }
            
            if ($setId) {
                $query->where('main_table.set_id', $setId);
            }
            
            if ($groupId) {
                $query->where('main_table.group_id', $groupId);
            }
            
            if ($search) {
                $query->where(
                    'concat(main_table.code, main_table.name, local.name, type.name)',
                    "%{$search}%",
                    'like'
                );
            }
            
            $query->order('main_table.update_time', 'DESC');
            $query->pagination($page, $pageSize);
            $items = $query->select()->fetchArray();
            $pagination = $query->getPagination();
            
            return $this->paginated($items, $pagination);
        });
    }

    /**
     * 获取属性详情
     * 
     * GET /eav/backend/api/attribute/detail?id=1
     * 
     * @return string JSON响应
     */
    public function getDetail(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少属性ID'));
            }
            
            $query = clone $this->eavAttribute;
            $query->loadLocalDescription();
            $query->joinModel(
                Type::class,
                'type',
                'main_table.type_id=type.type_id',
                'left',
                'type.name as type_name, type.code as type_code, type.element, type.is_swatch, type.swatch_image, type.swatch_color, type.swatch_text'
            );
            $query->where('main_table.attribute_id', $id);
            $attribute = $query->find()->fetchArray();
            
            if (empty($attribute)) {
                throw new \InvalidArgumentException(__('属性不存在: %1', $id));
            }
            
            // 获取属性选项
            if ($attribute['data_has_option'] ?? false) {
                $optionModel = ObjectManager::getInstance(Option::class);
                $options = $optionModel->where('attribute_id', $id)
                    ->select()
                    ->fetchArray();
                $attribute['options'] = $options;
            } else {
                $attribute['options'] = [];
            }
            
            return $this->apiSuccess($attribute);
        });
    }

    /**
     * 保存属性（新增或更新）
     * 
     * POST /eav/backend/api/attribute/save
     * 
     * @return string JSON响应
     */
    public function postSave(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('attribute_id');
            $entityId = $this->getIntParam('eav_entity_id');
            $setId = $this->getIntParam('set_id');
            $groupId = $this->getIntParam('group_id');
            $typeId = $this->getIntParam('type_id');
            $code = $this->getParam('code');
            $name = $this->getParam('name');
            $isSystem = $this->getIntParam('is_system', 0);
            // 基本设置组
            $basicIsEnable = $this->getIntParam('basic_is_enable', 1);
            // 前端显示组
            $frontendIsFilterable = $this->getIntParam('frontend_is_filterable', 0);
            $frontendIsSearchable = $this->getIntParam('frontend_is_searchable', 0);
            $frontendIsVisible = $this->getIntParam('frontend_is_visible', 1);
            // 数据配置组
            $dataIsMultiple = $this->getIntParam('data_is_multiple', 0);
            $dataHasOption = $this->getIntParam('data_has_option', 0);
            
            // 验证必填字段
            if (!$entityId) {
                throw new \InvalidArgumentException(__('请选择所属实体'));
            }
            
            if (!$setId) {
                throw new \InvalidArgumentException(__('请选择所属属性集'));
            }
            
            if (!$groupId) {
                throw new \InvalidArgumentException(__('请选择所属属性组'));
            }
            
            if (!$typeId) {
                throw new \InvalidArgumentException(__('请选择属性类型'));
            }
            
            if (!$code) {
                throw new \InvalidArgumentException(__('属性代码不能为空'));
            }
            
            if (!$name) {
                throw new \InvalidArgumentException(__('属性名称不能为空'));
            }
            
            $attribute = clone $this->eavAttribute;
            
            if ($id) {
                $attribute->load($id);
                if (!$attribute->getId()) {
                    throw new \InvalidArgumentException(__('属性不存在: %1', $id));
                }
                // 如果是系统属性，不允许修改代码
                if ($attribute->getData('is_system') && $attribute->getData('code') !== $code) {
                    throw new \InvalidArgumentException(__('系统属性的代码不能修改'));
                }
            } else {
                // 检查代码是否已存在（同一实体下）
                $existing = clone $this->eavAttribute;
                $existing->where('code', $code)
                    ->where('eav_entity_id', $entityId)
                    ->find();
                if ($existing->getId()) {
                    throw new \InvalidArgumentException(__('该实体下属性代码已存在: %1', $code));
                }
            }
            
            $attribute->setData('eav_entity_id', $entityId);
            $attribute->setData('set_id', $setId);
            $attribute->setData('group_id', $groupId);
            $attribute->setData('type_id', $typeId);
            $attribute->setData('code', $code);
            $attribute->setData('name', $name);
            $attribute->setData('is_system', $isSystem);
            // 基本设置组
            $attribute->setData('basic_is_enable', $basicIsEnable);
            // 前端显示组
            $attribute->setData('frontend_is_filterable', $frontendIsFilterable);
            $attribute->setData('frontend_is_searchable', $frontendIsSearchable);
            $attribute->setData('frontend_is_visible', $frontendIsVisible);
            // 数据配置组
            $attribute->setData('data_is_multiple', $dataIsMultiple);
            $attribute->setData('data_has_option', $dataHasOption);
            
            $attribute->save();
            $attributeId = $attribute->getAttributeId();
            
            // 处理属性选项
            $options = $this->request->getPost('options');
            if ($dataHasOption && is_array($options)) {
                $this->saveOptions($attributeId, $entityId, $options);
            }
            
            return $this->apiSuccess([
                'attribute_id' => $attributeId,
            ], $id ? __('属性更新成功') : __('属性创建成功'));
        });
    }

    /**
     * 保存属性选项
     */
    private function saveOptions(int $attributeId, int $entityId, array $options): void
    {
        $optionModel = ObjectManager::getInstance(Option::class);
        
        foreach ($options as $optionData) {
            $optionId = (int)($optionData['option_id'] ?? 0);
            
            $option = clone $optionModel;
            if ($optionId) {
                $option->load($optionId);
            }
            
            $option->setData('attribute_id', $attributeId);
            $option->setData('eav_entity_id', $entityId);
            $option->setData('code', $optionData['code'] ?? '');
            $option->setData('value', $optionData['value'] ?? '');
            $option->setData('swatch_image', $optionData['swatch_image'] ?? '');
            $option->setData('swatch_color', $optionData['swatch_color'] ?? '');
            $option->setData('swatch_text', $optionData['swatch_text'] ?? '');
            
            $option->save();
        }
    }

    /**
     * 删除属性
     * 
     * POST /eav/backend/api/attribute/delete
     * 
     * @return string JSON响应
     */
    public function postDelete(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少属性ID'));
            }
            
            $attribute = clone $this->eavAttribute;
            $attribute->load($id);
            
            if (!$attribute->getId()) {
                throw new \InvalidArgumentException(__('属性不存在: %1', $id));
            }
            
            if ($attribute->getData('is_system')) {
                throw new \InvalidArgumentException(__('系统属性不能删除'));
            }
            
            $attribute->delete();
            
            return $this->apiSuccess(null, __('属性删除成功'));
        });
    }

    /**
     * 获取属性类型列表
     * 
     * GET /eav/backend/api/attribute/types
     * 
     * @return string JSON响应
     */
    public function getTypes(): string
    {
        return $this->tryCatch(function () {
            $query = clone $this->eavType;
            $types = $query->select()->fetchArray();
            
            $options = [];
            foreach ($types as $type) {
                $options[] = [
                    'value' => (int)$type['type_id'],
                    'label' => $type['name'],
                    'code' => $type['code'],
                    'element' => $type['element'],
                    'isSwatch' => (bool)$type['is_swatch'],
                ];
            }
            
            return $this->apiSuccess($options);
        });
    }

    /**
     * 搜索属性（用于下拉选择）
     * 
     * GET /eav/backend/api/attribute/search
     * 
     * @return string JSON响应
     */
    public function getSearch(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $entityId = $this->getIntParam('entity_id');
            $setId = $this->getIntParam('set_id');
            $groupId = $this->getIntParam('group_id');
            $limit = $this->getIntParam('limit', 20);
            
            $query = clone $this->eavAttribute;
            $query->loadLocalDescription();
            
            if ($entityId) {
                $query->where('main_table.eav_entity_id', $entityId);
            }
            
            if ($setId) {
                $query->where('main_table.set_id', $setId);
            }
            
            if ($groupId) {
                $query->where('main_table.group_id', $groupId);
            }
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, local.name)', "%{$search}%", 'like');
            }
            
            $query->limit($limit);
            $items = $query->select()->fetchArray();
            
            // 格式化为下拉选项
            $options = [];
            foreach ($items as $item) {
                $options[] = [
                    'value' => (int)$item['attribute_id'],
                    'label' => $item['local_name'] ?? $item['name'] ?? $item['code'],
                    'code' => $item['code'],
                ];
            }
            
            return $this->apiSuccess($options);
        });
    }
}
