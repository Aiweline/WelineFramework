# Weline_Database 企业级数据库迁移系统功能规范

## 项目概述

### 功能描述
开发一个企业级的数据库迁移管理系统，用于管理和执行框架下每个模块的数据库迁移脚本。系统支持版本控制、数据备份、安全回滚，确保即使在删除表结构的情况下也能完整恢复数据和表结构。

### 核心目标
- **企业级稳定性**: 提供高可用、高稳定的迁移管理
- **数据安全保障**: 防止数据丢失，支持完整回滚
- **版本控制**: 基于模块版本号的迁移文件管理
- **自动化集成**: 与框架升级流程无缝集成
- **语义化命名**: 清晰的文件命名规范

## 系统架构

### 1. 模块结构
```
app/code/Weline/Database/
├── Console/
│   └── Db/
│       └── Migrate/                    # 迁移命令目录
│           ├── Install.php             # 安装迁移命令
│           ├── Uninstall.php           # 卸载迁移命令
│           ├── Status.php              # 迁移状态查询
│           ├── Backup.php              # 数据备份命令
│           └── Restore.php             # 数据恢复命令
├── MigrationInterface.php              # 迁移接口定义
├── Model/
│   ├── Migration.php                   # 迁移记录模型
│   ├── MigrationBackup.php            # 备份记录模型
│   └── ModuleVersion.php              # 模块版本模型
├── Service/
│   ├── MigrationService.php            # 迁移服务
│   ├── BackupService.php               # 备份服务
│   └── VersionService.php              # 版本管理服务
├── Observer/
│   ├── ModuleUpgradeObserver.php      # 模块升级事件监听
│   └── SetupUpgradeObserver.php       # 系统升级事件监听
└── Helper/
    ├── MigrationHelper.php             # 迁移助手
    └── BackupHelper.php                # 备份助手
```

### 2. 迁移文件结构
```
app/code/{Module}/Setup/Db/Migration/
├── {action}__{description}_{date}-{version}.php
├── create_table__users_20250101-v1.0.0.php
├── drop_column__raw_data_20250102-v1.0.1.php
├── add_index__user_email_20250103-v1.0.2.php
└── modify_table__orders_20250104-v1.1.0.php
```

## 核心组件设计

### 1. MigrationInterface 接口

```php
<?php
declare(strict_types=1);

namespace Weline\Database;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Data\Context;

/**
 * 数据库迁移接口
 * 所有迁移脚本必须实现此接口
 */
interface MigrationInterface
{
    /**
     * 执行迁移
     * 
     * @param ConnectionFactory $connectionFactory 数据库连接工厂
     * @param Context $context 上下文信息
     * @return bool 迁移是否成功
     */
    public function up(ConnectionFactory $connectionFactory, Context $context): bool;

    /**
     * 回滚迁移
     * 
     * @param ConnectionFactory $connectionFactory 数据库连接工厂
     * @param Context $context 上下文信息
     * @return bool 回滚是否成功
     */
    public function down(ConnectionFactory $connectionFactory, Context $context): bool;

    /**
     * 获取迁移描述
     * 
     * @return string 迁移描述
     */
    public function getDescription(): string;

    /**
     * 获取迁移版本
     * 
     * @return string 版本号
     */
    public function getVersion(): string;

    /**
     * 获取迁移日期
     * 
     * @return string 日期 (YYYYMMDD)
     */
    public function getDate(): string;

    /**
     * 获取迁移类型
     * 
     * @return string 迁移类型 (create_table, drop_table, add_column, drop_column, modify_column, add_index, drop_index)
     */
    public function getType(): string;

    /**
     * 获取影响的数据表
     * 
     * @return array 表名数组
     */
    public function getAffectedTables(): array;

    /**
     * 是否需要备份
     * 
     * @return bool 是否需要备份
     */
    public function requiresBackup(): bool;

    /**
     * 获取备份策略
     * 
     * @return array 备份配置
     */
    public function getBackupStrategy(): array;
}
```

### 2. 迁移文件命名规范

#### 命名格式
```
{action}__{description}_{date}-{version}.php
```

#### 命名规则
- **action**: 迁移操作类型
  - `create_table`: 创建表
  - `drop_table`: 删除表
  - `add_column`: 添加字段
  - `drop_column`: 删除字段
  - `modify_column`: 修改字段
  - `add_index`: 添加索引
  - `drop_index`: 删除索引
  - `modify_table`: 修改表结构
  - `data_migration`: 数据迁移

- **description**: 迁移描述（使用下划线分隔）
- **date**: 创建日期 (YYYYMMDD)
- **version**: 模块版本号

#### 示例
```php
// 创建用户表
create_table__users_20250101-v1.0.0.php

// 删除原始数据字段
drop_column__raw_data_20250102-v1.0.1.php

// 添加用户邮箱索引
add_index__user_email_20250103-v1.0.2.php

// 修改订单表结构
modify_table__orders_20250104-v1.1.0.php

// 数据迁移
data_migration__user_status_20250105-v1.1.1.php
```

### 3. 迁移脚本模板

```php
<?php
declare(strict_types=1);

namespace {Module}\Setup\Db\Migration;

use Weline\Database\MigrationInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Data\Context;

/**
 * {迁移描述}
 * 
 * @package {Module}\Setup\Db\Migration
 * @author 秋枫雁飞
 * @email aiweline@qq.com
 * @date {YYYY-MM-DD}
 * @version {version}
 */
class {ClassName} implements MigrationInterface
{
    public function up(ConnectionFactory $connectionFactory, Context $context): bool
    {
        try {
            $connection = $connectionFactory->create();
            
            // 执行迁移逻辑
            $sql = "CREATE TABLE users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            
            $connection->query($sql)->fetch();
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception("迁移执行失败: " . $e->getMessage());
        }
    }

    public function down(ConnectionFactory $connectionFactory, Context $context): bool
    {
        try {
            $connection = $connectionFactory->create();
            
            // 执行回滚逻辑
            $sql = "DROP TABLE IF EXISTS users";
            $connection->query($sql)->fetch();
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception("迁移回滚失败: " . $e->getMessage());
        }
    }

    public function getDescription(): string
    {
        return "创建用户表";
    }

    public function getVersion(): string
    {
        return "1.0.0";
    }

    public function getDate(): string
    {
        return "20250101";
    }

    public function getType(): string
    {
        return "create_table";
    }

    public function getAffectedTables(): array
    {
        return ["users"];
    }

    public function requiresBackup(): bool
    {
        return false; // 创建表不需要备份
    }

    public function getBackupStrategy(): array
    {
        return [
            'tables' => [],
            'strategy' => 'none'
        ];
    }
}
```

## 数据库设计

### 1. 迁移记录表 (migrations)

```sql
CREATE TABLE migrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    module_name VARCHAR(100) NOT NULL COMMENT '模块名称',
    migration_file VARCHAR(255) NOT NULL COMMENT '迁移文件名',
    migration_class VARCHAR(255) NOT NULL COMMENT '迁移类名',
    version VARCHAR(20) NOT NULL COMMENT '版本号',
    date VARCHAR(8) NOT NULL COMMENT '迁移日期',
    type VARCHAR(50) NOT NULL COMMENT '迁移类型',
    description TEXT COMMENT '迁移描述',
    affected_tables JSON COMMENT '影响的表',
    status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') DEFAULT 'pending' COMMENT '状态',
    executed_at TIMESTAMP NULL COMMENT '执行时间',
    rolled_back_at TIMESTAMP NULL COMMENT '回滚时间',
    error_message TEXT COMMENT '错误信息',
    backup_id INT NULL COMMENT '备份ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_module_version (module_name, version),
    INDEX idx_status (status),
    INDEX idx_executed_at (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移记录表';
```

### 2. 备份记录表 (migration_backups)

```sql
CREATE TABLE migration_backups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    migration_id INT NOT NULL COMMENT '迁移ID',
    backup_type ENUM('full', 'table', 'column', 'data') NOT NULL COMMENT '备份类型',
    backup_name VARCHAR(255) NOT NULL COMMENT '备份名称',
    backup_path VARCHAR(500) NOT NULL COMMENT '备份路径',
    backup_size BIGINT DEFAULT 0 COMMENT '备份大小',
    tables JSON COMMENT '备份的表',
    columns JSON COMMENT '备份的字段',
    data_count INT DEFAULT 0 COMMENT '数据条数',
    status ENUM('created', 'completed', 'failed', 'restored') DEFAULT 'created' COMMENT '状态',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    restored_at TIMESTAMP NULL COMMENT '恢复时间',
    
    FOREIGN KEY (migration_id) REFERENCES migrations(id) ON DELETE CASCADE,
    INDEX idx_migration_id (migration_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移备份表';
```

### 3. 模块版本表 (module_versions)

```sql
CREATE TABLE module_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    module_name VARCHAR(100) NOT NULL COMMENT '模块名称',
    current_version VARCHAR(20) NOT NULL COMMENT '当前版本',
    previous_version VARCHAR(20) NULL COMMENT '前一版本',
    last_migration_date VARCHAR(8) NULL COMMENT '最后迁移日期',
    migration_count INT DEFAULT 0 COMMENT '迁移次数',
    status ENUM('active', 'inactive', 'upgrading', 'downgrading') DEFAULT 'active' COMMENT '状态',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_module_name (module_name),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='模块版本表';
```

## 核心服务设计

### 1. MigrationService 迁移服务

```php
<?php
declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\MigrationInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;

/**
 * 迁移服务
 * 负责迁移的执行、回滚、状态管理
 */
class MigrationService
{
    private ConnectionFactory $connectionFactory;
    private BackupService $backupService;
    private VersionService $versionService;

    public function __construct(
        ConnectionFactory $connectionFactory,
        BackupService $backupService,
        VersionService $versionService
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->backupService = $backupService;
        $this->versionService = $versionService;
    }

    /**
     * 执行迁移
     */
    public function executeMigration(MigrationInterface $migration, Context $context): bool
    {
        $connection = $this->connectionFactory->create();
        
        try {
            $connection->beginTransaction();
            
            // 记录迁移开始
            $migrationRecord = $this->createMigrationRecord($migration, $context);
            
            // 如果需要备份，先执行备份
            if ($migration->requiresBackup()) {
                $backupId = $this->backupService->createBackup($migration, $context);
                $migrationRecord->setBackupId($backupId);
            }
            
            // 执行迁移
            $result = $migration->up($this->connectionFactory, $context);
            
            if ($result) {
                $migrationRecord->setStatus('completed')
                               ->setExecutedAt(new \DateTime())
                               ->save();
                
                $connection->commit();
                
                // 更新模块版本
                $this->versionService->updateModuleVersion($context->getModuleName(), $migration->getVersion());
                
                return true;
            } else {
                throw new \Exception("迁移执行返回false");
            }
            
        } catch (\Exception $e) {
            $connection->rollback();
            
            $migrationRecord->setStatus('failed')
                           ->setErrorMessage($e->getMessage())
                           ->save();
            
            throw $e;
        }
    }

    /**
     * 回滚迁移
     */
    public function rollbackMigration(MigrationInterface $migration, Context $context): bool
    {
        $connection = $this->connectionFactory->create();
        
        try {
            $connection->beginTransaction();
            
            // 查找迁移记录
            $migrationRecord = $this->getMigrationRecord($migration, $context);
            
            if (!$migrationRecord || $migrationRecord->getStatus() !== 'completed') {
                throw new \Exception("迁移记录不存在或状态不正确");
            }
            
            // 执行回滚
            $result = $migration->down($this->connectionFactory, $context);
            
            if ($result) {
                $migrationRecord->setStatus('rolled_back')
                               ->setRolledBackAt(new \DateTime())
                               ->save();
                
                $connection->commit();
                
                // 恢复备份（如果需要）
                if ($migrationRecord->getBackupId()) {
                    $this->backupService->restoreBackup($migrationRecord->getBackupId());
                }
                
                return true;
            } else {
                throw new \Exception("回滚执行返回false");
            }
            
        } catch (\Exception $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * 获取待执行的迁移
     */
    public function getPendingMigrations(string $moduleName = null): array
    {
        // 扫描所有模块的迁移文件
        $migrations = [];
        
        if ($moduleName) {
            $migrations = $this->scanModuleMigrations($moduleName);
        } else {
            $migrations = $this->scanAllMigrations();
        }
        
        // 过滤已执行的迁移
        return $this->filterPendingMigrations($migrations);
    }

    /**
     * 检查迁移冲突
     */
    public function checkMigrationConflicts(array $migrations): array
    {
        $conflicts = [];
        
        foreach ($migrations as $migration) {
            $affectedTables = $migration->getAffectedTables();
            
            foreach ($affectedTables as $table) {
                // 检查是否有其他迁移影响同一张表
                $conflictingMigrations = $this->findConflictingMigrations($table, $migrations);
                
                if (!empty($conflictingMigrations)) {
                    $conflicts[] = [
                        'migration' => $migration,
                        'table' => $table,
                        'conflicts' => $conflictingMigrations
                    ];
                }
            }
        }
        
        return $conflicts;
    }
}
```

### 2. BackupService 备份服务

```php
<?php
declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\MigrationInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Data\Context;

/**
 * 备份服务
 * 负责数据备份和恢复
 */
class BackupService
{
    private ConnectionFactory $connectionFactory;
    private string $backupPath;

    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
        $this->backupPath = BP . '/var/migration_backups';
        
        // 确保备份目录存在
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * 创建备份
     */
    public function createBackup(MigrationInterface $migration, Context $context): int
    {
        $backupStrategy = $migration->getBackupStrategy();
        $backupName = $this->generateBackupName($migration, $context);
        $backupPath = $this->backupPath . '/' . $backupName;
        
        $connection = $this->connectionFactory->create();
        
        try {
            switch ($backupStrategy['strategy']) {
                case 'full':
                    $this->createFullBackup($connection, $backupPath, $backupStrategy['tables']);
                    break;
                    
                case 'table':
                    $this->createTableBackup($connection, $backupPath, $backupStrategy['tables']);
                    break;
                    
                case 'column':
                    $this->createColumnBackup($connection, $backupPath, $backupStrategy['tables'], $backupStrategy['columns']);
                    break;
                    
                case 'data':
                    $this->createDataBackup($connection, $backupPath, $backupStrategy['tables']);
                    break;
            }
            
            // 记录备份信息
            $backupRecord = ObjectManager::getInstance(\Weline\Database\Model\MigrationBackup::class);
            $backupRecord->setMigrationId($migration->getId())
                        ->setBackupType($backupStrategy['strategy'])
                        ->setBackupName($backupName)
                        ->setBackupPath($backupPath)
                        ->setBackupSize(filesize($backupPath))
                        ->setTables(json_encode($backupStrategy['tables']))
                        ->setColumns(json_encode($backupStrategy['columns'] ?? []))
                        ->setStatus('completed')
                        ->save();
            
            return $backupRecord->getId();
            
        } catch (\Exception $e) {
            // 清理失败的备份文件
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
            throw $e;
        }
    }

    /**
     * 恢复备份
     */
    public function restoreBackup(int $backupId): bool
    {
        $backupRecord = ObjectManager::getInstance(\Weline\Database\Model\MigrationBackup::class)
                                   ->load($backupId);
        
        if (!$backupRecord->getId()) {
            throw new \Exception("备份记录不存在");
        }
        
        $connection = $this->connectionFactory->create();
        
        try {
            $connection->beginTransaction();
            
            switch ($backupRecord->getBackupType()) {
                case 'full':
                    $this->restoreFullBackup($connection, $backupRecord->getBackupPath());
                    break;
                    
                case 'table':
                    $this->restoreTableBackup($connection, $backupRecord->getBackupPath());
                    break;
                    
                case 'column':
                    $this->restoreColumnBackup($connection, $backupRecord->getBackupPath());
                    break;
                    
                case 'data':
                    $this->restoreDataBackup($connection, $backupRecord->getBackupPath());
                    break;
            }
            
            $backupRecord->setStatus('restored')
                         ->setRestoredAt(new \DateTime())
                         ->save();
            
            $connection->commit();
            return true;
            
        } catch (\Exception $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * 创建完整备份
     */
    private function createFullBackup($connection, string $backupPath, array $tables): void
    {
        $sql = "SHOW TABLES";
        $result = $connection->query($sql)->fetchArray();
        
        $backupContent = [];
        
        foreach ($result as $row) {
            $tableName = array_values($row)[0];
            
            if (empty($tables) || in_array($tableName, $tables)) {
                // 获取表结构
                $createTableSql = $connection->query("SHOW CREATE TABLE `{$tableName}`")->fetchArray()[0]['Create Table'];
                $backupContent[$tableName]['structure'] = $createTableSql;
                
                // 获取表数据
                $dataSql = "SELECT * FROM `{$tableName}`";
                $data = $connection->query($dataSql)->fetchArray();
                $backupContent[$tableName]['data'] = $data;
            }
        }
        
        file_put_contents($backupPath, json_encode($backupContent, JSON_PRETTY_PRINT));
    }

    /**
     * 恢复完整备份
     */
    private function restoreFullBackup($connection, string $backupPath): void
    {
        $backupContent = json_decode(file_get_contents($backupPath), true);
        
        foreach ($backupContent as $tableName => $tableData) {
            // 恢复表结构
            $connection->query($tableData['structure'])->fetch();
            
            // 恢复表数据
            if (!empty($tableData['data'])) {
                foreach ($tableData['data'] as $row) {
                    $columns = array_keys($row);
                    $values = array_values($row);
                    
                    $sql = "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES (" . 
                           str_repeat('?,', count($values) - 1) . "?)";
                    
                    $connection->query($sql, $values)->fetch();
                }
            }
        }
    }
}
```

## 命令行工具设计

### 1. 迁移安装命令

```php
<?php
declare(strict_types=1);

namespace Weline\Database\Console\Db\Migrate;

use Weline\Framework\Console\CommandInterface;
use Weline\Database\Service\MigrationService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 迁移安装命令
 */
class Install implements CommandInterface
{
    public function execute(array $args = [], array $data = []): void
    {
        $moduleName = $args['module'] ?? null;
        $version = $args['version'] ?? null;
        $force = isset($args['force']);
        
        /** @var MigrationService $migrationService */
        $migrationService = ObjectManager::getInstance(MigrationService::class);
        
        try {
            // 获取待执行的迁移
            $migrations = $migrationService->getPendingMigrations($moduleName);
            
            if (empty($migrations)) {
                echo "✅ 没有待执行的迁移\n";
                return;
            }
            
            // 检查冲突
            $conflicts = $migrationService->checkMigrationConflicts($migrations);
            if (!empty($conflicts) && !$force) {
                echo "❌ 检测到迁移冲突:\n";
                foreach ($conflicts as $conflict) {
                    echo "  - 迁移: {$conflict['migration']->getDescription()}\n";
                    echo "    表: {$conflict['table']}\n";
                    echo "    冲突: " . implode(', ', $conflict['conflicts']) . "\n";
                }
                echo "使用 --force 参数强制执行\n";
                return;
            }
            
            // 执行迁移
            $successCount = 0;
            $failCount = 0;
            
            foreach ($migrations as $migration) {
                try {
                    echo "🔄 执行迁移: {$migration->getDescription()}\n";
                    
                    $migrationService->executeMigration($migration, $context);
                    $successCount++;
                    
                    echo "✅ 迁移完成: {$migration->getDescription()}\n";
                    
                } catch (\Exception $e) {
                    $failCount++;
                    echo "❌ 迁移失败: {$migration->getDescription()}\n";
                    echo "   错误: {$e->getMessage()}\n";
                    
                    if (!$force) {
                        echo "❌ 迁移中断，请修复错误后重试\n";
                        break;
                    }
                }
            }
            
            echo "\n📊 迁移统计:\n";
            echo "  成功: {$successCount}\n";
            echo "  失败: {$failCount}\n";
            
        } catch (\Exception $e) {
            echo "❌ 迁移执行失败: {$e->getMessage()}\n";
        }
    }

    public function tip(): string
    {
        return "执行数据库迁移";
    }
}
```

### 2. 迁移回滚命令

```php
<?php
declare(strict_types=1);

namespace Weline\Database\Console\Db\Migrate;

use Weline\Framework\Console\CommandInterface;
use Weline\Database\Service\MigrationService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 迁移回滚命令
 */
class Uninstall implements CommandInterface
{
    public function execute(array $args = [], array $data = []): void
    {
        $moduleName = $args['module'] ?? null;
        $version = $args['version'] ?? null;
        $step = (int)($args['step'] ?? 1);
        
        /** @var MigrationService $migrationService */
        $migrationService = ObjectManager::getInstance(MigrationService::class);
        
        try {
            // 获取可回滚的迁移
            $migrations = $migrationService->getRollbackableMigrations($moduleName, $version, $step);
            
            if (empty($migrations)) {
                echo "✅ 没有可回滚的迁移\n";
                return;
            }
            
            echo "⚠️  即将回滚以下迁移:\n";
            foreach ($migrations as $migration) {
                echo "  - {$migration->getDescription()} (v{$migration->getVersion()})\n";
            }
            
            // 确认回滚
            if (!isset($args['yes'])) {
                echo "\n确认回滚? (y/N): ";
                $confirm = trim(fgets(STDIN));
                if (strtolower($confirm) !== 'y') {
                    echo "❌ 回滚已取消\n";
                    return;
                }
            }
            
            // 执行回滚
            $successCount = 0;
            $failCount = 0;
            
            foreach ($migrations as $migration) {
                try {
                    echo "🔄 回滚迁移: {$migration->getDescription()}\n";
                    
                    $migrationService->rollbackMigration($migration, $context);
                    $successCount++;
                    
                    echo "✅ 回滚完成: {$migration->getDescription()}\n";
                    
                } catch (\Exception $e) {
                    $failCount++;
                    echo "❌ 回滚失败: {$migration->getDescription()}\n";
                    echo "   错误: {$e->getMessage()}\n";
                }
            }
            
            echo "\n📊 回滚统计:\n";
            echo "  成功: {$successCount}\n";
            echo "  失败: {$failCount}\n";
            
        } catch (\Exception $e) {
            echo "❌ 回滚执行失败: {$e->getMessage()}\n";
        }
    }

    public function tip(): string
    {
        return "回滚数据库迁移";
    }
}
```

## 事件监听系统

### 1. 模块升级事件监听

```php
<?php
declare(strict_types=1);

namespace Weline\Database\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;
use Weline\Database\Service\MigrationService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 模块升级事件监听器
 * 在模块升级时自动执行迁移
 */
class ModuleUpgradeObserver implements ObserverInterface
{
    public function execute(Event $event): void
    {
        $moduleName = $event->getData('module_name');
        $newVersion = $event->getData('new_version');
        $oldVersion = $event->getData('old_version');
        
        if (!$moduleName || !$newVersion) {
            return;
        }
        
        try {
            /** @var MigrationService $migrationService */
            $migrationService = ObjectManager::getInstance(MigrationService::class);
            
            // 获取该模块的待执行迁移
            $migrations = $migrationService->getPendingMigrations($moduleName);
            
            if (empty($migrations)) {
                return;
            }
            
            // 检查版本兼容性
            $versionMigrations = $migrationService->filterMigrationsByVersion($migrations, $oldVersion, $newVersion);
            
            if (empty($versionMigrations)) {
                return;
            }
            
            // 检查冲突
            $conflicts = $migrationService->checkMigrationConflicts($versionMigrations);
            if (!empty($conflicts)) {
                throw new \Exception("检测到迁移冲突，请手动处理: " . json_encode($conflicts));
            }
            
            // 执行迁移
            foreach ($versionMigrations as $migration) {
                $migrationService->executeMigration($migration, $context);
            }
            
            $event->setData('migration_executed', true);
            $event->setData('migration_count', count($versionMigrations));
            
        } catch (\Exception $e) {
            $event->setData('migration_error', $e->getMessage());
            throw $e;
        }
    }
}
```

### 2. 系统升级事件监听

```php
<?php
declare(strict_types=1);

namespace Weline\Database\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;
use Weline\Database\Service\MigrationService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 系统升级事件监听器
 * 在系统升级时执行所有模块的迁移
 */
class SetupUpgradeObserver implements ObserverInterface
{
    public function execute(Event $event): void
    {
        try {
            /** @var MigrationService $migrationService */
            $migrationService = ObjectManager::getInstance(MigrationService::class);
            
            // 获取所有待执行的迁移
            $migrations = $migrationService->getPendingMigrations();
            
            if (empty($migrations)) {
                return;
            }
            
            // 按模块分组
            $moduleMigrations = [];
            foreach ($migrations as $migration) {
                $moduleName = $migration->getModuleName();
                $moduleMigrations[$moduleName][] = $migration;
            }
            
            // 检查全局冲突
            $globalConflicts = $migrationService->checkGlobalMigrationConflicts($migrations);
            if (!empty($globalConflicts)) {
                throw new \Exception("检测到全局迁移冲突，请手动处理");
            }
            
            // 按模块执行迁移
            $totalSuccess = 0;
            $totalFailed = 0;
            
            foreach ($moduleMigrations as $moduleName => $moduleMigrationList) {
                echo "🔄 执行模块 {$moduleName} 的迁移...\n";
                
                foreach ($moduleMigrationList as $migration) {
                    try {
                        $migrationService->executeMigration($migration, $context);
                        $totalSuccess++;
                    } catch (\Exception $e) {
                        $totalFailed++;
                        echo "❌ 模块 {$moduleName} 迁移失败: {$e->getMessage()}\n";
                    }
                }
            }
            
            $event->setData('migration_executed', true);
            $event->setData('total_success', $totalSuccess);
            $event->setData('total_failed', $totalFailed);
            
        } catch (\Exception $e) {
            $event->setData('migration_error', $e->getMessage());
            throw $e;
        }
    }
}
```

## 安全机制设计

### 1. 数据保护策略

#### 备份策略
- **全量备份**: 影响核心表结构时
- **表级备份**: 删除或修改表时
- **字段级备份**: 删除或修改字段时
- **数据备份**: 数据迁移时

#### 回滚保护
- **事务保护**: 所有迁移操作都在事务中执行
- **备份验证**: 执行前验证备份完整性
- **冲突检测**: 检测迁移间的冲突
- **版本控制**: 严格的版本管理

### 2. 冲突检测机制

```php
/**
 * 检测迁移冲突
 */
private function detectConflicts(array $migrations): array
{
    $conflicts = [];
    $tableLocks = [];
    
    foreach ($migrations as $migration) {
        $affectedTables = $migration->getAffectedTables();
        $migrationType = $migration->getType();
        
        foreach ($affectedTables as $table) {
            if (isset($tableLocks[$table])) {
                $conflicts[] = [
                    'table' => $table,
                    'conflict_type' => 'table_lock',
                    'migration1' => $tableLocks[$table],
                    'migration2' => $migration,
                    'severity' => $this->getConflictSeverity($tableLocks[$table], $migration)
                ];
            }
            
            // 记录表锁定
            $tableLocks[$table] = $migration;
        }
    }
    
    return $conflicts;
}

/**
 * 获取冲突严重程度
 */
private function getConflictSeverity($migration1, $migration2): string
{
    $criticalTypes = ['drop_table', 'drop_column'];
    $moderateTypes = ['modify_table', 'modify_column'];
    
    if (in_array($migration1->getType(), $criticalTypes) || 
        in_array($migration2->getType(), $criticalTypes)) {
        return 'critical';
    }
    
    if (in_array($migration1->getType(), $moderateTypes) || 
        in_array($migration2->getType(), $moderateTypes)) {
        return 'moderate';
    }
    
    return 'low';
}
```

## 使用示例

### 1. 创建迁移文件

```php
<?php
declare(strict_types=1);

namespace FlashForge\ShopifyOrderManager\Setup\Db\Migration;

use Weline\Database\MigrationInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Data\Context;

/**
 * 删除原始数据字段迁移
 * 
 * @package FlashForge\ShopifyOrderManager\Setup\Db\Migration
 * @author 秋枫雁飞
 * @email aiweline@qq.com
 * @date 2025-01-01
 * @version 1.0.1
 */
class DropColumn__RawData_20250101_v1_0_1 implements MigrationInterface
{
    public function up(ConnectionFactory $connectionFactory, Context $context): bool
    {
        $connection = $connectionFactory->create();
        
        // 删除订单表的raw_data字段
        $sql = "ALTER TABLE `shopify_orders` DROP COLUMN `raw_data`";
        $connection->query($sql)->fetch();
        
        // 删除订单项表的raw_data字段
        $sql = "ALTER TABLE `shopify_order_items` DROP COLUMN `raw_data`";
        $connection->query($sql)->fetch();
        
        return true;
    }

    public function down(ConnectionFactory $connectionFactory, Context $context): bool
    {
        $connection = $connectionFactory->create();
        
        // 恢复订单表的raw_data字段
        $sql = "ALTER TABLE `shopify_orders` ADD COLUMN `raw_data` TEXT COMMENT '原始数据JSON'";
        $connection->query($sql)->fetch();
        
        // 恢复订单项表的raw_data字段
        $sql = "ALTER TABLE `shopify_order_items` ADD COLUMN `raw_data` TEXT COMMENT '原始数据JSON'";
        $connection->query($sql)->fetch();
        
        return true;
    }

    public function getDescription(): string
    {
        return "删除原始数据字段";
    }

    public function getVersion(): string
    {
        return "1.0.1";
    }

    public function getDate(): string
    {
        return "20250101";
    }

    public function getType(): string
    {
        return "drop_column";
    }

    public function getAffectedTables(): array
    {
        return ["shopify_orders", "shopify_order_items"];
    }

    public function requiresBackup(): bool
    {
        return true; // 删除字段需要备份
    }

    public function getBackupStrategy(): array
    {
        return [
            'strategy' => 'column',
            'tables' => ["shopify_orders", "shopify_order_items"],
            'columns' => ["raw_data"]
        ];
    }
}
```

### 2. 执行迁移命令

```bash
# 执行所有待执行的迁移
php bin/w db:migrate:install

# 执行指定模块的迁移
php bin/w db:migrate:install --module=FlashForge_ShopifyOrderManager

# 执行指定版本的迁移
php bin/w db:migrate:install --module=FlashForge_ShopifyOrderManager --version=1.0.1

# 强制执行（忽略冲突）
php bin/w db:migrate:install --force

# 回滚迁移
php bin/w db:migrate:uninstall --module=FlashForge_ShopifyOrderManager

# 回滚指定步数
php bin/w db:migrate:uninstall --module=FlashForge_ShopifyOrderManager --step=2

# 查看迁移状态
php bin/w db:migrate:status

# 创建备份
php bin/w db:migrate:backup --module=FlashForge_ShopifyOrderManager

# 恢复备份
php bin/w db:migrate:restore --backup-id=123
```

### 3. 事件配置

```xml
<!-- app/code/Weline/Database/etc/event.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<events xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:weline:module:Weline_Framework::etc/xsd/event.xsd">
    
    <!-- 模块升级事件 -->
    <event name="module_upgrade">
        <observer name="migration_executor" 
                  instance="Weline\Database\Observer\ModuleUpgradeObserver" 
                  method="execute"/>
    </event>
    
    <!-- 系统升级事件 -->
    <event name="setup_upgrade">
        <observer name="migration_executor" 
                  instance="Weline\Database\Observer\SetupUpgradeObserver" 
                  method="execute"/>
    </event>
    
</events>
```

## 总结

这个企业级数据库迁移系统提供了：

1. **完整的迁移管理**: 支持创建、执行、回滚迁移
2. **数据安全保障**: 自动备份和恢复机制
3. **冲突检测**: 智能检测迁移冲突
4. **版本控制**: 基于模块版本的迁移管理
5. **事件集成**: 与框架升级流程无缝集成
6. **命令行工具**: 丰富的命令行操作
7. **语义化命名**: 清晰的文件命名规范
8. **高可用性**: 事务保护和错误恢复

系统设计遵循WelineFramework的开发规范，使用高度抽象的设计模式，确保系统的可扩展性和可维护性。
