<?php
/**
 * 数据库迁移记录模型
 * 
 * @author WelineFramework
 * @package Weline\Database\Model
 */

namespace Weline\Database\Model;

use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Model;

/**
 * 表由 Weline\Database\Setup\Install 创建。
 */
class Migration extends Model implements ModelInterface
{
    public const schema_table = 'weline_database_migrations';
    public const schema_primary_key = 'migration_id';

    public const schema_fields_ID = 'migration_id';
    public const schema_fields_MODULE = 'module_name';
    public const schema_fields_VERSION = 'version';
    public const schema_fields_FILE = 'migration_file';
    public const schema_fields_DESCRIPTION = 'description';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_EXECUTED_AT = 'executed_at';
    public const schema_fields_ROLLBACK_AT = 'rollback_at';
    public const schema_fields_DEPENDENCIES = 'dependencies';
    public const schema_fields_CHECKSUM = 'checksum';
    public const schema_fields_CREATED_AT = 'created_at';
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MANUAL = 'manual';

    /**
     * 获取模块的所有迁移记录
     *
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getModuleMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->order(self::schema_fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取已安装的迁移
     *
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getInstalledMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->order(self::schema_fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 检查迁移是否已存在
     *
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件名
     * @return bool
     */
    public function isMigrationExists(string $moduleName, string $migrationFile): bool
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->total() > 0;
    }
    
    /**
     * 记录迁移执行
     * 
     * @param array $data 迁移数据
     * @return int 插入的迁移记录 ID，失败返回 0
     */
    public function recordMigration(array $data): int
    {
        $this->clearData();
        $this->setData([
            self::schema_fields_MODULE => $data['module_name'],
            self::schema_fields_VERSION => $data['version'],
            self::schema_fields_FILE => $data['migration_file'],
            self::schema_fields_DESCRIPTION => $data['description'] ?? '',
            self::schema_fields_STATUS => $data['status'],
            self::schema_fields_DEPENDENCIES => json_encode($data['dependencies'] ?? []),
            self::schema_fields_CHECKSUM => $data['checksum'] ?? '',
            self::schema_fields_EXECUTED_AT => $data['executed_at'] ?? date('Y-m-d H:i:s')
        ]);
        
        $saved = $this->save();
        return $saved ? (int) $this->getId() : 0;
    }
    
    /**
     * 更新迁移状态
     * 
     * @param string $status 新状态
     * @return bool
     */
    public function updateStatus(string $status): bool
    {
        $this->setData(self::schema_fields_STATUS, $status);
        
        if ($status === self::STATUS_ROLLED_BACK) {
            $this->setData(self::schema_fields_ROLLBACK_AT, date('Y-m-d H:i:s'));
        }
        
        return (bool)$this->save();
    }
    
    /**
     * 按模块名和文件名查找迁移记录 ID
     *
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件名
     * @return int 记录 ID，未找到返回 0
     */
    public function findMigrationId(string $moduleName, string $migrationFile): int
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $first = $items[0] ?? null;
        return $first && $first->getId() ? (int) $first->getId() : 0;
    }

    /**
     * 获取迁移统计信息
     *
     * @param string $moduleName 模块名称
     * @return array{total: int, installed: int, failed: int, pending: int}
     */
    public function getMigrationStats(string $moduleName): array
    {
        $total = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->total();
        $installed = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->total();
        $failed = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_FAILED)
            ->total();
        return [
            'total' => $total,
            'installed' => $installed,
            'failed' => $failed,
            'pending' => $total - $installed - $failed
        ];
    }
}
