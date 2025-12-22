<?php

declare(strict_types=1);

/*
 * 模块数据库备份服务
 *
 * 负责在模块卸载/重装过程中的数据库表备份与恢复。
 */

namespace Weline\ModuleManager\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\ModuleManager\Model\Module\Backup as BackupModel;
use Weline\ModuleManager\Model\Module\Table as ModuleTableModel;

class ModuleBackupService
{
    private Printing $printer;

    public function __construct()
    {
        $this->printer = ObjectManager::getInstance(Printing::class);
    }

    /**
     * 备份指定模块的所有表（通过重命名表实现快速备份）
     *
     * @param string $moduleName
     * @return array{success:bool,message:string,backup_timestamp?:string,backup_id?:int,table_count?:int,tables?:array}
     */
    public function backupModuleTables(string $moduleName): array
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return [
                'success' => false,
                'message' => __('模块名称不能为空'),
            ];
        }

        $timestamp  = date('Ymd_His');
        $backupDate = date('Y-m-d H:i:s');

        /** @var ModuleTableModel $moduleTableModel */
        $moduleTableModel = ObjectManager::getInstance(ModuleTableModel::class);
        $collection       = $moduleTableModel->getCollection();
        $collection->addFieldToFilter(ModuleTableModel::fields_module_name, $moduleName);
        $moduleTables = $collection->getItems();

        if (empty($moduleTables)) {
            return [
                'success'          => true,
                'message'          => __('模块 %{1} 没有注册的数据库表，无需备份', [$moduleName]),
                'backup_timestamp' => $timestamp,
                'backup_id'        => 0,
                'table_count'      => 0,
                'tables'           => [],
            ];
        }

        /** @var ConnectionFactory $connectionFactory */
        $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $connection        = $connectionFactory->getConnection();

        $tables      = [];
        $tableCount  = 0;
        $totalRecord = 0;

        foreach ($moduleTables as $moduleTable) {
            $tableName = (string)$moduleTable->getData('name');
            if ($tableName === '') {
                continue;
            }

            try {
                // 检查表是否存在（跨数据库使用通用 SELECT 语句）
                try {
                    $connection->query("SELECT 1 FROM {$tableName} LIMIT 1")->fetch();
                } catch (\Throwable $e) {
                    // 表不存在，跳过
                    $this->printer->warning(__('表 %{1} 不存在，跳过备份', [$tableName]));
                    continue;
                }

                // 统计记录数
                $countRow   = $connection->query("SELECT COUNT(*) AS cnt FROM {$tableName}")->fetch();
                $recordCount = isset($countRow[0]['cnt'])
                    ? (int)$countRow[0]['cnt']
                    : ((int)($countRow['cnt'] ?? 0));

                $backupTableName = $tableName . '_backup_' . $timestamp;

                $this->printer->note(__('备份模块 %{1} 表：%{2} → %{3}', [
                    $moduleName,
                    $tableName,
                    $backupTableName,
                ]));

                // 使用通用 ALTER TABLE RENAME 语法，兼容 MySQL / PostgreSQL / SQLite
                $connection->query("ALTER TABLE {$tableName} RENAME TO {$backupTableName}")->fetch();

                $tables[] = [
                    'original_name' => $tableName,
                    'backup_name'   => $backupTableName,
                    'record_count'  => $recordCount,
                ];
                $tableCount++;
                $totalRecord += $recordCount;

                $this->printer->success(__('  ✓ 表 %{1} 备份完成，记录数：%{2}', [$tableName, $recordCount]));
            } catch (\Throwable $e) {
                $this->printer->error(__('备份表 %{1} 失败：%{2}', [$tableName, $e->getMessage()]));
            }
        }

        // 如果没有成功备份任何表，返回失败
        if ($tableCount === 0) {
            return [
                'success' => false,
                'message' => __('模块 %{1} 的表备份失败或没有可备份的表', [$moduleName]),
            ];
        }

        // 记录备份信息
        /** @var BackupModel $backup */
        $backup = ObjectManager::getInstance(BackupModel::class);
        $backup->setModuleName($moduleName)
            ->setBackupTimestamp($timestamp)
            ->setBackupDate($backupDate)
            ->setTableCount($tableCount)
            ->setTables($tables)
            ->setStatus(BackupModel::status_active)
            ->save();

        $backupId = (int)$backup->getId();

        $this->printer->note(__('模块 %{1} 数据库备份完成：共 %{2} 个表，%{3} 条记录', [
            $moduleName,
            $tableCount,
            $totalRecord,
        ]));

        return [
            'success'          => true,
            'message'          => __('备份成功'),
            'backup_timestamp' => $timestamp,
            'backup_id'        => $backupId,
            'table_count'      => $tableCount,
            'tables'           => $tables,
        ];
    }

    /**
     * 恢复指定模块的表（从最近一次或指定时间戳的备份中恢复）
     *
     * @param string      $moduleName
     * @param string|null $backupTimestamp
     * @return array{success:bool,message:string,backup_id?:int}
     */
    public function restoreModuleTables(string $moduleName, ?string $backupTimestamp = null): array
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return [
                'success' => false,
                'message' => __('模块名称不能为空'),
            ];
        }

        $backup = $this->getLatestBackupModel($moduleName, $backupTimestamp);
        if (!$backup) {
            return [
                'success' => false,
                'message' => __('未找到模块 %{1} 的备份记录', [$moduleName]),
            ];
        }

        $tables = $backup->getTables();
        if (empty($tables)) {
            return [
                'success' => false,
                'message' => __('备份记录中不包含任何表信息'),
            ];
        }

        /** @var ConnectionFactory $connectionFactory */
        $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $connection        = $connectionFactory->getConnection();

        foreach ($tables as $tableInfo) {
            $originalName = $tableInfo['original_name'] ?? '';
            $backupName   = $tableInfo['backup_name'] ?? '';

            if ($originalName === '' || $backupName === '') {
                continue;
            }

            try {
                // 检查备份表是否存在
                try {
                    $connection->query("SELECT 1 FROM {$backupName} LIMIT 1")->fetch();
                } catch (\Throwable $e) {
                    $this->printer->warning(__('备份表 %{1} 不存在，跳过恢复', [$backupName]));
                    continue;
                }

                // 删除可能存在的当前表
                $connection->query("DROP TABLE IF EXISTS {$originalName}")->fetch();

                $this->printer->note(__('恢复表：%{1} → %{2}', [$backupName, $originalName]));
                $connection->query("ALTER TABLE {$backupName} RENAME TO {$originalName}")->fetch();

                $this->printer->success(__('  ✓ 表 %{1} 恢复完成', [$originalName]));
            } catch (\Throwable $e) {
                $this->printer->error(__('恢复表 %{1} 失败：%{2}', [$originalName, $e->getMessage()]));
            }
        }

        // 更新备份状态
        $backup->setStatus(BackupModel::status_restored)
            ->setRestoredAt(date('Y-m-d H:i:s'))
            ->save();

        return [
            'success'   => true,
            'message'   => __('模块 %{1} 数据库表已从备份恢复', [$moduleName]),
            'backup_id' => (int)$backup->getId(),
        ];
    }

    /**
     * 获取模块的所有备份记录（按时间倒序）
     */
    public function getModuleBackups(string $moduleName): array
    {
        /** @var BackupModel $backupModel */
        $backupModel = ObjectManager::getInstance(BackupModel::class);
        $collection  = $backupModel->getCollection();
        $collection->addFieldToFilter(BackupModel::fields_MODULE_NAME, $moduleName);
        $collection->setOrder(BackupModel::fields_BACKUP_TIMESTAMP, 'DESC');

        $items  = [];
        $models = $collection->getItems();
        foreach ($models as $item) {
            $items[] = $item->getData();
        }

        return $items;
    }

    /**
     * 获取模块最近一次备份
     */
    public function getLatestBackup(string $moduleName): ?array
    {
        $model = $this->getLatestBackupModel($moduleName, null);
        return $model ? $model->getData() : null;
    }

    /**
     * 删除备份记录（仅标记为 deleted，不直接删除物理表）
     */
    public function deleteBackup(string $moduleName, string $backupTimestamp): array
    {
        /** @var BackupModel $backupModel */
        $backupModel = ObjectManager::getInstance(BackupModel::class);
        $collection  = $backupModel->getCollection();
        $collection->addFieldToFilter(BackupModel::fields_MODULE_NAME, $moduleName);
        $collection->addFieldToFilter(BackupModel::fields_BACKUP_TIMESTAMP, $backupTimestamp);

        $items = $collection->getItems();
        if (empty($items)) {
            return [
                'success' => false,
                'message' => __('未找到指定的备份记录'),
            ];
        }

        /** @var BackupModel $backup */
        $backup = reset($items);
        $backup->setStatus(BackupModel::status_deleted)->save();

        return [
            'success' => true,
            'message' => __('备份记录已标记为删除'),
        ];
    }

    /**
     * 获取最近一次或指定时间戳的备份模型
     */
    private function getLatestBackupModel(string $moduleName, ?string $backupTimestamp = null): ?BackupModel
    {
        /** @var BackupModel $backupModel */
        $backupModel = ObjectManager::getInstance(BackupModel::class);
        $collection  = $backupModel->getCollection();
        $collection->addFieldToFilter(BackupModel::fields_MODULE_NAME, $moduleName);

        if ($backupTimestamp) {
            $collection->addFieldToFilter(BackupModel::fields_BACKUP_TIMESTAMP, $backupTimestamp);
        }

        $collection->setOrder(BackupModel::fields_BACKUP_TIMESTAMP, 'DESC');
        $items = $collection->getItems();
        if (empty($items)) {
            return null;
        }

        /** @var BackupModel $backup */
        $backup = reset($items);
        return $backup;
    }
}


