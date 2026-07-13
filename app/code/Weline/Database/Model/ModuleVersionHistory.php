<?php

declare(strict_types=1);

/**
 * 模块版本历史模型
 * 表结构由 SchemaDiffStage 根据 #[Col] 同步。
 *
 * @author WelineFramework
 * @package Weline\Database\Model
 */

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table as TableAttribute;

#[TableAttribute(comment: 'Module Version History Table')]
class ModuleVersionHistory extends Model
{
    /** 列名 module_name 与 AbstractModel::$module_name 冲突，用 name 指定列名 */

    public const schema_table = 'weline_database_module_version_history';
    public const schema_primary_key = 'id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 100, nullable: false, comment: 'Module Name')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col('varchar', 50, nullable: false, comment: 'From Version')]
    public const schema_fields_FROM_VERSION = 'from_version';
    #[Col('varchar', 50, nullable: false, comment: 'To Version')]
    public const schema_fields_TO_VERSION = 'to_version';
    #[Col('varchar', 20, nullable: false, comment: 'Action Type: install/upgrade/rollback/uninstall')]
    public const schema_fields_ACTION = 'action';
    #[Col('varchar', 255, comment: 'Migration File')]
    public const schema_fields_MIGRATION_FILE = 'migration_file';
    #[Col('varchar', 20, default: 'cli', comment: 'Operator: cli/admin/system')]
    public const schema_fields_OPERATOR = 'operator';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created At')]
    public const schema_fields_CREATED_AT = 'created_at';

    /** VersionService 等使用的字段名常量（与 schema_fields_* 同值） */
    public const fields_MODULE_NAME = 'module_name';
    public const fields_FROM_VERSION = 'from_version';
    public const fields_TO_VERSION = 'to_version';
    public const fields_ACTION = 'action';
    public const fields_MIGRATION_FILE = 'migration_file';
    public const fields_OPERATOR = 'operator';
    public const fields_CREATED_AT = 'created_at';

    public const ACTION_INSTALL = 'install';
    public const ACTION_UPGRADE = 'upgrade';
    public const ACTION_ROLLBACK = 'rollback';
    public const ACTION_UNINSTALL = 'uninstall';
    public const OPERATOR_CLI = 'cli';
    public const OPERATOR_ADMIN = 'admin';
    public const OPERATOR_SYSTEM = 'system';

    public function _construct()
    {
        $this->init(self::schema_table, self::schema_primary_key);
    }

    /**
     * 获取模块版本历史
     */
    public function getModuleHistory(string $moduleName, int $limit = 50): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE_NAME, $moduleName)
            ->order(self::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取最近的版本操作
     */
    public function getLatestAction(string $moduleName): ?ModuleVersionHistory
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE_NAME, $moduleName)
            ->order(self::schema_fields_CREATED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        return $items[0] ?? null;
    }

    /**
     * 获取指定类型的历史记录
     */
    public function getHistoryByAction(string $moduleName, string $action, int $limit = 20): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE_NAME, $moduleName)
            ->where(self::schema_fields_ACTION, $action)
            ->order(self::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 统计模块版本操作次数
     */
    public function getActionStats(string $moduleName): array
    {
        $history = $this->reset()
            ->where(self::schema_fields_MODULE_NAME, $moduleName)
            ->select()
            ->fetch()
            ->getItems();

        $stats = [
            'total' => count($history),
            'install' => 0,
            'upgrade' => 0,
            'rollback' => 0,
            'uninstall' => 0,
        ];

        foreach ($history as $record) {
            $action = $record->getData(self::schema_fields_ACTION);
            if (isset($stats[$action])) {
                $stats[$action]++;
            }
        }

        return $stats;
    }
}
