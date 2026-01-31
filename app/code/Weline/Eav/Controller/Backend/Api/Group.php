<?php

declare(strict_types=1);

/*
 * EAV属性组API控制器
 * 提供属性组的CRUD操作
 */

namespace Weline\Eav\Controller\Backend\Api;

use Weline\Eav\Model\EavAttribute\Group as EavGroup;

/**
 * 属性组CRUD API
 */
class Group extends ApiController
{
    private EavGroup $eavGroup;

    public function __construct(EavGroup $eavGroup)
    {
        $this->eavGroup = $eavGroup;
    }

    /**
     * 获取属性组列表
     * 
     * GET /eav/backend/api/group
     * 
     * @return string JSON响应
     */
    public function getIndex(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $entityId = $this->getIntParam('entity_id');
            $setId = $this->getIntParam('set_id');
            $page = $this->getIntParam('page', 1);
            $pageSize = $this->getIntParam('pageSize', 20);
            
            $query = clone $this->eavGroup;
            $query->loadLocalDescription();
            
            if ($entityId) {
                $query->where('main_table.eav_entity_id', $entityId);
            }
            
            if ($setId) {
                $query->where('main_table.set_id', $setId);
            }
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, local.name)', "%{$search}%", 'like');
            }
            
            $query->pagination($page, $pageSize);
            $items = $query->select()->fetchArray();
            $pagination = $query->getPagination();
            
            return $this->paginated($items, $pagination);
        });
    }

    /**
     * 获取属性组详情
     * 
     * GET /eav/backend/api/group/detail?id=1
     * 
     * @return string JSON响应
     */
    public function getDetail(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少属性组ID'));
            }
            
            $query = clone $this->eavGroup;
            $query->loadLocalDescription();
            $query->where('main_table.group_id', $id);
            $group = $query->find()->fetchArray();
            
            if (empty($group)) {
                throw new \InvalidArgumentException(__('属性组不存在: %1', $id));
            }
            
            return $this->apiSuccess($group);
        });
    }

    /**
     * 保存属性组（新增或更新）
     * 
     * POST /eav/backend/api/group/save
     * 
     * @return string JSON响应
     */
    public function postSave(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('group_id');
            $entityId = $this->getIntParam('eav_entity_id');
            $setId = $this->getIntParam('set_id');
            $code = $this->getParam('code');
            $name = $this->getParam('name');
            
            if (!$entityId) {
                throw new \InvalidArgumentException(__('请选择所属实体'));
            }
            
            if (!$setId) {
                throw new \InvalidArgumentException(__('请选择所属属性集'));
            }
            
            if (!$code) {
                throw new \InvalidArgumentException(__('属性组代码不能为空'));
            }
            
            if (!$name) {
                throw new \InvalidArgumentException(__('属性组名称不能为空'));
            }
            
            $group = clone $this->eavGroup;
            
            if ($id) {
                $group->load($id);
                if (!$group->getId()) {
                    throw new \InvalidArgumentException(__('属性组不存在: %1', $id));
                }
            } else {
                // 检查代码是否已存在（同一实体和属性集下）
                $existing = clone $this->eavGroup;
                $existing->where('code', $code)
                    ->where('eav_entity_id', $entityId)
                    ->where('set_id', $setId)
                    ->find();
                if ($existing->getId()) {
                    throw new \InvalidArgumentException(__('该属性集下属性组代码已存在: %1', $code));
                }
            }
            
            $group->setData('eav_entity_id', $entityId);
            $group->setData('set_id', $setId);
            $group->setData('code', $code);
            $group->setData('name', $name);
            
            $group->save();
            
            return $this->apiSuccess([
                'group_id' => $group->getId(),
            ], $id ? __('属性组更新成功') : __('属性组创建成功'));
        });
    }

    /**
     * 删除属性组
     * 
     * POST /eav/backend/api/group/delete
     * 
     * @return string JSON响应
     */
    public function postDelete(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少属性组ID'));
            }
            
            $group = clone $this->eavGroup;
            $group->load($id);
            
            if (!$group->getId()) {
                throw new \InvalidArgumentException(__('属性组不存在: %1', $id));
            }
            
            // 检查是否有关联的属性
            // 这里可以添加更多验证逻辑
            
            $group->delete();
            
            return $this->apiSuccess(null, __('属性组删除成功'));
        });
    }

    /**
     * 搜索属性组（用于下拉选择）
     * 
     * GET /eav/backend/api/group/search
     * 
     * @return string JSON响应
     */
    public function getSearch(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $entityId = $this->getIntParam('entity_id');
            $setId = $this->getIntParam('set_id');
            $limit = $this->getIntParam('limit', 20);
            
            if (!$entityId) {
                throw new \InvalidArgumentException(__('请先选择实体'));
            }
            
            if (!$setId) {
                throw new \InvalidArgumentException(__('请先选择属性集'));
            }
            
            $query = clone $this->eavGroup;
            $query->loadLocalDescription();
            $query->where('main_table.eav_entity_id', $entityId);
            $query->where('main_table.set_id', $setId);
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, local.name)', "%{$search}%", 'like');
            }
            
            $query->limit($limit);
            $items = $query->select()->fetchArray();
            
            // 格式化为下拉选项
            $options = [];
            foreach ($items as $item) {
                $options[] = [
                    'value' => (int)$item['group_id'],
                    'label' => $item['local_name'] ?? $item['name'] ?? $item['code'],
                    'code' => $item['code'],
                ];
            }
            
            return $this->apiSuccess($options);
        });
    }
}
