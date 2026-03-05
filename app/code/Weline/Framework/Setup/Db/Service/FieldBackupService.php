<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Db\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Database\DbManager\ConfigProvider as DbConfigProvider;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * 字段备份服务
 * 
 * 负责在字段删除前备份数据，在字段添加后恢复数据
 * 遵循SOLID原则，单一职责：专门处理字段级别的数据备份和恢复
 */
class FieldBackupService
{
    /** 主库 DbManager（由框架注入） */
    private DbManager $dbManager;

    /** 备份库连接工厂（可选，未配置时为 null） */
    private ?ConnectionFactory $backupConnectionFactory = null;

    private Printing $printing;
    
    // 备份存储表名（使用框架的备份表）
    private const BACKUP_TABLE = 'weline_framework_field_backup';
    
    public function __construct(
        DbManager $dbManager,
        Printing $printing
    ) {
        $this->dbManager = $dbManager;
        $this->printing = $printing;

        // 优先尝试使用独立的备份数据库配置（env.php 中 backup_db）
        $this->initBackupConnectionFactory();
    }

    /**
     * 获取当前用于备份/恢复的连接器：
     * - 如果配置了 backup_db，则使用备份库连接
     * - 否则回退到主库连接
     */
    private function getActiveConnector(): \Weline\Framework\Database\Connection\Api\ConnectorInterface
    {
        if ($this->backupConnectionFactory !== null) {
            return $this->backupConnectionFactory->getConnector();
        }

        // 使用主库 DbManager 的默认连接
        return $this->dbManager->getConnector();
    }

    /**
     * 初始化备份库连接工厂：从 env.php 中读取 backup_db 配置（可选）。
     * 结构与主库 db 一致，例如：
     *
     * 'backup_db' => [
     *     'default' => 'mysql',
     *     'master'  => [...],
     *     'slaves'  => [],
     * ]
     *
     * 如果未配置或配置异常，则保持为 null，后续统一回退到主库。
     */
    private function initBackupConnectionFactory(): void
    {
        try {
            $backupConf = Env::get('backup_db', []);
            if (is_array($backupConf) && !empty($backupConf)) {
                $configProvider = new DbConfigProvider($backupConf);
                $this->backupConnectionFactory = ConnectionFactory::getInstance($configProvider);
            }
        } catch (\Throwable $e) {
            // 备份库初始化失败时，不影响主流程，直接使用主库
            $this->backupConnectionFactory = null;
        }
    }
    
    /**
     * 备份字段数据
     * 
     * @param string $tableName 表名
     * @param string $fieldName 字段名
     * @param string $primaryKey 主键字段名
     * @param string $moduleName 模块名称
     * @param string $version 模块版本
     * @return bool 是否备份成功
     */
    public function backupFieldData(
        string $tableName,
        string $fieldName,
        string $primaryKey,
        string $moduleName,
        string $version
    ): bool {
        try {
            $connector = $this->getActiveConnector();
            
            // 检查字段是否存在
            if (!$this->hasField($tableName, $fieldName)) {
                $this->printing->warning(__('字段 %{1}.%{2} 不存在，无需备份', [$tableName, $fieldName]));
                return true;
            }
            
            // 在备份字段数据前，优先备份字段「定义信息」（DDL 元数据）
            $this->backupFieldDefinition($connector, $tableName, $fieldName, $moduleName, $version);

            // 使用查询器查询字段数据（主键 + 字段值），避免手写 SQL 造成方言问题
            // 注意：字段值可能为 NULL，我们也要备份 NULL 值
            $query = $connector->getQuery();
            $data = $query
                ->table($tableName)
                ->fields([$primaryKey, $fieldName])
                ->select()
                ->fetchArray();
            
            if (empty($data)) {
                $this->printing->debug(__('字段 %{1}.%{2} 没有数据需要备份', [$tableName, $fieldName]));
                return true;
            }
            
            // 确保备份表存在
            $this->ensureBackupTableExists();
            
            // 保存备份数据到备份表
            foreach ($data as $row) {
                $backupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldBackup::class);
                $backupModel->clearData()
                    ->setData('module', $moduleName)
                    ->setData('table_name', $tableName)
                    ->setData('field_name', $fieldName)
                    ->setData('primary_key', $primaryKey)
                    ->setData('primary_value', (string)$row[$primaryKey])
                    ->setData('field_value', $row[$fieldName] !== null ? json_encode($row[$fieldName], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null)
                    ->setData('version', $version)
                    ->setData('restored', 0)
                    ->save();
            }
            
            $this->printing->success(__('字段 %{1}.%{2} 数据备份完成，共 %{3} 条记录', [$tableName, $fieldName, count($data)]));
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error(__('备份字段 %{1}.%{2} 数据失败：%{3}', [$tableName, $fieldName, $e->getMessage()]));
            return false;
        }
    }
    
    /**
     * 恢复字段数据
     * 
     * @param string $tableName 表名
     * @param string $fieldName 字段名
     * @param string $moduleName 模块名称
     * @param string $version 模块版本（可选，如果提供则只恢复该版本的备份）
     * @return bool 是否恢复成功
     */
    public function restoreFieldData(
        string $tableName,
        string $fieldName,
        string $moduleName,
        ?string $version = null
    ): bool {
        try {
            $connector = $this->getActiveConnector();
            
            // 检查字段是否存在
            if (!$this->hasField($tableName, $fieldName)) {
                $this->printing->warning(__('字段 %{1}.%{2} 不存在，无法恢复数据', [$tableName, $fieldName]));
                return false;
            }
            
            // 查询备份数据
            $backupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldBackup::class);
            $backupModel->reset()
                ->where('module', $moduleName)
                ->where('table_name', $tableName)
                ->where('field_name', $fieldName)
                ->where('restored', 0);
            
            if ($version !== null) {
                $backupModel->where('version', $version);
            }
            
            $backups = $backupModel->select()->fetchArray();
            
            if (empty($backups)) {
                $this->printing->debug(__('字段 %{1}.%{2} 没有可恢复的备份数据', [$tableName, $fieldName]));
                return true;
            }

            // 恢复数据（存在冲突时不覆盖，只记录冲突日志）
            $restoredCount = 0;
            $conflictCount = 0;
            foreach ($backups as $backup) {
                $backupId = $backup['backup_id'] ?? null;
                $primaryKey = $backup['primary_key'] ?? '';
                $primaryValue = $backup['primary_value'] ?? '';
                $fieldValueJson = $backup['field_value'] ?? null;
                $backupVersion = $backup['version'] ?? '';
                
                // 解析字段值
                $fieldValue = null;
                if ($fieldValueJson !== null && $fieldValueJson !== '') {
                    $fieldValue = json_decode($fieldValueJson, true);
                }

                // 先查询当前表中该主键的现有值，用于冲突判断
                $currentQuery = $connector->getQuery();
                $currentRows = $currentQuery
                    ->table($tableName)
                    ->fields([$fieldName])
                    ->where($primaryKey, $primaryValue)
                    ->limit(1)
                    ->select()
                    ->fetchArray();
                $currentRow = $currentRows[0] ?? [];
                $currentValue = $currentRow[$fieldName] ?? null;

                $hasCurrent = $currentValue !== null && $currentValue !== '';

                if ($hasCurrent) {
                    // 存在人为或新版本写入的数据，不做覆盖，只做冲突记录
                    $conflictModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldBackupConflict::class);
                    $conflictModel->clearData()
                        ->setData('module', $moduleName)
                        ->setData('table_name', $tableName)
                        ->setData('field_name', $fieldName)
                        ->setData('primary_key', $primaryKey)
                        ->setData('primary_value', (string)$primaryValue)
                        ->setData('backup_value', $fieldValueJson)
                        ->setData(
                            'current_value',
                            $currentValue !== null
                                ? json_encode($currentValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : null
                        )
                        ->setData('version', $version ?? ($backupVersion ?: ''))
                        ->setData('note', '当前记录已存在非空值，跳过备份数据覆盖')
                        ->save();

                    $conflictCount++;
                    // 注意：此处不标记原备份为 restored，保留以便后续人工决策
                    continue;
                }

                // 没有现有值，可以安全恢复备份数据（使用查询器执行 UPDATE，避免直接调用底层 update 方法）
                $updateQuery = $connector->getQuery();
                $updateQuery
                    ->table($tableName)
                    ->where($primaryKey, $primaryValue)
                    ->update([$fieldName => $fieldValue])
                    ->fetchArray();

                // 标记为已恢复
                if ($backupId !== null) {
                    $updateBackupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldBackup::class);
                    $updateBackupModel->load($backupId)
                        ->setData('restored', 1)
                        ->setData('restore_time', date('Y-m-d H:i:s'))
                        ->save();
                }

                $restoredCount++;
            }
            
            $this->printing->success(
                __('字段 %{1}.%{2} 数据恢复完成，共恢复 %{3} 条记录，产生 %{4} 条冲突（已记录到冲突表）', [
                    $tableName,
                    $fieldName,
                    $restoredCount,
                    $conflictCount
                ])
            );
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error(__('恢复字段 %{1}.%{2} 数据失败：%{3}', [$tableName, $fieldName, $e->getMessage()]));
            return false;
        }
    }
    
    /**
     * 检查字段是否存在
     */
    private function hasField(string $tableName, string $fieldName): bool
    {
        $connection = $this->getActiveConnector();
        return $connection->hasField($tableName, $fieldName);
    }
    
    /**
     * 确保备份表存在
     */
    private function ensureBackupTableExists(): void
    {
        try {
            $backupModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldBackup::class);
            $conflictModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldBackupConflict::class);
            $definitionModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldDefinitionBackup::class);
            $setup = ObjectManager::make(\Weline\Framework\Setup\Db\ModelSetup::class);
            // 正确传递构造参数数组给 Context（模块名 + 模块版本）
            $context = ObjectManager::make(
                \Weline\Framework\Setup\Data\Context::class,
                ['module_name' => 'Weline_Framework', 'module_version' => '1.0.0']
            );
            $setup->putModel($backupModel);
            $backupModel->install($setup, $context);
            // 冲突记录表也一起初始化
            $setup->putModel($conflictModel);
            $conflictModel->install($setup, $context);
            // 字段定义备份表也一并初始化
            $setup->putModel($definitionModel);
            $definitionModel->install($setup, $context);
        } catch (\Exception $e) {
            // 如果表已存在或其他错误，忽略
            // 实际使用时，应该在框架初始化时确保这个表存在
        }
    }

    /**
     * 备份字段结构定义信息（DDL 元数据）
     *
     * 所有定义均按「模块 + 表名 + 字段名 + 模块版本」维度存储，
     * 不依赖任何系统级版本号。
     *
     * @param \Weline\Framework\Database\Connection\Api\ConnectorInterface $connection
     * @param string $tableName
     * @param string $fieldName
     * @param string $moduleName
     * @param string $version
     */
    private function backupFieldDefinition(
        \Weline\Framework\Database\Connection\Api\ConnectorInterface $connector,
        string $tableName,
        string $fieldName,
        string $moduleName,
        string $version
    ): void {
        try {
            // 通过适配器的 Query 层获取字段定义，方言细节下沉到各驱动实现
            $row = $connector->getQuery()->getColumnDefinition($tableName, $fieldName);

            if (!$row) {
                // 如果无法获取结构信息，不中断主流程，仅记录调试信息
                $this->printing->warning(
                    __('无法获取字段 %{1}.%{2} 的结构定义信息，已跳过结构备份', [$tableName, $fieldName])
                );
                return;
            }

            $definitionJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            /** @var \Weline\Framework\Setup\Model\FieldDefinitionBackup $defModel */
            $defModel = ObjectManager::getInstance(\Weline\Framework\Setup\Model\FieldDefinitionBackup::class);
            $defModel->clearData()
                ->setData('module', $moduleName)
                ->setData('table_name', $tableName)
                ->setData('field_name', $fieldName)
                ->setData('version', $version)
                ->setData('definition', $definitionJson)
                ->save();
        } catch (\Throwable $e) {
            // 结构备份失败不应影响主流程，只做警告输出
            $this->printing->warning(
                __('备份字段结构定义 %{1}.%{2} 失败：%{3}', [$tableName, $fieldName, $e->getMessage()])
            );
        }
    }
}
