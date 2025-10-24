<?php

namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;

/**
 * 回收站管理API
 * 提供软删除记录的管理功能
 */
class Trash extends BackendRestController
{
    /**
     * 获取回收站数据
     */
    public function getData()
    {
        $model = $this->request->getParam('model');
        $page = max(1, intval($this->request->getParam('page', 1)));
        $limit = max(1, min(100, intval($this->request->getParam('limit', 20))));
        $filters = $this->request->getParam('filters', []);
        $sort = $this->request->getParam('sort', []);

        try {
            if (empty($model)) {
                return $this->error('缺少必需参数: model');
            }

            if (!class_exists($model)) {
                return $this->error("模型类不存在: {$model}");
            }

            $modelInstance = new $model();
            
            // 检查模型是否支持软删除
            if (!method_exists($modelInstance, 'onlyTrashed')) {
                return $this->error('该模型不支持软删除功能');
            }

            // 查询软删除的记录
            $query = $modelInstance->onlyTrashed();

            // 应用筛选条件
            if (!empty($filters)) {
                foreach ($filters as $field => $value) {
                    if (!empty($value)) {
                        if (is_array($value)) {
                            $query->where($field, 'IN', $value);
                        } else {
                            $query->where($field, 'LIKE', "%{$value}%");
                        }
                    }
                }
            }

            // 应用排序
            if (!empty($sort)) {
                foreach ($sort as $field => $direction) {
                    $query->order($field, strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
                }
            } else {
                // 默认按删除时间倒序
                $softDeleteField = method_exists($modelInstance, 'getSoftDeleteField') 
                    ? $modelInstance->getSoftDeleteField() 
                    : 'deleted_at';
                $query->order($softDeleteField, 'DESC');
            }

            // 分页查询
            $data = $query->pagination($page, $limit)->select()->fetch();
            $total = $modelInstance->onlyTrashed()->count();

            return $this->success('回收站数据获取成功', [
                'data' => $data ?: [],
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]);

        } catch (\Exception $e) {
            return $this->error('回收站数据获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 恢复记录
     */
    public function restore()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $ids = $this->request->getParam('ids', []);

        try {
            if (empty($model)) {
                return $this->error('缺少必需参数: model');
            }

            if (!class_exists($model)) {
                return $this->error("模型类不存在: {$model}");
            }

            $modelInstance = new $model();

            // 检查模型是否支持软删除
            if (!method_exists($modelInstance, 'restore')) {
                return $this->error('该模型不支持恢复功能');
            }

            // 批量恢复
            if (!empty($ids) && is_array($ids)) {
                return $this->batchRestore($modelInstance, $ids);
            }

            // 单个恢复
            if (empty($id)) {
                return $this->error('缺少必需参数: id 或 ids');
            }

            $modelInstance->withTrashed()->load($id);
            if (!$modelInstance->getId()) {
                return $this->error('记录不存在');
            }

            if (!$modelInstance->isTrashed()) {
                return $this->error('该记录未被删除，无需恢复');
            }

            $result = $modelInstance->restore();
            if ($result) {
                return $this->success('记录恢复成功');
            } else {
                return $this->error('记录恢复失败');
            }

        } catch (\Exception $e) {
            return $this->error('恢复失败: ' . $e->getMessage());
        }
    }

    /**
     * 永久删除记录
     */
    public function forceDelete()
    {
        $model = $this->request->getParam('model');
        $id = $this->request->getParam('id');
        $ids = $this->request->getParam('ids', []);

        try {
            if (empty($model)) {
                return $this->error('缺少必需参数: model');
            }

            if (!class_exists($model)) {
                return $this->error("模型类不存在: {$model}");
            }

            $modelInstance = new $model();

            // 检查模型是否支持软删除
            if (!method_exists($modelInstance, 'forceDelete')) {
                return $this->error('该模型不支持永久删除功能');
            }

            // 批量永久删除
            if (!empty($ids) && is_array($ids)) {
                return $this->batchForceDelete($modelInstance, $ids);
            }

            // 单个永久删除
            if (empty($id)) {
                return $this->error('缺少必需参数: id 或 ids');
            }

            $modelInstance->withTrashed()->load($id);
            if (!$modelInstance->getId()) {
                return $this->error('记录不存在');
            }

            $result = $modelInstance->forceDelete();
            if ($result) {
                return $this->success('记录已永久删除');
            } else {
                return $this->error('永久删除失败');
            }

        } catch (\Exception $e) {
            return $this->error('永久删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 清空回收站
     */
    public function empty()
    {
        $model = $this->request->getParam('model');
        $confirm = $this->request->getParam('confirm', false);

        try {
            if (empty($model)) {
                return $this->error('缺少必需参数: model');
            }

            if (!$confirm) {
                return $this->error('请确认清空回收站操作');
            }

            if (!class_exists($model)) {
                return $this->error("模型类不存在: {$model}");
            }

            $modelInstance = new $model();

            // 检查模型是否支持软删除
            if (!method_exists($modelInstance, 'onlyTrashed') || !method_exists($modelInstance, 'forceDelete')) {
                return $this->error('该模型不支持回收站功能');
            }

            // 获取所有软删除的记录
            $trashedRecords = $modelInstance->onlyTrashed()->select()->fetch();
            $deletedCount = 0;

            foreach ($trashedRecords as $record) {
                $model = clone $modelInstance;
                $model->load($record['id']);
                if ($model->forceDelete()) {
                    $deletedCount++;
                }
            }

            return $this->success("回收站已清空，共删除 {$deletedCount} 条记录", [
                'deleted_count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            return $this->error('清空回收站失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量恢复
     */
    private function batchRestore($modelInstance, array $ids)
    {
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $model = clone $modelInstance;
                $model->withTrashed()->load($id);
                
                if (!$model->getId()) {
                    $failedCount++;
                    $errors[] = "ID {$id} 记录不存在";
                    continue;
                }

                if (!$model->isTrashed()) {
                    $failedCount++;
                    $errors[] = "ID {$id} 记录未被删除，无需恢复";
                    continue;
                }

                if ($model->restore()) {
                    $successCount++;
                } else {
                    $failedCount++;
                    $errors[] = "ID {$id} 恢复失败";
                }
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = "ID {$id} 恢复失败: " . $e->getMessage();
            }
        }

        $message = "批量恢复完成，成功: {$successCount}，失败: {$failedCount}";

        if ($failedCount > 0) {
            return $this->success($message, [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'errors' => $errors
            ]);
        } else {
            return $this->success($message, [
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ]);
        }
    }

    /**
     * 批量永久删除
     */
    private function batchForceDelete($modelInstance, array $ids)
    {
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $model = clone $modelInstance;
                $model->withTrashed()->load($id);
                
                if (!$model->getId()) {
                    $failedCount++;
                    $errors[] = "ID {$id} 记录不存在";
                    continue;
                }

                if ($model->forceDelete()) {
                    $successCount++;
                } else {
                    $failedCount++;
                    $errors[] = "ID {$id} 永久删除失败";
                }
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = "ID {$id} 永久删除失败: " . $e->getMessage();
            }
        }

        $message = "批量永久删除完成，成功: {$successCount}，失败: {$failedCount}";

        if ($failedCount > 0) {
            return $this->success($message, [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'errors' => $errors
            ]);
        } else {
            return $this->success($message, [
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ]);
        }
    }
}
