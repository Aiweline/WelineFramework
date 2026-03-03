<?php

namespace Weline\DataTable\Model\Trait;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;

/**
 * 软删除功能Trait
 * 为模型提供软删除功能，删除的数据可以恢复
 */
trait SoftDelete
{
    /**
     * 软删除字段名
     */
    protected string $softDeleteField = 'deleted_at';

    /**
     * 是否启用软删除
     */
    protected bool $enableSoftDelete = true;

    /**
     * 软删除时间戳
     */
    protected ?string $deletedAt = null;

    /**
     * 初始化软删除功能
     */
    public function initSoftDelete(): void
    {
        // 确保软删除字段存在
        $this->addSoftDeleteField();
        
        // 默认查询时排除已软删除的记录
        if ($this->enableSoftDelete) {
            $this->where($this->softDeleteField, 'IS', 'NULL');
        }
    }

    /**
     * 添加软删除字段到数据表
     */
    protected function addSoftDeleteField(): void
    {
        try {
            $table = $this->getTable();
            $connection = $this->getConnection();
            
            // 检查字段是否已存在
            $columns = $connection->getColumns($table);
            $fieldExists = false;
            
            foreach ($columns as $column) {
                if ($column['COLUMN_NAME'] === $this->softDeleteField) {
                    $fieldExists = true;
                    break;
                }
            }
            
            // 如果字段不存在，则添加
            if (!$fieldExists) {
                $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$this->softDeleteField}` TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间'";
                $connection->query($sql);
            }
        } catch (\Exception $e) {
            // 静默处理，避免影响正常功能
            w_log_error('SoftDelete field creation failed: ' . $e->getMessage());
        }
    }

    /**
     * 软删除记录
     */
    public function softDelete(): bool
    {
        if (!$this->getId()) {
            return false;
        }

        try {
            $this->setData($this->softDeleteField, date('Y-m-d H:i:s'));
            return $this->save();
        } catch (\Exception $e) {
            w_log_error('Soft delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 恢复软删除的记录
     */
    public function restore(): bool
    {
        if (!$this->getId()) {
            return false;
        }

        try {
            $this->setData($this->softDeleteField, null);
            return $this->save();
        } catch (\Exception $e) {
            w_log_error('Restore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 永久删除记录（物理删除）
     */
    public function forceDelete(): bool
    {
        return parent::delete();
    }

    /**
     * 重写删除方法，默认使用软删除
     */
    public function delete(): bool
    {
        if ($this->enableSoftDelete) {
            return $this->softDelete();
        } else {
            return $this->forceDelete();
        }
    }

    /**
     * 查询包含软删除的记录
     */
    public function withTrashed(): self
    {
        // 移除软删除条件
        $this->clearWhere($this->softDeleteField);
        return $this;
    }

    /**
     * 只查询软删除的记录
     */
    public function onlyTrashed(): self
    {
        $this->clearWhere($this->softDeleteField);
        $this->where($this->softDeleteField, 'IS NOT', 'NULL');
        return $this;
    }

    /**
     * 检查记录是否被软删除
     */
    public function isTrashed(): bool
    {
        return !empty($this->getData($this->softDeleteField));
    }

    /**
     * 获取软删除时间
     */
    public function getDeletedAt(): ?string
    {
        return $this->getData($this->softDeleteField);
    }

    /**
     * 设置软删除字段名
     */
    public function setSoftDeleteField(string $field): self
    {
        $this->softDeleteField = $field;
        return $this;
    }

    /**
     * 获取软删除字段名
     */
    public function getSoftDeleteField(): string
    {
        return $this->softDeleteField;
    }

    /**
     * 启用或禁用软删除
     */
    public function enableSoftDelete(bool $enable = true): self
    {
        $this->enableSoftDelete = $enable;
        return $this;
    }

    /**
     * 检查是否启用了软删除
     */
    public function isSoftDeleteEnabled(): bool
    {
        return $this->enableSoftDelete;
    }

    /**
     * 清除指定字段的where条件
     */
    protected function clearWhere(string $field): void
    {
        // 这个方法需要在具体的模型基类中实现
        // 用于清除特定字段的查询条件
    }

    /**
     * 批量软删除
     */
    public function batchSoftDelete(array $ids): array
    {
        $results = [];
        foreach ($ids as $id) {
            $model = clone $this;
            $model->load($id);
            if ($model->getId()) {
                $results[$id] = $model->softDelete();
            } else {
                $results[$id] = false;
            }
        }
        return $results;
    }

    /**
     * 批量恢复
     */
    public function batchRestore(array $ids): array
    {
        $results = [];
        foreach ($ids as $id) {
            $model = clone $this;
            $model->withTrashed()->load($id);
            if ($model->getId()) {
                $results[$id] = $model->restore();
            } else {
                $results[$id] = false;
            }
        }
        return $results;
    }

    /**
     * 批量永久删除
     */
    public function batchForceDelete(array $ids): array
    {
        $results = [];
        foreach ($ids as $id) {
            $model = clone $this;
            $model->withTrashed()->load($id);
            if ($model->getId()) {
                $results[$id] = $model->forceDelete();
            } else {
                $results[$id] = false;
            }
        }
        return $results;
    }

    /**
     * 清理过期的软删除记录
     * @param int $days 保留天数，默认180天
     */
    public function cleanupExpiredSoftDeleted(int $days = 180): int
    {
        try {
            $expiredDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            // 查找过期的软删除记录
            $expiredRecords = $this->withTrashed()
                ->where($this->softDeleteField, '<=', $expiredDate)
                ->select()
                ->fetch();
            
            $deletedCount = 0;
            foreach ($expiredRecords as $record) {
                $model = clone $this;
                $model->load($record['id']);
                if ($model->forceDelete()) {
                    $deletedCount++;
                }
            }
            
            return $deletedCount;
        } catch (\Exception $e) {
            w_log_error('Cleanup expired soft deleted records failed: ' . $e->getMessage());
            return 0;
        }
    }
}
