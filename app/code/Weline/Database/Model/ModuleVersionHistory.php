<?php
/**
 * 模块版本历史模型
 * 
 * @author WelineFramework
 * @package Weline\Database\Model
 */

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;

class ModuleVersionHistory extends Model implements ModelInterface
{
    public const fields_ID = 'id';
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
        $this->init('weline_database_module_version_history', self::fields_ID);
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('Module Version History Table')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_MODULE_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', 'Module Name')
                ->addColumn(self::fields_FROM_VERSION, TableInterface::column_type_VARCHAR, 50, 'not null', 'From Version')
                ->addColumn(self::fields_TO_VERSION, TableInterface::column_type_VARCHAR, 50, 'not null', 'To Version')
                ->addColumn(self::fields_ACTION, TableInterface::column_type_VARCHAR, 20, 'not null', 'Action Type: install/upgrade/rollback/uninstall')
                ->addColumn(self::fields_MIGRATION_FILE, TableInterface::column_type_VARCHAR, 255, 'default null', 'Migration File')
                ->addColumn(self::fields_OPERATOR, TableInterface::column_type_VARCHAR, 20, 'default "cli"', 'Operator: cli/admin/system')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Created At')
                ->create();
        }
    }
    
    /**
     * 获取模块版本历史
     * 
     * @param string $moduleName 模块名称
     * @param int $limit 限制数量
     * @return array
     */
    public function getModuleHistory(string $moduleName, int $limit = 50): array
    {
        return $this->reset()
            ->where(self::fields_MODULE_NAME, $moduleName)
            ->order(self::fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取最近的版本操作
     * 
     * @param string $moduleName 模块名称
     * @return ModuleVersionHistory|null
     */
    public function getLatestAction(string $moduleName): ?ModuleVersionHistory
    {
        $items = $this->reset()
            ->where(self::fields_MODULE_NAME, $moduleName)
            ->order(self::fields_CREATED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        return $items[0] ?? null;
    }
    
    /**
     * 获取指定类型的历史记录
     * 
     * @param string $moduleName 模块名称
     * @param string $action 操作类型
     * @param int $limit 限制数量
     * @return array
     */
    public function getHistoryByAction(string $moduleName, string $action, int $limit = 20): array
    {
        return $this->reset()
            ->where(self::fields_MODULE_NAME, $moduleName)
            ->where(self::fields_ACTION, $action)
            ->order(self::fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 统计模块版本操作次数
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getActionStats(string $moduleName): array
    {
        $history = $this->reset()
            ->where(self::fields_MODULE_NAME, $moduleName)
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
            $action = $record->getData(self::fields_ACTION);
            if (isset($stats[$action])) {
                $stats[$action]++;
            }
        }
        
        return $stats;
    }
}
