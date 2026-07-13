<?php
/**
 * 数据库迁移服务
 * 
 * @author WelineFramework
 * @package Weline\Database\Service
 */

namespace Weline\Database\Service;

use Weline\Framework\Database\Migration\MigrationInterface;
use Weline\Framework\Database\Migration\RollbackBackupStrategyInterface;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\VersionService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\Setup\Model\MigrationBackup;

class MigrationService
{
    private ConnectionFactory $connectionFactory;
    private Migration $migrationModel;
    private BackupService $backupService;
    private VersionService $versionService;
    private Printing $printing;
    
    public function __construct(
        ConnectionFactory $connectionFactory,
        Migration $migrationModel,
        BackupService $backupService,
        VersionService $versionService,
        Printing $printing
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->migrationModel = $migrationModel;
        $this->backupService = $backupService;
        $this->versionService = $versionService;
        $this->printing = $printing;
    }
    
    /**
     * 执行迁移升级
     * 
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件路径
     * @return bool
     */
    public function upgradeMigration(string $moduleName, string $migrationFile): bool
    {
        try {
            if (!file_exists($migrationFile)) {
                throw new \Exception(__("迁移文件不存在: %{1}", $migrationFile));
            }
            
            $migrationClass = $this->loadMigrationClass($migrationFile);
            if (!$migrationClass instanceof MigrationInterface) {
                throw new \Exception(__("迁移类必须实现MigrationInterface接口"));
            }
            
            if (!$migrationClass->validate()) {
                throw new \Exception(__("迁移前置条件验证失败"));
            }
            
            $dependencies = $migrationClass->getDependencies();
            if (!$this->checkDependencies($moduleName, $dependencies)) {
                throw new \Exception(__("迁移依赖未满足"));
            }
            
            $transactional = $this->isDataOnlyMigration($migrationClass);
            $query = $transactional ? $this->connectionFactory->query('SELECT 1') : null;
            if ($query !== null) {
                $query->beginTransaction();
            }

            $migrationId = 0;
            try {
                $needsBackup = $this->migrationRequiresBackup($migrationClass);
                $migrationId = $this->insertMigrationRecord(
                    $moduleName,
                    $migrationFile,
                    $migrationClass,
                    Migration::STATUS_RUNNING
                );
                if ($migrationId <= 0) {
                    throw new \RuntimeException(__('无法记录迁移运行状态'));
                }

                if ($needsBackup) {
                    $this->performBackup($migrationClass, $migrationId);
                }
                
                $result = $migrationClass->install();
                
                if (!$result) {
                    throw new \Exception(__("迁移执行失败"));
                }

                $this->restorePreviouslyRolledBackBackups(
                    $moduleName,
                    $migrationFile,
                    $migrationId,
                );
                
                $this->updateMigrationStatusById($migrationId, Migration::STATUS_INSTALLED);
                if ($query !== null) {
                    $query->commit();
                }
                
                $this->printing->success(__("迁移升级成功: %{1}", $migrationFile));
                return true;
                
            } catch (\Throwable $e) {
                if ($query !== null) {
                    $query->rollBack();
                }
                if ($migrationId > 0) {
                    $this->updateMigrationStatusById($migrationId, Migration::STATUS_FAILED);
                }
                throw $e;
            }
            
        } catch (\Throwable $e) {
            $this->printing->error(__("迁移升级失败: %{1}", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * 执行迁移回滚
     * 
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件路径
     * @return bool
     */
    public function rollbackMigration(
        string $moduleName,
        string $migrationFile,
        string $operationId = '',
        ?array $expectedBackupStrategy = null,
    ): bool
    {
        try {
            if ($operationId === '') {
                throw new \RuntimeException(__(
                    '已禁止独立迁移回滚；请通过 ModuleRollbackManagerInterface 创建联动回滚任务'
                ));
            }
            if (!is_file($migrationFile)) {
                throw new \RuntimeException(__('迁移文件不存在: %{1}', $migrationFile));
            }
            $filename = basename($migrationFile);
            $record = $this->getInstalledMigrationRecord($moduleName, $filename);
            if ($record === null) {
                throw new \RuntimeException(__("未找到已安装迁移记录: %{1}", $migrationFile));
            }
            $this->assertMigrationChecksum($record, $migrationFile);

            $migrationClass = $this->loadMigrationClass($migrationFile);
            if (!$migrationClass instanceof MigrationInterface) {
                throw new \Exception(__("迁移类必须实现MigrationInterface接口"));
            }

            $backupStrategy = $this->resolveRollbackBackupStrategy($migrationClass);
            if ($expectedBackupStrategy !== null
                && $this->canonicalJson($backupStrategy) !== $this->canonicalJson($expectedBackupStrategy)) {
                throw new \RuntimeException(__('迁移回滚备份策略在预检后已变化: %{1}', $filename));
            }
            $migrationId = (int)$record->getId();
            $this->performRollbackBackup($migrationClass, $migrationId, $operationId, $backupStrategy);
            
            $transactional = $this->isDataOnlyMigration($migrationClass);
            $query = $transactional ? $this->connectionFactory->query('SELECT 1') : null;
            if ($query !== null) {
                $query->beginTransaction();
            }

            try {
                $result = $migrationClass->uninstall();
                
                if (!$result) {
                    throw new \Exception(__("迁移回滚失败"));
                }
                
                if ($migrationId > 0) {
                    $this->restoreBackupsForMigration($migrationId);
                }
                
                $record->updateStatus(Migration::STATUS_ROLLED_BACK);
                if ($query !== null) {
                    $query->commit();
                }
                
                $this->printing->success(__("迁移回滚成功: %{1}", $migrationFile));
                return true;
                
            } catch (\Throwable $e) {
                if ($query !== null) {
                    $query->rollBack();
                }
                throw $e;
            }
            
        } catch (\Throwable $e) {
            $this->printing->error(__("迁移回滚失败: %{1}", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * 执行迁移卸载
     * 
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件路径
     * @return bool
     */
    public function uninstallMigration(string $moduleName, string $migrationFile): bool
    {
        try {
            if (!is_file($migrationFile)) {
                throw new \RuntimeException(__('迁移文件不存在: %{1}', $migrationFile));
            }
            $record = $this->getInstalledMigrationRecord($moduleName, basename($migrationFile));
            if ($record === null) {
                throw new \RuntimeException(__("迁移记录不存在: %{1}", $migrationFile));
            }
            $this->assertMigrationChecksum($record, $migrationFile);

            $migrationClass = $this->loadMigrationClass($migrationFile);
            if (!$migrationClass instanceof MigrationInterface) {
                throw new \Exception(__("迁移类必须实现MigrationInterface接口"));
            }

            $query = $this->isDataOnlyMigration($migrationClass)
                ? $this->connectionFactory->query('SELECT 1')
                : null;
            $query?->beginTransaction();
            
            try {
                // 执行卸载
                $result = $migrationClass->uninstall();
                
                if (!$result) {
                    throw new \Exception(__("迁移卸载失败"));
                }
                
                $record->updateStatus(Migration::STATUS_ROLLED_BACK);
                $query?->commit();
                
                $this->printing->success(__("迁移卸载成功: %{1}", $migrationFile));
                return true;
                
            } catch (\Throwable $e) {
                $query?->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->printing->error(__("迁移卸载失败: %{1}", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * 获取模块的所有迁移文件（基于已注册模块的 base_path，不扫描未知路径）
     *
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getModuleMigrations(string $moduleName): array
    {
        $migrationPath = $this->getMigrationPath($moduleName);
        if ($migrationPath === '' || !is_dir($migrationPath)) {
            return [];
        }

        $files = glob($migrationPath . "*.php");
        $migrations = [];
        
        foreach ($files as $file) {
            $migration = $this->loadMigrationClass($file);
            $migrations[] = [
                'file' => $file,
                'filename' => basename($file),
                'class' => $migration::class,
                'version' => $migration->getVersion(),
                'checksum' => hash_file('sha256', $file) ?: '',
            ];
        }
        
        // 按文件名排序
        usort($migrations, function($a, $b) {
            $versionOrder = version_compare($a['version'], $b['version']);
            return $versionOrder !== 0 ? $versionOrder : strcmp($a['filename'], $b['filename']);
        });
        
        return $migrations;
    }
    
    /**
     * 获取待执行的迁移
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getPendingMigrations(string $moduleName): array
    {
        RegistryProgress::log('Migration pending lookup started: ' . $moduleName);
        $allMigrations = $this->getModuleMigrations($moduleName);
        RegistryProgress::count('Migration script files for ' . $moduleName, count($allMigrations), 'files');
        $installedFiles = array_fill_keys($this->migrationModel->getInstalledMigrationFiles($moduleName), true);
        RegistryProgress::count('Installed migration script records for ' . $moduleName, count($installedFiles), 'files');

        $pending = [];
        foreach ($allMigrations as $migration) {
            if (!isset($installedFiles[$migration['filename']])) {
                $pending[] = $migration;
            }
        }

        RegistryProgress::count('Pending migration script files for ' . $moduleName, count($pending), 'files');
        return $pending;
    }
    
    /**
     * 加载迁移类
     * 
     * @param string $migrationFile 迁移文件路径
     * @return MigrationInterface
     */
    private function loadMigrationClass(string $migrationFile): MigrationInterface
    {
        if (!is_file($migrationFile)) {
            throw new \RuntimeException(__('迁移文件不存在: %{1}', $migrationFile));
        }

        $className = $this->readMigrationClassName($migrationFile);
        require_once $migrationFile;
        if ($className === null || !class_exists($className)) {
            throw new \RuntimeException(__('迁移文件未声明可加载类: %{1}', $migrationFile));
        }

        // 使用 ObjectManager 的非共享实例入口：它同时支持无显式构造函数和带 DI 构造函数的迁移类。
        $instance = ObjectManager::getInstance($className, [], false);
        if (!$instance instanceof MigrationInterface) {
            throw new \RuntimeException(__('迁移类必须实现 MigrationInterface: %{1}', $className));
        }

        return $instance;
    }
    
    
    /**
     * 检查迁移依赖
     * 
     * @param string $moduleName 模块名称
     * @param array $dependencies 依赖列表
     * @return bool
     */
    private function checkDependencies(string $moduleName, array $dependencies): bool
    {
        if (empty($dependencies)) {
            return true;
        }
        
        $installedFiles = array_fill_keys($this->migrationModel->getInstalledMigrationFiles($moduleName), true);
        
        foreach ($dependencies as $dependency) {
            if (!isset($installedFiles[$dependency])) {
                $this->printing->error(__("依赖迁移未安装: %{1}", $dependency));
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 记录迁移执行（status = installed）
     */
    private function recordMigration(string $moduleName, string $migrationFile, MigrationInterface $migrationClass): int
    {
        return $this->insertMigrationRecord($moduleName, $migrationFile, $migrationClass, Migration::STATUS_INSTALLED);
    }

    /**
     * 插入迁移记录
     *
     * @return int 记录 ID
     */
    private function insertMigrationRecord(
        string $moduleName,
        string $migrationFile,
        MigrationInterface $migrationClass,
        string $status
    ): int {
        $data = [
            'module_name'    => $moduleName,
            'version'        => $migrationClass->getVersion(),
            'migration_file' => basename($migrationFile),
            'description'    => $migrationClass->getDescription(),
            'status'         => $status,
            'dependencies'   => $migrationClass->getDependencies(),
            'checksum'       => file_exists($migrationFile) ? hash_file('sha256', $migrationFile) : '',
            'executed_at'    => date('Y-m-d H:i:s'),
            'migration_type' => 'script',
            'operation_kind' => method_exists($migrationClass, 'getType') ? $migrationClass->getType() : 'script',
        ];

        return $this->migrationModel->recordMigration($data);
    }

    /**
     * 判断迁移脚本是否需要备份（兼容未实现新方法的旧脚本）
     */
    private function migrationRequiresBackup(MigrationInterface $migration): bool
    {
        if (method_exists($migration, 'requiresBackup')) {
            return $migration->requiresBackup();
        }
        return false;
    }

    private function isDataOnlyMigration(MigrationInterface $migration): bool
    {
        return method_exists($migration, 'getType') && $migration->getType() === 'data_migration';
    }

    /**
     * 根据备份策略执行备份
     */
    private function performBackup(MigrationInterface $migration, int $migrationId): void
    {
        $strategy = method_exists($migration, 'getBackupStrategy')
            ? $migration->getBackupStrategy()
            : ['strategy' => 'none', 'tables' => [], 'columns' => []];

        $strategyName = strtolower(trim((string)($strategy['strategy'] ?? 'none')));
        $tables = $this->normalizeIdentifierList((array)($strategy['tables'] ?? []));
        $columns = $this->normalizeIdentifierList((array)($strategy['columns'] ?? []));
        if (!in_array($strategyName, ['column', 'table'], true) || $tables === []) {
            throw new \RuntimeException(__(
                '迁移声明 requiresBackup=true，但未提供可执行的 table/column 备份策略'
            ));
        }
        if ($strategyName === 'column' && $columns === []) {
            throw new \RuntimeException(__('列备份策略必须声明至少一个字段'));
        }

        foreach ($tables as $table) {
            if ($strategyName === 'column') {
                foreach ($columns as $column) {
                    $this->backupService->backupColumnData($table, $column, $migrationId);
                }
            } else {
                if (!$this->backupService->backupTableStructure($table, $migrationId)) {
                    throw new \RuntimeException(__('无法备份表 %{1} 的结构', $table));
                }
                $this->backupService->backupTableData($table, $migrationId);
            }
        }

        $this->printing->info(__("迁移备份完成 (migration_id: %{1})", $migrationId));
    }

    /**
     * 回滚时恢复关联备份
     */
    private function restoreBackupsForMigration(int $migrationId): void
    {
        $backups = array_values(array_filter(
            $this->backupService->getBackupsByMigrationId($migrationId),
            static function ($backup): bool {
                $scope = trim((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE));
                return $scope === '' || $scope === MigrationBackup::SCOPE_UPGRADE;
            },
        ));
        if (empty($backups)) {
            return;
        }

        $priority = [
            MigrationBackup::TYPE_STRUCTURE => 0,
            MigrationBackup::TYPE_TABLE => 1,
            MigrationBackup::TYPE_COLUMN => 2,
        ];
        usort($backups, static function ($left, $right) use ($priority): int {
            $leftType = (string)$left->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            $rightType = (string)$right->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            $typeOrder = ($priority[$leftType] ?? 99) <=> ($priority[$rightType] ?? 99);
            return $typeOrder !== 0 ? $typeOrder : (int)$left->getId() <=> (int)$right->getId();
        });

        $this->printing->info(__("正在恢复迁移备份 (migration_id: %{1})...", $migrationId));

        foreach ($backups as $backup) {
            if (!$this->backupService->restoreByBackupId((int)$backup->getId())) {
                throw new \RuntimeException(__('恢复迁移备份失败: #%{1}', (string)$backup->getId()));
            }
        }
    }

    /**
     * @param array{
     *     strategy: string,
     *     tables: list<string>,
     *     columns: list<string>,
     *     reason: string,
     *     requires_forward_backup: bool,
     *     forward_backup_types: list<string>
     * } $strategy
     */
    private function performRollbackBackup(
        MigrationInterface $migration,
        int $migrationId,
        string $operationId,
        array $strategy,
    ): void {
        $strategyName = (string)$strategy['strategy'];
        if ($strategyName === 'none') {
            return;
        }

        foreach ($strategy['tables'] as $table) {
            if ($strategyName === 'table') {
                if (!$this->hasRollbackBackup(
                    $migrationId,
                    $table,
                    MigrationBackup::TYPE_STRUCTURE,
                    null,
                    $operationId,
                )) {
                    if (!$this->backupService->backupTableStructure(
                        $table,
                        $migrationId,
                        MigrationBackup::SCOPE_ROLLBACK,
                        $operationId,
                    )) {
                        throw new \RuntimeException(__('回滚前无法备份表 %{1} 的结构', $table));
                    }
                }
                if (!$this->hasRollbackBackup(
                    $migrationId,
                    $table,
                    MigrationBackup::TYPE_TABLE,
                    null,
                    $operationId,
                )) {
                    $this->backupService->backupTableData(
                        $table,
                        $migrationId,
                        MigrationBackup::SCOPE_ROLLBACK,
                        $operationId,
                    );
                }
                continue;
            }

            foreach ($strategy['columns'] as $column) {
                if ($this->hasRollbackBackup(
                    $migrationId,
                    $table,
                    MigrationBackup::TYPE_COLUMN,
                    $column,
                    $operationId,
                )) {
                    continue;
                }
                $this->backupService->backupColumnData(
                    $table,
                    $column,
                    $migrationId,
                    null,
                    null,
                    'ROLLBACK',
                    MigrationBackup::SCOPE_ROLLBACK,
                    $operationId,
                );
            }
        }

        $this->printing->info(__(
            '迁移回滚备份完成 (migration_id: %{1}, operation_id: %{2})',
            [(string)$migrationId, $operationId]
        ));
    }

    private function hasRollbackBackup(
        int $migrationId,
        string $table,
        string $type,
        ?string $column,
        string $operationId,
    ): bool {
        foreach ($this->backupService->getBackupsByMigrationId($migrationId) as $backup) {
            if ((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE) !== MigrationBackup::SCOPE_ROLLBACK
                || (string)$backup->getData(MigrationBackup::schema_fields_OPERATION_ID) !== $operationId
                || (string)$backup->getData(MigrationBackup::schema_fields_TABLE_NAME) !== $table
                || (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE) !== $type) {
                continue;
            }
            $storedColumn = trim((string)$backup->getData(MigrationBackup::schema_fields_COLUMN_NAME));
            if ($column === null || $column === '' || strcasecmp($storedColumn, $column) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Restore data captured immediately before a successful coordinated
     * rollback when the same migration is installed again later.
     *
     * Existing non-empty values and existing rows are never overwritten;
     * BackupService persists a conflict record instead.
     */
    private function restorePreviouslyRolledBackBackups(
        string $moduleName,
        string $migrationFile,
        int $currentMigrationId,
    ): void {
        $records = (clone $this->migrationModel)->reset()
            ->where(Migration::schema_fields_MODULE, $moduleName)
            ->where(Migration::schema_fields_FILE, basename($migrationFile))
            ->where(Migration::schema_fields_STATUS, Migration::STATUS_ROLLED_BACK)
            ->order(Migration::schema_fields_ID, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        foreach ($records as $record) {
            $sourceMigrationId = (int)$record->getId();
            if ($sourceMigrationId <= 0 || $sourceMigrationId === $currentMigrationId) {
                continue;
            }
            $backups = array_values(array_filter(
                $this->backupService->getBackupsByMigrationId($sourceMigrationId),
                static fn($backup): bool =>
                    (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE)
                    === MigrationBackup::SCOPE_ROLLBACK,
            ));
            if ($backups === []) {
                continue;
            }

            $expectedChecksum = trim((string)$record->getData(Migration::schema_fields_CHECKSUM));
            $actualChecksum = strlen($expectedChecksum) === 32
                ? (md5_file($migrationFile) ?: '')
                : (hash_file('sha256', $migrationFile) ?: '');
            if ($expectedChecksum === '' || !hash_equals($expectedChecksum, $actualChecksum)) {
                throw new \RuntimeException(__(
                    '迁移 %{1} 的历史回滚备份对应不同校验和，禁止自动恢复',
                    basename($migrationFile)
                ));
            }

            $conflicts = 0;
            foreach ($backups as $backup) {
                $backupId = (int)$backup->getId();
                $table = (string)$backup->getData(MigrationBackup::schema_fields_TABLE_NAME);
                $type = (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
                $operationId = (string)$backup->getData(MigrationBackup::schema_fields_OPERATION_ID);
                if ($type === MigrationBackup::TYPE_STRUCTURE) {
                    if ($this->connectionFactory->getConnector()->tableExist($table)) {
                        $this->backupService->markBackupRestored($backupId);
                    }
                    continue;
                }
                if ($type === MigrationBackup::TYPE_TABLE) {
                    $result = $this->backupService->restoreTableDataConflictSafe(
                        $table,
                        $sourceMigrationId,
                        MigrationBackup::SCOPE_ROLLBACK,
                        $operationId,
                        $backupId,
                    );
                    $conflicts += $result['conflicts'];
                    continue;
                }
                if ($type === MigrationBackup::TYPE_COLUMN) {
                    $column = trim((string)$backup->getData(MigrationBackup::schema_fields_COLUMN_NAME));
                    if ($column === '') {
                        throw new \RuntimeException(__('回滚列备份 #%{1} 缺少字段名', (string)$backupId));
                    }
                    $result = $this->backupService->restoreColumnDataConflictSafe(
                        $table,
                        $column,
                        $sourceMigrationId,
                        null,
                        null,
                        null,
                        MigrationBackup::SCOPE_ROLLBACK,
                        $operationId,
                        $backupId,
                    );
                    $conflicts += $result['conflicts'];
                }
            }

            if ($conflicts > 0) {
                $this->printing->warning(__(
                    '迁移 %{1} 重新升级后有 %{2} 个备份冲突，已记录且未覆盖现值',
                    [basename($migrationFile), (string)$conflicts]
                ));
            }
            return;
        }
    }

    /**
     * @return array{
     *     strategy: 'none'|'column'|'table',
     *     tables: list<string>,
     *     columns: list<string>,
     *     reason: string,
     *     requires_forward_backup: bool,
     *     forward_backup_types: list<string>
     * }
     */
    private function resolveRollbackBackupStrategy(MigrationInterface $migration): array
    {
        if (!$migration instanceof RollbackBackupStrategyInterface) {
            throw new \RuntimeException(__('迁移未实现 RollbackBackupStrategyInterface'));
        }
        $raw = $migration->getRollbackBackupStrategy();
        $strategy = strtolower(trim((string)($raw['strategy'] ?? '')));
        $tables = $this->normalizeIdentifierList((array)($raw['tables'] ?? []));
        $columns = $this->normalizeIdentifierList((array)($raw['columns'] ?? []));
        $reason = trim((string)($raw['reason'] ?? ''));
        $requiresForwardBackup = (bool)($raw['requires_forward_backup'] ?? false);
        $forwardBackupTypes = $this->normalizeIdentifierList((array)($raw['forward_backup_types'] ?? []));

        if (!in_array($strategy, ['none', 'column', 'table'], true)) {
            throw new \RuntimeException(__('未知回滚备份策略: %{1}', $strategy));
        }
        if ($reason === '') {
            throw new \RuntimeException(__('回滚备份策略必须声明 reason'));
        }
        if ($strategy !== 'none' && $tables === []) {
            throw new \RuntimeException(__('回滚备份策略必须声明至少一个表'));
        }
        if ($strategy === 'column' && $columns === []) {
            throw new \RuntimeException(__('column 回滚备份策略必须声明至少一个字段'));
        }
        $allowedForwardTypes = [
            MigrationBackup::TYPE_STRUCTURE,
            MigrationBackup::TYPE_TABLE,
            MigrationBackup::TYPE_COLUMN,
        ];
        foreach ($forwardBackupTypes as $type) {
            if (!in_array($type, $allowedForwardTypes, true)) {
                throw new \RuntimeException(__('未知正向备份类型: %{1}', $type));
            }
        }
        if ($requiresForwardBackup && $forwardBackupTypes === []) {
            $forwardBackupTypes = [MigrationBackup::TYPE_STRUCTURE, MigrationBackup::TYPE_TABLE];
        }

        sort($tables);
        sort($columns);
        sort($forwardBackupTypes);
        return [
            'strategy' => $strategy,
            'tables' => $tables,
            'columns' => $columns,
            'reason' => $reason,
            'requires_forward_backup' => $requiresForwardBackup,
            'forward_backup_types' => $forwardBackupTypes,
        ];
    }

    /** @param array<string, mixed> $strategy */
    private function assertRequiredForwardBackups(Migration $record, array $strategy): void
    {
        if (empty($strategy['requires_forward_backup'])) {
            return;
        }
        $available = [];
        foreach ($this->backupService->getBackupsByMigrationId((int)$record->getId()) as $backup) {
            $scope = trim((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE));
            if ($scope === '' || $scope === MigrationBackup::SCOPE_UPGRADE) {
                $available[(string)$backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE)] = true;
            }
        }
        foreach ((array)($strategy['forward_backup_types'] ?? []) as $requiredType) {
            if (!isset($available[$requiredType])) {
                throw new \RuntimeException(__('缺少正向 %{1} 备份', $requiredType));
            }
        }
    }

    /** @return list<string> */
    private function normalizeIdentifierList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $result[$value] = true;
            }
        }
        return array_keys($result);
    }

    private function canonicalJson(array $value): string
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = json_decode($this->canonicalJson($item), true);
            }
        }
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * 更新迁移状态
     * 
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件名
     * @param string $status 新状态
     */
    private function updateMigrationStatus(string $moduleName, string $migrationFile, string $status): void
    {
        $items = $this->migrationModel->reset()
            ->where(Migration::schema_fields_MODULE, $moduleName)
            ->where(Migration::schema_fields_FILE, $migrationFile)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $migration = $items[0] ?? null;
        if ($migration && $migration->getId()) {
            $migration->updateStatus($status);
        }
    }

    private function updateMigrationStatusById(int $migrationId, string $status): void
    {
        $migration = clone $this->migrationModel;
        $migration->load($migrationId);
        if (!$migration->getId()) {
            throw new \RuntimeException(__('迁移记录不存在: %{1}', (string)$migrationId));
        }
        $migration->updateStatus($status);
    }

    private function getInstalledMigrationRecord(string $moduleName, string $migrationFile): ?Migration
    {
        $items = $this->migrationModel->reset()
            ->where(Migration::schema_fields_MODULE, $moduleName)
            ->where(Migration::schema_fields_FILE, $migrationFile)
            ->where(Migration::schema_fields_STATUS, Migration::STATUS_INSTALLED)
            ->order(Migration::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();

        $record = $items[0] ?? null;
        return $record instanceof Migration ? $record : null;
    }

    private function assertMigrationChecksum(Migration $record, string $migrationFile): void
    {
        $expected = trim((string)$record->getData(Migration::schema_fields_CHECKSUM));
        if ($expected === '') {
            throw new \RuntimeException(__('迁移记录缺少校验和，禁止自动回滚: %{1}', basename($migrationFile)));
        }

        $actual = strlen($expected) === 32
            ? md5_file($migrationFile)
            : hash_file('sha256', $migrationFile);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new \RuntimeException(__('迁移文件校验和不一致，禁止自动回滚: %{1}', basename($migrationFile)));
        }
    }
    
    
    /**
     * 通过版本获取迁移文件
     * 
     * @param string $moduleName 模块名称
     * @param string $version 版本号
     * @param string $specificFile 指定的迁移文件（可选）
     * @return array
     */
    public function getMigrationsByVersion(string $moduleName, string $version, string $specificFile = ''): array
    {
        $migrations = [];
        foreach ($this->getModuleMigrations($moduleName) as $migration) {
            if ($migration['version'] !== $version) {
                continue;
            }
            if ($specificFile !== '' && $migration['filename'] !== basename($specificFile)) {
                continue;
            }
            $migrations[] = $migration;
        }
        return $migrations;
    }
    
    /**
     * 执行版本迁移升级
     * 
     * @param string $moduleName 模块名称
     * @param string $version 版本号
     * @param string $specificFile 指定的迁移文件（可选）
     * @return bool
     */
    public function upgradeMigrationsByVersion(string $moduleName, string $version, string $specificFile = ''): bool
    {
        $migrations = $this->getMigrationsByVersion($moduleName, $version, $specificFile);
        
        if (empty($migrations)) {
            $this->printing->error(__("未找到版本 %{1} 的迁移文件", $version));
            return false;
        }
        
        $success = true;
        foreach ($migrations as $migration) {
            $this->printing->note(__("执行迁移: %{1}", $migration['filename']));
            $result = $this->upgradeMigration($moduleName, $migration['file']);
            if (!$result) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * 执行版本迁移回滚
     * 
     * @param string $moduleName 模块名称
     * @param string $version 版本号
     * @param string $specificFile 指定的迁移文件（可选）
     * @return bool
     */
    public function rollbackMigrationsByVersion(string $moduleName, string $version, string $specificFile = ''): bool
    {
        $migrations = $this->getMigrationsByVersion($moduleName, $version, $specificFile);
        
        if (empty($migrations)) {
            $this->printing->error(__("未找到版本 %{1} 的迁移文件", $version));
            return false;
        }
        
        $success = true;
        // 按相反顺序执行回滚
        $migrations = array_reverse($migrations);
        foreach ($migrations as $migration) {
            $this->printing->note(__("回滚迁移: %{1}", $migration['filename']));
            $result = $this->rollbackMigration($moduleName, $migration['file']);
            if (!$result) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * 执行版本迁移卸载
     * 
     * @param string $moduleName 模块名称
     * @param string $version 版本号
     * @param string $specificFile 指定的迁移文件（可选）
     * @return bool
     */
    public function uninstallMigrationsByVersion(string $moduleName, string $version, string $specificFile = ''): bool
    {
        $migrations = $this->getMigrationsByVersion($moduleName, $version, $specificFile);
        
        if (empty($migrations)) {
            $this->printing->error(__("未找到版本 %{1} 的迁移文件", $version));
            return false;
        }
        
        $success = true;
        // 按相反顺序执行卸载
        $migrations = array_reverse($migrations);
        foreach ($migrations as $migration) {
            $this->printing->note(__("卸载迁移: %{1}", $migration['filename']));
            $result = $this->uninstallMigration($moduleName, $migration['file']);
            if (!$result) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * 获取迁移路径
     * 
     * @param string $moduleName 模块名称
     * @return string
     */
    private function getMigrationPath(string $moduleName): string
    {
        // 获取模块信息
        $moduleInfo = \Weline\Framework\App\Env::getInstance()->getModuleInfo($moduleName);
        
        if ($moduleInfo && isset($moduleInfo['base_path'])) {
            // 使用模块的基础路径
            $basePath = (string)$moduleInfo['base_path'];
            if (!str_starts_with($basePath, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $basePath)) {
                $basePath = BP . ltrim($basePath, '/\\\\');
            }
            return rtrim($basePath, '/\\\\') . DS . 'Setup' . DS . 'Db' . DS . 'Migration' . DS;
        }
        
        // 如果没有找到模块信息，尝试默认路径
        // 解析模块名称
        $parts = explode('_', $moduleName);
        if (count($parts) < 2) {
            return '';
        }
        
        $vendor = $parts[0];
        $module = $parts[1];
        
        // 尝试 app/code 路径
        $appPath = BP . "app/code/{$vendor}/{$module}/Setup/Db/Migration/";
        if (is_dir($appPath)) {
            return $appPath;
        }
        
        // 尝试 vendor 路径
        $vendorPath = BP . "vendor/{$vendor}/{$module}/Setup/Db/Migration/";
        if (is_dir($vendorPath)) {
            return $vendorPath;
        }
        
        // 默认返回 app/code 路径
        return $appPath;
    }
    
    /**
     * 获取迁移类名
     * 
     * @param string $filename 文件名
     * @return string
     */
    private function getMigrationClassName(string $filename): string
    {
        $basename = basename($filename);
        $className = str_replace('.php', '', $basename);
        $className = str_replace(['_', '-', '.'], ' ', $className);
        $className = ucwords($className);
        $className = str_replace(' ', '', $className);

        return $className;
    }

    /**
     * 迁移文件在命名空间 Weline\X\Setup\Db\Migration 下时，class_exists(短类名) 为 false，由此解析 FQCN。
     */
    private function readMigrationClassName(string $migrationFile): ?string
    {
        $source = file_get_contents($migrationFile);
        if (!is_string($source)) {
            return null;
        }
        $tokens = token_get_all($source);
        $namespace = '';
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($index++; $index < $count; $index++) {
                    $part = $tokens[$index];
                    if ($part === ';' || $part === '{') {
                        break;
                    }
                    if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $part[1];
                    }
                }
                continue;
            }
            if ($token[0] !== T_CLASS) {
                continue;
            }
            for ($index++; $index < $count; $index++) {
                $nameToken = $tokens[$index];
                if (is_array($nameToken) && $nameToken[0] === T_STRING) {
                    return ltrim($namespace . '\\' . $nameToken[1], '\\');
                }
            }
        }
        return null;
    }
    
    /**
     * 跨版本回滚：从当前版本回滚到目标版本
     * 
     * @param string $moduleName 模块名称
     * @param string $targetVersion 目标版本
     * @param bool $dryRun 预演模式
     * @return array 回滚结果
     */
    public function rollbackToVersion(string $moduleName, string $targetVersion, bool $dryRun = false): array
    {
        $result = [
            'success' => false,
            'current_version' => null,
            'target_version' => $targetVersion,
            'rolled_back_migrations' => [],
            'errors' => [],
        ];
        
        try {
            $currentVersion = $this->versionService->getModuleVersionString($moduleName);
            $result['current_version'] = $currentVersion;
            
            if (!$currentVersion) {
                $result['errors'][] = __('模块 %{1} 当前版本不存在', $moduleName);
                return $result;
            }
            
            if (version_compare($currentVersion, $targetVersion, '<=')) {
                $result['errors'][] = __('当前版本 %{1} 不高于目标版本 %{2}', [$currentVersion, $targetVersion]);
                return $result;
            }
            
            $scriptPlan = $this->planRollbackToVersion($moduleName, $targetVersion, $currentVersion);
            $migrationsToRollback = $scriptPlan['migrations'];
            if ($scriptPlan['blockers'] !== []) {
                $result['errors'] = array_merge($result['errors'], $scriptPlan['blockers']);
                return $result;
            }
            
            if (empty($migrationsToRollback)) {
                $this->printing->info(__('没有需要回滚的迁移'));
                $result['success'] = true;
                return $result;
            }
            
            if ($dryRun) {
                $this->printing->note(__('预演模式 - 以下迁移将被回滚:'));
                foreach ($migrationsToRollback as $migration) {
                    $this->printing->info("  - " . $migration['filename']);
                    $result['rolled_back_migrations'][] = $migration['filename'];
                }
                $result['success'] = true;
                return $result;
            }
            
            $result['errors'][] = __(
                '已禁止独立数据库回滚；请通过 ModuleRollbackManagerInterface 创建代码与数据库联动回滚任务'
            );
            
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            $this->printing->error(__('跨版本回滚失败: %{1}', $e->getMessage()));
        }
        
        return $result;
    }

    /**
     * Build a checksum-verified reverse script chain for a semantic version range.
     *
     * @return array{migrations: list<array<string, mixed>>, blockers: list<string>}
     */
    public function planRollbackToVersion(string $moduleName, string $targetVersion, string $currentVersion): array
    {
        $blockers = [];
        $migrations = $this->getMigrationsBetweenVersions($moduleName, $targetVersion, $currentVersion);
        foreach ($migrations as &$migration) {
            $file = (string)$migration['file'];
            $record = $this->getInstalledMigrationRecord($moduleName, (string)$migration['filename']);
            if ($record === null) {
                $blockers[] = __('迁移缺少 installed 记录: %{1}', $migration['filename']);
                continue;
            }
            if (!is_file($file)) {
                $blockers[] = __('迁移文件缺失: %{1}', $migration['filename']);
                continue;
            }
            try {
                $this->assertMigrationChecksum($record, $file);
            } catch (\Throwable $e) {
                $blockers[] = $e->getMessage();
                continue;
            }
            try {
                $migrationInstance = $this->loadMigrationClass($file);
                $rollbackBackupStrategy = $this->resolveRollbackBackupStrategy($migrationInstance);
                $this->assertRequiredForwardBackups($record, $rollbackBackupStrategy);
            } catch (\Throwable $e) {
                $blockers[] = __('迁移 %{1} 无法自动回滚：%{2}', [$migration['filename'], $e->getMessage()]);
                continue;
            }
            $migration['migration_id'] = (int)$record->getId();
            $migration['checksum'] = (string)$record->getData(Migration::schema_fields_CHECKSUM);
            $migration['rollback_backup_strategy'] = $rollbackBackupStrategy;
            $dependencies = json_decode((string)$record->getData(Migration::schema_fields_DEPENDENCIES), true);
            $migration['dependencies'] = is_array($dependencies) ? $dependencies : [];
        }
        unset($migration);

        $installedDependencies = [];
        foreach ($this->migrationModel->getInstalledMigrations($moduleName) as $installedMigration) {
            $migrationType = (string)$installedMigration->getData(Migration::schema_fields_MIGRATION_TYPE);
            if ($migrationType !== '' && $migrationType !== 'script') {
                continue;
            }
            $filename = basename((string)$installedMigration->getData(Migration::schema_fields_FILE));
            if ($filename === '') {
                continue;
            }
            $dependencies = json_decode(
                (string)$installedMigration->getData(Migration::schema_fields_DEPENDENCIES),
                true
            );
            $installedDependencies[$filename] = array_values(array_unique(array_map(
                static fn(mixed $dependency): string => basename(trim((string)$dependency)),
                is_array($dependencies) ? $dependencies : []
            )));
        }

        $dependencyPlan = $this->sortRollbackDependencyGraph($migrations, $installedDependencies);
        $migrations = $dependencyPlan['migrations'];
        $blockers = array_merge($blockers, $dependencyPlan['blockers']);

        return ['migrations' => $migrations, 'blockers' => array_values(array_unique($blockers))];
    }

    /**
     * Dependencies point from a migration to the migration it requires. During
     * rollback that edge is executed in the same direction: dependent first,
     * dependency second.
     *
     * @param list<array<string, mixed>> $migrations
     * @param array<string, list<string>> $installedDependencies
     * @return array{migrations: list<array<string, mixed>>, blockers: list<string>}
     */
    private function sortRollbackDependencyGraph(array $migrations, array $installedDependencies): array
    {
        $selected = [];
        foreach ($migrations as $migration) {
            $filename = basename((string)($migration['filename'] ?? ''));
            if ($filename !== '') {
                $selected[$filename] = $migration;
            }
        }

        $blockers = [];
        $adjacency = array_fill_keys(array_keys($selected), []);
        $inDegree = array_fill_keys(array_keys($selected), 0);
        foreach ($installedDependencies as $filename => $dependencies) {
            foreach ($dependencies as $dependency) {
                $dependency = basename(trim((string)$dependency));
                if ($dependency === '') {
                    continue;
                }
                if (isset($selected[$filename]) && !array_key_exists($dependency, $installedDependencies)) {
                    $blockers[] = __('迁移 %{1} 的已安装依赖缺失: %{2}', [$filename, $dependency]);
                    continue;
                }
                if (!isset($selected[$dependency])) {
                    continue;
                }
                if (!isset($selected[$filename])) {
                    $blockers[] = __(
                        '目标范围外的已安装迁移 %{1} 仍依赖待回滚迁移 %{2}',
                        [$filename, $dependency]
                    );
                    continue;
                }
                $adjacency[$filename][] = $dependency;
                $inDegree[$dependency]++;
            }
        }

        $compare = static function (string $left, string $right) use ($selected): int {
            $version = version_compare(
                (string)($selected[$right]['version'] ?? ''),
                (string)($selected[$left]['version'] ?? '')
            );
            return $version !== 0 ? $version : strcmp($right, $left);
        };
        $queue = array_keys(array_filter($inDegree, static fn(int $degree): bool => $degree === 0));
        usort($queue, $compare);
        $ordered = [];
        while ($queue !== []) {
            $filename = array_shift($queue);
            $ordered[] = $selected[$filename];
            foreach (array_values(array_unique($adjacency[$filename])) as $dependency) {
                $inDegree[$dependency]--;
                if ($inDegree[$dependency] === 0) {
                    $queue[] = $dependency;
                    usort($queue, $compare);
                }
            }
        }

        if (count($ordered) !== count($selected)) {
            $blockers[] = __('待回滚迁移依赖图存在循环，无法生成安全反向顺序');
            $ordered = array_values($selected);
            usort($ordered, static function (array $left, array $right): int {
                $version = version_compare((string)$right['version'], (string)$left['version']);
                return $version !== 0
                    ? $version
                    : strcmp((string)$right['filename'], (string)$left['filename']);
            });
        }

        return ['migrations' => $ordered, 'blockers' => array_values(array_unique($blockers))];
    }

    /** @param list<array<string, mixed>> $migrations */
    public function executeRollbackPlan(string $moduleName, array $migrations, string $operationId = ''): array
    {
        if ($operationId === '') {
            throw new \InvalidArgumentException(__('联动回滚任务缺少 operation_id'));
        }
        $completed = [];
        foreach ($migrations as $migration) {
            $file = (string)($migration['file'] ?? '');
            $migrationId = (int)($migration['migration_id'] ?? 0);
            if ($migrationId > 0 && $operationId !== '') {
                $record = clone $this->migrationModel;
                $record->load($migrationId);
                if (!$record->getId() || $record->getData(Migration::schema_fields_STATUS) !== Migration::STATUS_INSTALLED) {
                    throw new \RuntimeException(__('迁移记录状态已变化: #%{1}', (string)$migrationId));
                }
                $record->setData(Migration::schema_fields_OPERATION_ID, $operationId)->save();
            }
            if (!$this->rollbackMigration(
                $moduleName,
                $file,
                $operationId,
                (array)($migration['rollback_backup_strategy'] ?? []),
            )) {
                throw new \RuntimeException(__('回滚迁移 %{1} 失败', (string)($migration['filename'] ?? basename($file))));
            }
            $completed[] = $migration;
        }
        return $completed;
    }

    /** @param list<array<string, mixed>> $migrations */
    public function compensateRollbackPlan(string $moduleName, array $migrations): void
    {
        foreach (array_reverse($migrations) as $migration) {
            $file = (string)($migration['file'] ?? '');
            if (!$this->upgradeMigration($moduleName, $file)) {
                throw new \RuntimeException(__('正向补偿迁移 %{1} 失败', (string)($migration['filename'] ?? basename($file))));
            }
        }
    }
    
    /**
     * 回滚最近 N 个迁移
     * 
     * @param string $moduleName 模块名称
     * @param int $steps 回滚步数
     * @param bool $dryRun 预演模式
     * @return array 回滚结果
     */
    public function rollbackSteps(string $moduleName, int $steps, bool $dryRun = false): array
    {
        $result = [
            'success' => false,
            'steps' => $steps,
            'rolled_back_migrations' => [],
            'errors' => [],
        ];
        
        try {
            // 获取最近已安装的迁移
            $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
            
            if (empty($installedMigrations)) {
                $result['errors'][] = __('模块 %{1} 没有已安装的迁移', $moduleName);
                return $result;
            }
            
            // 按执行时间倒序排列，取最近 N 个
            usort($installedMigrations, function($a, $b) {
                return strtotime($b['executed_at'] ?? '0') - strtotime($a['executed_at'] ?? '0');
            });
            
            $migrationsToRollback = array_slice($installedMigrations, 0, $steps);
            
            if ($dryRun) {
                $this->printing->note(__('预演模式 - 以下迁移将被回滚:'));
                foreach ($migrationsToRollback as $migration) {
                    $filename = $migration['migration_file'] ?? $migration->getData('migration_file');
                    $this->printing->info("  - " . $filename);
                    $result['rolled_back_migrations'][] = $filename;
                }
                $result['success'] = true;
                return $result;
            }
            
            // 执行回滚
            $migrationPath = $this->getMigrationPath($moduleName);
            
            foreach ($migrationsToRollback as $migration) {
                $filename = $migration['migration_file'] ?? $migration->getData('migration_file');
                $file = $migrationPath . $filename;
                
                // 检查反向依赖
                if (!$this->checkReverseDependencies($moduleName, $filename)) {
                    $result['errors'][] = __('迁移 %{1} 存在反向依赖，无法回滚', $filename);
                    return $result;
                }
                
                $this->printing->note(__('回滚迁移: %{1}', $filename));
                
                if (!$this->rollbackMigration($moduleName, $file)) {
                    $result['errors'][] = __('回滚迁移 %{1} 失败', $filename);
                    return $result;
                }
                
                $result['rolled_back_migrations'][] = $filename;
            }
            
            $result['success'] = true;
            $this->printing->success(__('成功回滚 %{1} 个迁移', count($result['rolled_back_migrations'])));
            
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            $this->printing->error(__('回滚失败: %{1}', $e->getMessage()));
        }
        
        return $result;
    }
    
    /**
     * 检查反向依赖
     * 检查是否有其他已安装的迁移依赖于当前迁移
     * 
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件名
     * @return bool 无反向依赖返回 true
     */
    public function checkReverseDependencies(string $moduleName, string $migrationFile): bool
    {
        $currentBasename = basename($migrationFile);
        $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
        
        foreach ($installedMigrations as $migration) {
            $filename = $migration['migration_file'] ?? $migration->getData('migration_file');
            
            // 跳过自身
            if ($filename === $currentBasename) {
                continue;
            }
            
            // 跳过已回滚的迁移
            $status = $migration['status'] ?? $migration->getData('status');
            if ($status === Migration::STATUS_ROLLED_BACK) {
                continue;
            }
            
            // 获取依赖列表
            $deps = $migration['dependencies'] ?? $migration->getData('dependencies');
            if (is_string($deps)) {
                $deps = json_decode($deps, true) ?? [];
            }
            
            if (in_array($currentBasename, $deps)) {
                $this->printing->warning(__('迁移 %{1} 依赖于 %{2}，请先回滚依赖方', [$filename, $currentBasename]));
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 获取两个版本之间的迁移
     * 
     * @param string $moduleName 模块名称
     * @param string $fromVersion 起始版本（不包含）
     * @param string $toVersion 结束版本（包含）
     * @return array
     */
    private function getMigrationsBetweenVersions(string $moduleName, string $fromVersion, string $toVersion): array
    {
        $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
        $migrationPath = $this->getMigrationPath($moduleName);
        
        $result = [];
        
        foreach ($installedMigrations as $migration) {
            $version = $migration['version'] ?? $migration->getData('version');
            $filename = $migration['migration_file'] ?? $migration->getData('migration_file');
            $status = $migration['status'] ?? $migration->getData('status');
            $migrationType = $migration['migration_type'] ?? $migration->getData(Migration::schema_fields_MIGRATION_TYPE);
            
            // 跳过已回滚的迁移
            if ($status === Migration::STATUS_ROLLED_BACK) {
                continue;
            }
            if ($migrationType !== '' && $migrationType !== 'script') {
                continue;
            }
            
            // 版本大于 fromVersion 且小于等于 toVersion
            if (version_compare($version, $fromVersion, '>') && version_compare($version, $toVersion, '<=')) {
                $result[] = [
                    'file' => $migrationPath . $filename,
                    'filename' => $filename,
                    'version' => $version,
                ];
            }
        }
        
        // 按版本排序
        usort($result, function($a, $b) {
            return version_compare($a['version'], $b['version']);
        });
        
        return $result;
    }
}
