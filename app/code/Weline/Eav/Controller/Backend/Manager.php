<?php

declare(strict_types=1);

/*
 * EAV统一管理控制器
 * 提供EAV后台统一管理界面和API
 */

namespace Weline\Eav\Controller\Backend;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Controller\BackendController;

/**
 * EAV统一管理控制器
 * 
 * 提供树形视图的统一管理界面
 * 左侧：Entity → Set → Group → Attribute 树形导航
 * 右侧：详情编辑面板
 */
class Manager extends BackendController
{
    private EavEntity $eavEntity;
    private Set $eavSet;
    private Group $eavGroup;
    private EavAttribute $eavAttribute;
    private Type $eavType;

    public function __construct(
        EavEntity $eavEntity,
        Set $eavSet,
        Group $eavGroup,
        EavAttribute $eavAttribute,
        Type $eavType
    ) {
        $this->eavEntity = $eavEntity;
        $this->eavSet = $eavSet;
        $this->eavGroup = $eavGroup;
        $this->eavAttribute = $eavAttribute;
        $this->eavType = $eavType;
    }

    /**
     * EAV统一管理首页
     * 
     * GET /eav/backend/manager
     */
    public function index()
    {
        return $this->fetch();
    }

    // ========== Tree API ==========

    /**
     * 获取树形数据（实体列表）
     * 
     * GET /eav/backend/manager/tree
     */
    public function getTree(): string
    {
        try {
            $search = $this->request->getGet('search') ?? '';
            
            $query = clone $this->eavEntity;
            $query->loadLocalDescription();
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, local.name)', "%{$search}%", 'like');
            }
            
            $entities = $query->select()->fetchArray();
            
            $treeData = [];
            foreach ($entities as $entity) {
                $treeData[] = $this->formatEntityNode($entity);
            }
            
            return $this->fetchJson(['success' => true, 'data' => $treeData]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 获取子节点（懒加载）
     * 
     * GET /eav/backend/manager/children?type=entity&id=1
     */
    public function getChildren(): string
    {
        try {
            $type = $this->request->getGet('type');
            $id = (int)$this->request->getGet('id');
            
            $children = match ($type) {
                'entity' => $this->getEntityChildren($id),
                'set' => $this->getSetChildren($id),
                'group' => $this->getGroupChildren($id),
                default => throw new \InvalidArgumentException(__('未知节点类型: %1', $type)),
            };
            
            return $this->fetchJson(['success' => true, 'data' => $children]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Entity API ==========

    /**
     * 获取实体详情
     * 
     * GET /eav/backend/manager/entityDetail?id=1
     */
    public function getEntityDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavEntity;
            $query->loadLocalDescription();
            $query->where('main_table.eav_entity_id', $id);
            $entity = $query->find()->fetchArray();
            
            if (empty($entity)) {
                throw new \InvalidArgumentException(__('实体不存在: %1', $id));
            }
            
            return $this->fetchJson(['success' => true, 'data' => $entity]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存实体
     * 
     * POST /eav/backend/manager/entitySave
     */
    public function postEntitySave(): string
    {
        try {
            $id = (int)$this->request->getPost('eav_entity_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            $class = $this->request->getPost('class');
            
            if (!$code) {
                throw new \InvalidArgumentException(__('实体代码不能为空'));
            }
            if (!$name) {
                throw new \InvalidArgumentException(__('实体名称不能为空'));
            }
            if (!$class) {
                throw new \InvalidArgumentException(__('实体类不能为空'));
            }
            
            $entity = clone $this->eavEntity;
            
            if ($id) {
                $entity->load($id);
                if (!$entity->getId()) {
                    throw new \InvalidArgumentException(__('实体不存在'));
                }
                
                // 如果是系统实体，不允许修改代码和类
                if ($entity->getData('is_system')) {
                    if ($entity->getData('code') !== $code) {
                        throw new \InvalidArgumentException(__('系统实体的代码不能修改'));
                    }
                    if ($entity->getData('class') !== $class) {
                        throw new \InvalidArgumentException(__('系统实体的实体类不能修改'));
                    }
                }
            }
            
            $entity->setData('code', $code);
            $entity->setData('name', $name);
            $entity->setData('class', $class);
            $entity->setData('eav_entity_id_field_type', $this->request->getPost('eav_entity_id_field_type') ?? 'integer');
            $entity->setData('eav_entity_id_field_length', (int)($this->request->getPost('eav_entity_id_field_length') ?? 11));
            
            $entity->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('实体更新成功') : __('实体创建成功'),
                'data' => ['eav_entity_id' => $entity->getId()]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Set API ==========

    /**
     * 获取属性集详情
     */
    public function getSetDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavSet;
            $query->loadLocalDescription();
            $query->where('main_table.set_id', $id);
            $set = $query->find()->fetchArray();
            
            if (empty($set)) {
                throw new \InvalidArgumentException(__('属性集不存在'));
            }
            
            return $this->fetchJson(['success' => true, 'data' => $set]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存属性集
     */
    public function postSetSave(): string
    {
        try {
            $id = (int)$this->request->getPost('set_id');
            $entityId = (int)$this->request->getPost('eav_entity_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            
            if (!$entityId) {
                throw new \InvalidArgumentException(__('请选择所属实体'));
            }
            if (!$code) {
                throw new \InvalidArgumentException(__('属性集代码不能为空'));
            }
            if (!$name) {
                throw new \InvalidArgumentException(__('属性集名称不能为空'));
            }
            
            $set = clone $this->eavSet;
            
            if ($id) {
                $set->load($id);
            }
            
            $set->setData('eav_entity_id', $entityId);
            $set->setData('code', $code);
            $set->setData('name', $name);
            $set->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('属性集更新成功') : __('属性集创建成功'),
                'data' => ['set_id' => $set->getId()]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Group API ==========

    /**
     * 获取属性组详情
     */
    public function getGroupDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavGroup;
            $query->loadLocalDescription();
            $query->where('main_table.group_id', $id);
            $group = $query->find()->fetchArray();
            
            if (empty($group)) {
                throw new \InvalidArgumentException(__('属性组不存在'));
            }
            
            return $this->fetchJson(['success' => true, 'data' => $group]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存属性组
     */
    public function postGroupSave(): string
    {
        try {
            $id = (int)$this->request->getPost('group_id');
            $entityId = (int)$this->request->getPost('eav_entity_id');
            $setId = (int)$this->request->getPost('set_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            
            if (!$entityId || !$setId || !$code || !$name) {
                throw new \InvalidArgumentException(__('缺少必填字段'));
            }
            
            $group = clone $this->eavGroup;
            
            if ($id) {
                $group->load($id);
            }
            
            $group->setData('eav_entity_id', $entityId);
            $group->setData('set_id', $setId);
            $group->setData('code', $code);
            $group->setData('name', $name);
            $group->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('属性组更新成功') : __('属性组创建成功'),
                'data' => ['group_id' => $group->getId()]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Attribute API ==========

    /**
     * 获取属性详情
     */
    public function getAttributeDetail(): string
    {
        try {
            $id = (int)$this->request->getGet('id');
            
            $query = clone $this->eavAttribute;
            $query->loadLocalDescription();
            $query->joinModel(
                Type::class,
                'type',
                'main_table.type_id=type.type_id',
                'left',
                'type.name as type_name, type.code as type_code, type.element'
            );
            $query->where('main_table.attribute_id', $id);
            $attribute = $query->find()->fetchArray();
            
            if (empty($attribute)) {
                throw new \InvalidArgumentException(__('属性不存在'));
            }
            
            // 获取选项
            if ($attribute['data_has_option'] ?? false) {
                $optionModel = \Weline\Framework\Manager\ObjectManager::getInstance(Option::class);
                $attribute['options'] = $optionModel->where('attribute_id', $id)->select()->fetchArray();
            } else {
                $attribute['options'] = [];
            }
            
            return $this->fetchJson(['success' => true, 'data' => $attribute]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 保存属性
     */
    public function postAttributeSave(): string
    {
        try {
            $id = (int)$this->request->getPost('attribute_id');
            $entityId = (int)$this->request->getPost('eav_entity_id');
            $setId = (int)$this->request->getPost('set_id');
            $groupId = (int)$this->request->getPost('group_id');
            $typeId = (int)$this->request->getPost('type_id');
            $code = $this->request->getPost('code');
            $name = $this->request->getPost('name');
            
            if (!$entityId || !$setId || !$groupId || !$typeId || !$code || !$name) {
                throw new \InvalidArgumentException(__('缺少必填字段'));
            }
            
            $attribute = clone $this->eavAttribute;
            
            if ($id) {
                $attribute->load($id);
            }
            
            $attribute->setData('eav_entity_id', $entityId);
            $attribute->setData('set_id', $setId);
            $attribute->setData('group_id', $groupId);
            $attribute->setData('type_id', $typeId);
            $attribute->setData('code', $code);
            $attribute->setData('name', $name);
            // 基本设置组
            $attribute->setData('basic_is_enable', $this->request->getPost('basic_is_enable') ? 1 : 0);
            // 前端显示组
            $attribute->setData('frontend_is_filterable', $this->request->getPost('frontend_is_filterable') ? 1 : 0);
            $attribute->setData('frontend_is_visible', $this->request->getPost('frontend_is_visible') ? 1 : 0);
            // 数据配置组
            $attribute->setData('data_is_multiple', $this->request->getPost('data_is_multiple') ? 1 : 0);
            $attribute->setData('data_has_option', $this->request->getPost('data_has_option') ? 1 : 0);
            
            $attribute->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $id ? __('属性更新成功') : __('属性创建成功'),
                'data' => ['attribute_id' => $attribute->getId()]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 获取属性类型列表
     */
    public function getTypes(): string
    {
        try {
            $types = $this->eavType->select()->fetchArray();
            
            $options = [];
            foreach ($types as $type) {
                $options[] = [
                    'value' => (int)$type['type_id'],
                    'label' => $type['name'],
                    'code' => $type['code'],
                    'element' => $type['element'],
                ];
            }
            
            return $this->fetchJson(['success' => true, 'data' => $options]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Delete API ==========

    /**
     * 删除节点
     */
    public function postDelete(): string
    {
        try {
            $type = $this->request->getPost('type');
            $id = (int)$this->request->getPost('id');
            
            $model = match ($type) {
                'entity' => clone $this->eavEntity,
                'set' => clone $this->eavSet,
                'group' => clone $this->eavGroup,
                'attribute' => clone $this->eavAttribute,
                default => throw new \InvalidArgumentException(__('未知类型')),
            };
            
            $model->load($id);
            if (!$model->getId()) {
                throw new \InvalidArgumentException(__('记录不存在'));
            }
            
            if ($model->getData('is_system')) {
                throw new \InvalidArgumentException(__('系统记录不能删除'));
            }
            
            $model->delete();
            
            return $this->fetchJson(['success' => true, 'message' => __('删除成功')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== Helper Methods ==========

    private function formatEntityNode(array $entity): array
    {
        return [
            'id' => 'entity_' . $entity['eav_entity_id'],
            'nodeId' => (int)$entity['eav_entity_id'],
            'type' => 'entity',
            'code' => $entity['code'],
            'name' => $entity['local_name'] ?? $entity['name'] ?? $entity['code'],
            'class' => $entity['class'] ?? '',
            'icon' => 'mdi-cube-outline',
            'isSystem' => (bool)($entity['is_system'] ?? false),
            'lazy' => true,
            'children' => [],
        ];
    }

    private function formatSetNode(array $set): array
    {
        return [
            'id' => 'set_' . $set['set_id'],
            'nodeId' => (int)$set['set_id'],
            'type' => 'set',
            'code' => $set['code'],
            'name' => $set['local_name'] ?? $set['name'] ?? $set['code'],
            'icon' => 'mdi-folder-outline',
            'entityId' => (int)$set['eav_entity_id'],
            'lazy' => true,
            'children' => [],
        ];
    }

    private function formatGroupNode(array $group): array
    {
        return [
            'id' => 'group_' . $group['group_id'],
            'nodeId' => (int)$group['group_id'],
            'type' => 'group',
            'code' => $group['code'],
            'name' => $group['local_name'] ?? $group['name'] ?? $group['code'],
            'icon' => 'mdi-folder-multiple-outline',
            'entityId' => (int)$group['eav_entity_id'],
            'setId' => (int)$group['set_id'],
            'lazy' => true,
            'children' => [],
        ];
    }

    private function formatAttributeNode(array $attribute): array
    {
        return [
            'id' => 'attribute_' . $attribute['attribute_id'],
            'nodeId' => (int)$attribute['attribute_id'],
            'type' => 'attribute',
            'code' => $attribute['code'],
            'name' => $attribute['local_name'] ?? $attribute['name'] ?? $attribute['code'],
            'icon' => !empty($attribute['data_has_option']) ? 'mdi-format-list-bulleted' : 'mdi-tag-outline',
            'entityId' => (int)$attribute['eav_entity_id'],
            'setId' => (int)($attribute['set_id'] ?? 0),
            'groupId' => (int)($attribute['group_id'] ?? 0),
            'isSystem' => (bool)($attribute['is_system'] ?? false),
            'lazy' => false,
            'children' => [],
        ];
    }

    private function getEntityChildren(int $entityId): array
    {
        $query = clone $this->eavSet;
        $query->loadLocalDescription();
        $query->where('main_table.eav_entity_id', $entityId);
        $sets = $query->select()->fetchArray();
        
        $children = [];
        foreach ($sets as $set) {
            $children[] = $this->formatSetNode($set);
        }
        return $children;
    }

    private function getSetChildren(int $setId): array
    {
        $query = clone $this->eavGroup;
        $query->loadLocalDescription();
        $query->where('main_table.set_id', $setId);
        $groups = $query->select()->fetchArray();
        
        $children = [];
        foreach ($groups as $group) {
            $children[] = $this->formatGroupNode($group);
        }
        return $children;
    }

    private function getGroupChildren(int $groupId): array
    {
        $query = clone $this->eavAttribute;
        $query->loadLocalDescription();
        $query->where('main_table.group_id', $groupId);
        $attributes = $query->select()->fetchArray();
        
        $children = [];
        foreach ($attributes as $attribute) {
            $children[] = $this->formatAttributeNode($attribute);
        }
        return $children;
    }
}
