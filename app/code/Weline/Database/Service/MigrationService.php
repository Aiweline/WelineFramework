<?php
/**
 * 数据库迁移服务
 * 
 * @author WelineFramework
 * @package Weline\Database\Service
 */

namespace Weline\Database\Service;

use Weline\Framework\Database\Migration\MigrationInterface;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\VersionService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Registry\Service\RegistryProgress;

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
    public function rollbackMigration(string $moduleName, string $migrationFile): bool
    {
        try {
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
                
                $migrationId = (int)$record->getId();
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

        $instance = ObjectManager::make($className);
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

        if ($strategy['strategy'] === 'none' || empty($strategy['tables'])) {
            return;
        }

        $tables  = $strategy['tables'] ?? [];
        $columns = $strategy['columns'] ?? [];

        foreach ($tables as $table) {
            if ($strategy['strategy'] === 'column' && !empty($columns)) {
                foreach ($columns as $column) {
                    $this->backupService->backupColumnData($table, $column, $migrationId);
                }
            } else {
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
        $backups = $this->backupService->getBackupsByMigrationId($migrationId);
        if (empty($backups)) {
            return;
        }

        $this->printing->info(__("正在恢复迁移备份 (migration_id: %{1})...", $migrationId));

        foreach ($backups as $backup) {
            $this->backupService->restoreByBackupId((int) $backup->getId());
        }
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
            $migration['migration_id'] = (int)$record->getId();
            $migration['checksum'] = (string)$record->getData(Migration::schema_fields_CHECKSUM);
            $dependencies = json_decode((string)$record->getData(Migration::schema_fields_DEPENDENCIES), true);
            $migration['dependencies'] = is_array($dependencies) ? $dependencies : [];
        }
        unset($migration);

        usort($migrations, static function (array $left, array $right): int {
            $version = version_compare((string)$right['version'], (string)$left['version']);
            return $version !== 0 ? $version : strcmp((string)$right['filename'], (string)$left['filename']);
        });

        return ['migrations' => $migrations, 'blockers' => array_values(array_unique($blockers))];
    }

    /** @param list<array<string, mixed>> $migrations */
    public function executeRollbackPlan(string $moduleName, array $migrations): array
    {
        $completed = [];
        foreach ($migrations as $migration) {
            $file = (string)($migration['file'] ?? '');
            if (!$this->rollbackMigration($moduleName, $file)) {
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
