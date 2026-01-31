<?php

declare(strict_types=1);

/*
 * EAV树形数据API控制器
 * 提供EAV层级结构的树形数据
 */

namespace Weline\Eav\Controller\Backend\Api;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;

/**
 * 树形数据API
 * 
 * 提供EAV层级结构：Entity → Set → Group → Attribute
 */
class Tree extends ApiController
{
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
    }

    /**
     * 获取完整树形数据（仅实体层级，子节点懒加载）
     * 
     * GET /eav/backend/api/tree
     * 
     * @return string JSON响应
     */
    public function getIndex(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            
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
            
            return $this->apiSuccess($treeData);
        });
    }

    /**
     * 获取单个节点的子节点（懒加载）
     * 
     * GET /eav/backend/api/tree/children
     * 
     * @return string JSON响应
     */
    public function getChildren(): string
    {
        return $this->tryCatch(function () {
            $type = $this->getRequiredParam('type');
            $id = $this->getIntParam('id');
            
            $children = match ($type) {
                'entity' => $this->getEntityChildren($id),
                'set' => $this->getSetChildren($id),
                'group' => $this->getGroupChildren($id),
                default => throw new \InvalidArgumentException(__('未知节点类型: %1', $type)),
            };
            
            return $this->apiSuccess($children);
        });
    }

    /**
     * 获取单个节点详情
     * 
     * GET /eav/backend/api/tree/node
     * 
     * @return string JSON响应
     */
    public function getNode(): string
    {
        return $this->tryCatch(function () {
            $type = $this->getRequiredParam('type');
            $id = $this->getIntParam('id');
            
            $node = match ($type) {
                'entity' => $this->getEntityNode($id),
                'set' => $this->getSetNode($id),
                'group' => $this->getGroupNode($id),
                'attribute' => $this->getAttributeNode($id),
                default => throw new \InvalidArgumentException(__('未知节点类型: %1', $type)),
            };
            
            return $this->apiSuccess($node);
        });
    }

    /**
     * 格式化实体节点
     */
    private function formatEntityNode(array $entity): array
    {
        return [
            'id' => 'entity_' . $entity['eav_entity_id'],
            'nodeId' => (int)$entity['eav_entity_id'],
            'type' => 'entity',
            'code' => $entity['code'],
            'name' => $entity['local_name'] ?? $entity['name'] ?? $entity['code'],
            'icon' => 'mdi-cube-outline',
            'isSystem' => (bool)($entity['is_system'] ?? false),
            'lazy' => true,
            'children' => [],
        ];
    }

    /**
     * 格式化属性集节点
     */
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

    /**
     * 格式化属性组节点
     */
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

    /**
     * 格式化属性节点
     */
    private function formatAttributeNode(array $attribute): array
    {
        return [
            'id' => 'attribute_' . $attribute['attribute_id'],
            'nodeId' => (int)$attribute['attribute_id'],
            'type' => 'attribute',
            'code' => $attribute['code'],
            'name' => $attribute['local_name'] ?? $attribute['name'] ?? $attribute['code'],
            'icon' => $this->getAttributeIcon($attribute),
            'entityId' => (int)$attribute['eav_entity_id'],
            'setId' => (int)($attribute['set_id'] ?? 0),
            'groupId' => (int)($attribute['group_id'] ?? 0),
            'typeId' => (int)($attribute['type_id'] ?? 0),
            'isSystem' => (bool)($attribute['is_system'] ?? false),
            'isEnable' => (bool)($attribute['basic_is_enable'] ?? true),
            'lazy' => false,
            'children' => [],
        ];
    }

    /**
     * 根据属性类型获取图标
     */
    private function getAttributeIcon(array $attribute): string
    {
        // 根据data_has_option或type_id判断图标
        if (!empty($attribute['data_has_option'])) {
            return 'mdi-format-list-bulleted';
        }
        return 'mdi-tag-outline';
    }

    /**
     * 获取实体的子节点（属性集）
     */
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

    /**
     * 获取属性集的子节点（属性组）
     */
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

    /**
     * 获取属性组的子节点（属性）
     */
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

    /**
     * 获取实体节点详情
     */
    private function getEntityNode(int $id): array
    {
        $query = clone $this->eavEntity;
        $query->loadLocalDescription();
        $query->where('main_table.eav_entity_id', $id);
        $entity = $query->find()->fetchArray();
        
        if (empty($entity)) {
            throw new \InvalidArgumentException(__('实体不存在: %1', $id));
        }
        
        return $this->formatEntityNode($entity);
    }

    /**
     * 获取属性集节点详情
     */
    private function getSetNode(int $id): array
    {
        $query = clone $this->eavSet;
        $query->loadLocalDescription();
        $query->where('main_table.set_id', $id);
        $set = $query->find()->fetchArray();
        
        if (empty($set)) {
            throw new \InvalidArgumentException(__('属性集不存在: %1', $id));
        }
        
        return $this->formatSetNode($set);
    }

    /**
     * 获取属性组节点详情
     */
    private function getGroupNode(int $id): array
    {
        $query = clone $this->eavGroup;
        $query->loadLocalDescription();
        $query->where('main_table.group_id', $id);
        $group = $query->find()->fetchArray();
        
        if (empty($group)) {
            throw new \InvalidArgumentException(__('属性组不存在: %1', $id));
        }
        
        return $this->formatGroupNode($group);
    }

    /**
     * 获取属性节点详情
     */
    private function getAttributeNode(int $id): array
    {
        $query = clone $this->eavAttribute;
        $query->loadLocalDescription();
        $query->where('main_table.attribute_id', $id);
        $attribute = $query->find()->fetchArray();
        
        if (empty($attribute)) {
            throw new \InvalidArgumentException(__('属性不存在: %1', $id));
        }
        
        return $this->formatAttributeNode($attribute);
    }
}
