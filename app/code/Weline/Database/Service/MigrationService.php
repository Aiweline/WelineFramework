<?php
/**
 * 数据库迁移服务
 * 
 * @author WelineFramework
 * @package Weline\Database\Service
 */

namespace Weline\Database\Service;

use Weline\Database\Interface\MigrationInterface;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\VersionService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Output\Cli\Printing;

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
            // 检查迁移文件是否存在
            if (!file_exists($migrationFile)) {
                throw new \Exception("迁移文件不存在: {$migrationFile}");
            }
            
            // 加载迁移类
            $migrationClass = $this->loadMigrationClass($migrationFile);
            if (!$migrationClass instanceof MigrationInterface) {
                throw new \Exception("迁移类必须实现MigrationInterface接口");
            }
            
            // 验证前置条件
            if (!$migrationClass->validate()) {
                throw new \Exception("迁移前置条件验证失败");
            }
            
            // 检查依赖
            $dependencies = $migrationClass->getDependencies();
            if (!$this->checkDependencies($moduleName, $dependencies)) {
                throw new \Exception("迁移依赖未满足");
            }
            
            // 开始事务
            $query = $this->connectionFactory->query('SELECT 1');
            $query->beginTransaction();
            
            try {
                // 执行迁移
                $result = $migrationClass->install();
                
                if (!$result) {
                    throw new \Exception("迁移执行失败");
                }
                
                // 记录迁移
                $this->recordMigration($moduleName, $migrationFile, $migrationClass);
                
                $query->commit();
                
                $this->printing->success("迁移升级成功: {$migrationFile}");
                return true;
                
            } catch (\Exception $e) {
                $query->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->printing->error("迁移升级失败: " . $e->getMessage());
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
            // 检查迁移是否已安装
            if (!$this->migrationModel->isMigrationExists($moduleName, basename($migrationFile))) {
                throw new \Exception("迁移记录不存在: {$migrationFile}");
            }
            
            // 加载迁移类
            $migrationClass = $this->loadMigrationClass($migrationFile);
            if (!$migrationClass instanceof MigrationInterface) {
                throw new \Exception("迁移类必须实现MigrationInterface接口");
            }
            
            // 开始事务
            $query = $this->connectionFactory->query('SELECT 1');
            $query->beginTransaction();
            
            try {
                // 执行回滚
                $result = $migrationClass->uninstall();
                
                if (!$result) {
                    throw new \Exception("迁移回滚失败");
                }
                
                // 更新迁移状态
                $this->updateMigrationStatus($moduleName, basename($migrationFile), Migration::STATUS_ROLLED_BACK);
                
                $query->commit();
                
                $this->printing->success("迁移回滚成功: {$migrationFile}");
                return true;
                
            } catch (\Exception $e) {
                $query->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->printing->error("迁移回滚失败: " . $e->getMessage());
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
            // 检查迁移是否已安装
            if (!$this->migrationModel->isMigrationExists($moduleName, basename($migrationFile))) {
                throw new \Exception("迁移记录不存在: {$migrationFile}");
            }
            
            // 加载迁移类
            $migrationClass = $this->loadMigrationClass($migrationFile);
            if (!$migrationClass instanceof MigrationInterface) {
                throw new \Exception("迁移类必须实现MigrationInterface接口");
            }
            
            // 开始事务
            $query = $this->connectionFactory->query('SELECT 1');
            $query->beginTransaction();
            
            try {
                // 执行卸载
                $result = $migrationClass->uninstall();
                
                if (!$result) {
                    throw new \Exception("迁移卸载失败");
                }
                
                // 删除迁移记录
                $this->migrationModel->deleteMigration($moduleName, basename($migrationFile));
                
                $query->commit();
                
                $this->printing->success("迁移卸载成功: {$migrationFile}");
                return true;
                
            } catch (\Exception $e) {
                $query->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->printing->error("迁移卸载失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取模块的所有迁移文件
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getModuleMigrations(string $moduleName): array
    {
        $migrationPath = "app/code/{$moduleName}/Setup/Db/Migration/";
        
        if (!is_dir($migrationPath)) {
            return [];
        }
        
        $files = glob($migrationPath . "*.php");
        $migrations = [];
        
        foreach ($files as $file) {
            $migrations[] = [
                'file' => $file,
                'filename' => basename($file),
                'class' => $this->getMigrationClassName($file)
            ];
        }
        
        // 按文件名排序
        usort($migrations, function($a, $b) {
            return strcmp($a['filename'], $b['filename']);
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
        $allMigrations = $this->getModuleMigrations($moduleName);
        $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
        
        $installedFiles = array_column($installedMigrations, 'migration_file');
        
        $pending = [];
        foreach ($allMigrations as $migration) {
            if (!in_array($migration['filename'], $installedFiles)) {
                $pending[] = $migration;
            }
        }
        
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
        $className = $this->getMigrationClassName($migrationFile);
        
        if (!class_exists($className)) {
            require_once $migrationFile;
        }
        
        if (!class_exists($className)) {
            throw new \Exception("迁移类不存在: {$className}");
        }
        
        return new $className();
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
        
        $installedMigrations = $this->migrationModel->getInstalledMigrations($moduleName);
        $installedFiles = array_column($installedMigrations, 'migration_file');
        
        foreach ($dependencies as $dependency) {
            if (!in_array($dependency, $installedFiles)) {
                $this->printing->error("依赖迁移未安装: {$dependency}");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 记录迁移执行
     * 
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件路径
     * @param MigrationInterface $migrationClass 迁移类实例
     */
    private function recordMigration(string $moduleName, string $migrationFile, MigrationInterface $migrationClass): void
    {
        $info = $migrationClass->getInfo();
        
        $data = [
            'module_name' => $moduleName,
            'version' => $migrationClass->getVersion(),
            'migration_file' => basename($migrationFile),
            'description' => $migrationClass->getDescription(),
            'status' => Migration::STATUS_INSTALLED,
            'dependencies' => $migrationClass->getDependencies(),
            'checksum' => md5_file($migrationFile),
            'executed_at' => date('Y-m-d H:i:s')
        ];
        
        $this->migrationModel->recordMigration($data);
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
        $collection = $this->migrationModel->getCollection();
        $collection->addFieldToFilter(Migration::fields_MODULE, $moduleName);
        $collection->addFieldToFilter(Migration::fields_FILE, $migrationFile);
        
        $migration = $collection->getFirstItem();
        if ($migration->getId()) {
            $migration->updateStatus($status);
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
        $migrationPath = $this->getMigrationPath($moduleName);
        $versionPath = $migrationPath . $version . '/';
        
        if (!is_dir($versionPath)) {
            return [];
        }
        
        $files = glob($versionPath . "*.php");
        $migrations = [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // 如果指定了特定文件，只返回该文件
            if (!empty($specificFile)) {
                if ($filename === $specificFile) {
                    return [[
                        'file' => $file,
                        'filename' => $filename,
                        'class' => $this->getMigrationClassName($filename)
                    ]];
                }
            } else {
                $migrations[] = [
                    'file' => $file,
                    'filename' => $filename,
                    'class' => $this->getMigrationClassName($filename)
                ];
            }
        }
        
        // 按文件名排序
        usort($migrations, function($a, $b) {
            return strcmp($a['filename'], $b['filename']);
        });
        
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
            $result = $this->upgradeMigration($moduleName, $migration['filename']);
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
            $result = $this->rollbackMigration($moduleName, $migration['filename']);
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
            $result = $this->uninstallMigration($moduleName, $migration['filename']);
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
            return $moduleInfo['base_path'] . 'Setup/Db/Migration/';
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
        $appPath = "app/code/{$vendor}/{$module}/Setup/Db/Migration/";
        if (is_dir($appPath)) {
            return $appPath;
        }
        
        // 尝试 vendor 路径
        $vendorPath = "vendor/{$vendor}/{$module}/Setup/Db/Migration/";
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
        $className = str_replace('.php', '', $filename);
        $className = str_replace('_', '', $className);
        $className = ucwords($className, '_');
        
        return $className;
    }
}
