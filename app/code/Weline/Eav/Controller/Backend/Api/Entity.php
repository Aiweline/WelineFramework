<?php

declare(strict_types=1);

/*
 * EAV实体API控制器
 * 提供实体的CRUD操作
 */

namespace Weline\Eav\Controller\Backend\Api;

use Weline\Eav\Model\EavEntity;

/**
 * 实体CRUD API
 */
class Entity extends ApiController
{
    private EavEntity $eavEntity;

    public function __construct(EavEntity $eavEntity)
    {
        $this->eavEntity = $eavEntity;
    }

    /**
     * 获取实体列表
     * 
     * GET /eav/backend/api/entity
     * 
     * @return string JSON响应
     */
    public function getIndex(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $page = $this->getIntParam('page', 1);
            $pageSize = $this->getIntParam('pageSize', 20);
            
            $query = clone $this->eavEntity;
            $query->loadLocalDescription();
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, main_table.class, local.name)', "%{$search}%", 'like');
            }
            
            $query->pagination($page, $pageSize);
            $items = $query->select()->fetchArray();
            $pagination = $query->getPagination();
            
            return $this->paginated($items, $pagination);
        });
    }

    /**
     * 获取实体详情
     * 
     * GET /eav/backend/api/entity/detail?id=1
     * 
     * @return string JSON响应
     */
    public function getDetail(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少实体ID'));
            }
            
            $query = clone $this->eavEntity;
            $query->loadLocalDescription();
            $query->where('main_table.eav_entity_id', $id);
            $entity = $query->find()->fetchArray();
            
            if (empty($entity)) {
                throw new \InvalidArgumentException(__('实体不存在: %1', $id));
            }
            
            return $this->apiSuccess($entity);
        });
    }

    /**
     * 保存实体（新增或更新）
     * 
     * POST /eav/backend/api/entity/save
     * 
     * @return string JSON响应
     */
    public function postSave(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('eav_entity_id');
            $code = $this->getParam('code');
            $name = $this->getParam('name');
            $class = $this->getParam('class');
            $entityIdFieldType = $this->getParam('eav_entity_id_field_type', 'integer');
            $entityIdFieldLength = $this->getIntParam('eav_entity_id_field_length', 11);
            
            if (!$code) {
                throw new \InvalidArgumentException(__('实体代码不能为空'));
            }
            
            if (!$name) {
                throw new \InvalidArgumentException(__('实体名称不能为空'));
            }
            
            if (!$class) {
                throw new \InvalidArgumentException(__('实体类不能为空'));
            }
            
            // 验证类是否存在
            if (!class_exists($class)) {
                throw new \InvalidArgumentException(__('实体类不存在: %1', $class));
            }
            
            $entity = clone $this->eavEntity;
            
            // 检查代码是否重复
            if ($id) {
                $entity->load($id);
                if (!$entity->getId()) {
                    throw new \InvalidArgumentException(__('实体不存在: %1', $id));
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
            } else {
                // 检查代码是否已存在
                $existing = clone $this->eavEntity;
                $existing->where('code', $code)->find();
                if ($existing->getId()) {
                    throw new \InvalidArgumentException(__('实体代码已存在: %1', $code));
                }
            }
            
            $entity->setData('code', $code);
            $entity->setData('name', $name);
            $entity->setData('class', $class);
            $entity->setData('eav_entity_id_field_type', $entityIdFieldType);
            $entity->setData('eav_entity_id_field_length', $entityIdFieldLength);
            
            $entity->save();
            
            return $this->apiSuccess([
                'eav_entity_id' => $entity->getId(),
            ], $id ? __('实体更新成功') : __('实体创建成功'));
        });
    }

    /**
     * 删除实体
     * 
     * POST /eav/backend/api/entity/delete
     * 
     * @return string JSON响应
     */
    public function postDelete(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少实体ID'));
            }
            
            $entity = clone $this->eavEntity;
            $entity->load($id);
            
            if (!$entity->getId()) {
                throw new \InvalidArgumentException(__('实体不存在: %1', $id));
            }
            
            if ($entity->getData('is_system')) {
                throw new \InvalidArgumentException(__('系统实体不能删除'));
            }
            
            $entity->delete();
            
            return $this->apiSuccess(null, __('实体删除成功'));
        });
    }

    /**
     * 搜索实体（用于下拉选择）
     * 
     * GET /eav/backend/api/entity/search
     * 
     * @return string JSON响应
     */
    public function getSearch(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $limit = $this->getIntParam('limit', 20);
            
            $query = clone $this->eavEntity;
            $query->loadLocalDescription();
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, local.name)', "%{$search}%", 'like');
            }
            
            $query->limit($limit);
            $items = $query->select()->fetchArray();
            
            // 格式化为下拉选项
            $options = [];
            foreach ($items as $item) {
                $options[] = [
                    'value' => (int)$item['eav_entity_id'],
                    'label' => $item['local_name'] ?? $item['name'] ?? $item['code'],
                    'code' => $item['code'],
                ];
            }
            
            return $this->apiSuccess($options);
        });
    }
}
