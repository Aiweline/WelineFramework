<?php

declare(strict_types=1);

/*
 * EAV属性集API控制器
 * 提供属性集的CRUD操作
 */

namespace Weline\Eav\Controller\Backend\Api;

use Weline\Eav\Model\EavAttribute\Set as EavSet;

/**
 * 属性集CRUD API
 */
class Set extends ApiController
{
    private EavSet $eavSet;

    public function __construct(EavSet $eavSet)
    {
        $this->eavSet = $eavSet;
    }

    /**
     * 获取属性集列表
     * 
     * GET /eav/backend/api/set
     * 
     * @return string JSON响应
     */
    public function getIndex(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $entityId = $this->getIntParam('entity_id');
            $page = $this->getIntParam('page', 1);
            $pageSize = $this->getIntParam('pageSize', 20);
            
            $query = clone $this->eavSet;
            $query->loadLocalDescription();
            
            if ($entityId) {
                $query->where('main_table.eav_entity_id', $entityId);
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
     * 获取属性集详情
     * 
     * GET /eav/backend/api/set/detail?id=1
     * 
     * @return string JSON响应
     */
    public function getDetail(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少属性集ID'));
            }
            
            $query = clone $this->eavSet;
            $query->loadLocalDescription();
            $query->where('main_table.set_id', $id);
            $set = $query->find()->fetchArray();
            
            if (empty($set)) {
                throw new \InvalidArgumentException(__('属性集不存在: %1', $id));
            }
            
            return $this->apiSuccess($set);
        });
    }

    /**
     * 保存属性集（新增或更新）
     * 
     * POST /eav/backend/api/set/save
     * 
     * @return string JSON响应
     */
    public function postSave(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('set_id');
            $entityId = $this->getIntParam('eav_entity_id');
            $code = $this->getParam('code');
            $name = $this->getParam('name');
            
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
                if (!$set->getId()) {
                    throw new \InvalidArgumentException(__('属性集不存在: %1', $id));
                }
            } else {
                // 检查代码是否已存在（同一实体下）
                $existing = clone $this->eavSet;
                $existing->where('code', $code)
                    ->where('eav_entity_id', $entityId)
                    ->find();
                if ($existing->getId()) {
                    throw new \InvalidArgumentException(__('该实体下属性集代码已存在: %1', $code));
                }
            }
            
            $set->setData('eav_entity_id', $entityId);
            $set->setData('code', $code);
            $set->setData('name', $name);
            
            $set->save();
            
            return $this->apiSuccess([
                'set_id' => $set->getId(),
            ], $id ? __('属性集更新成功') : __('属性集创建成功'));
        });
    }

    /**
     * 删除属性集
     * 
     * POST /eav/backend/api/set/delete
     * 
     * @return string JSON响应
     */
    public function postDelete(): string
    {
        return $this->tryCatch(function () {
            $id = $this->getIntParam('id');
            
            if (!$id) {
                throw new \InvalidArgumentException(__('缺少属性集ID'));
            }
            
            $set = clone $this->eavSet;
            $set->load($id);
            
            if (!$set->getId()) {
                throw new \InvalidArgumentException(__('属性集不存在: %1', $id));
            }
            
            // 检查是否有关联的属性组或属性
            // 这里可以添加更多验证逻辑
            
            $set->delete();
            
            return $this->apiSuccess(null, __('属性集删除成功'));
        });
    }

    /**
     * 搜索属性集（用于下拉选择）
     * 
     * GET /eav/backend/api/set/search
     * 
     * @return string JSON响应
     */
    public function getSearch(): string
    {
        return $this->tryCatch(function () {
            $search = $this->getParam('search', '');
            $entityId = $this->getIntParam('entity_id');
            $limit = $this->getIntParam('limit', 20);
            
            if (!$entityId) {
                throw new \InvalidArgumentException(__('请先选择实体'));
            }
            
            $query = clone $this->eavSet;
            $query->loadLocalDescription();
            $query->where('main_table.eav_entity_id', $entityId);
            
            if ($search) {
                $query->where('concat(main_table.code, main_table.name, local.name)', "%{$search}%", 'like');
            }
            
            $query->limit($limit);
            $items = $query->select()->fetchArray();
            
            // 格式化为下拉选项
            $options = [];
            foreach ($items as $item) {
                $options[] = [
                    'value' => (int)$item['set_id'],
                    'label' => $item['local_name'] ?? $item['name'] ?? $item['code'],
                    'code' => $item['code'],
                ];
            }
            
            return $this->apiSuccess($options);
        });
    }
}
