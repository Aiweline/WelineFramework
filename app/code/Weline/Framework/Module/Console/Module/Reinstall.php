<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\Console\Server\TablePrinter;
use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Register\Register;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Db\ModelSetup;

class Reinstall extends CommandAbstract
{
    use TablePrinter;
    /**
     * @var System
     */
    private System $system;

    /**
     * @var ModuleFileReader
     */
    private ModuleFileReader $moduleFileReader;

    /**
     * @var array 记录所有创建的备份表
     */
    private array $backupTables = [];

    /**
     * @var string 统一的备份批次时间戳
     */
    private string $backupTimestamp = '';
    
    /**
     * @var array 命令参数
     */
    private array $args = [];

    public function __construct(
        Printing         $printer,
        System           $system,
        ModuleFileReader $moduleFileReader
    )
    {
        $this->printer = $printer;
        $this->system = $system;
        $this->moduleFileReader = $moduleFileReader;
    }

    /**
     * @DESC         |重新安装模块（危险操作，仅限开发模式）
     *
     * 参数区：
     *
     * @param array $args
     * @param array $data
     * @return mixed|void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function execute(array $args = [], array $data = [])
    {
        // 保存参数供后续使用
        $this->args = $args;
        
        // 1. 检查是否在开发模式
        $deploy_mode = Env::get('deploy', 'prod');
        if ($deploy_mode !== 'dev' && $deploy_mode !== 'development') {
            $this->printer->error(__('错误：此命令只能在开发模式下运行！'));
            $this->printer->note(__('当前部署模式：%{1}', [$deploy_mode]));
            $this->printer->note(__('请使用以下命令切换到开发模式：'));
            $this->printer->setup('php bin/w deploy:mode:set dev');
            exit(1);
        }

        // 2. 获取要重新安装的模块
        $moduleNames = $args['module'] ?? $args['m'] ?? [];
        if (is_string($moduleNames)) {
            $moduleNames = explode(' ', $moduleNames);
        }

        if (empty($moduleNames)) {
            $this->printer->error(__('错误：请指定要重新安装的模块！'));
            $this->printer->note(__('用法示例：'));
            $this->printer->setup('php bin/w module:reinstall -m Weline_Demo');
            $this->printer->setup('php bin/w module:reinstall --module "Weline_Demo Weline_Test"');
            exit(1);
        }

        // 3. 验证模块是否存在
        $modules = Env::getInstance()->getModuleList();
        foreach ($moduleNames as $moduleName) {
            if (!isset($modules[$moduleName])) {
                $this->printer->error(__('错误：模块 %{1} 不存在！', [$moduleName]));
                exit(1);
            }
        }

        // 4. 检查是否强制模式（-f 或 --force）
        $force = isset($args['f']) || isset($args['force']);
        
        // 5. 显示警告信息并要求确认（除非是强制模式）
        if (!$force) {
            $this->printer->error('');
            $this->printer->error('╔════════════════════════════════════════════════════════════════╗');
            $this->printer->error('║                      ⚠️  危险操作警告 ⚠️                        ║');
            $this->printer->error('╚════════════════════════════════════════════════════════════════╝');
            $this->printer->error('');
            $this->printer->warning(__('您即将重新安装以下模块：'));
            foreach ($moduleNames as $moduleName) {
                $this->printer->warning('  - ' . $moduleName);
            }
            $this->printer->error('');
            $this->printer->warning(__('此操作将执行以下步骤：'));
            $this->printer->note(__('1. 备份模块的所有数据库表（备份到 var/backup/db/ 目录）'));
            $this->printer->note(__('2. 复制数据库表并添加时间戳（如：demo → demo_backup_2025_10_27_14_30_00）'));
            $this->printer->note(__('3. 删除模块的所有数据库表'));
            $this->printer->note(__('4. 从 app/etc/modules.php 中删除模块注册信息'));
            $this->printer->note(__('5. 从 app/etc/module_dependencies.php 中删除模块依赖信息'));
            $this->printer->note(__('6. 重新安装指定的模块'));
            $this->printer->note(__('7. 询问是否清理历史备份表'));
            $this->printer->error('');
            $this->printer->error(__('⚠️  警告：此操作不可逆！所有模块数据将被永久删除！'));
            $this->printer->error(__('⚠️  警告：虽然会自动备份，但请确保您已手动备份重要数据！'));
            $this->printer->error(__('⚠️  警告：如果有其他模块依赖于这些模块，可能会导致系统错误！'));
            $this->printer->error('');
            
            // 要求用户输入确认
            $this->printer->setup(__('请输入 "yes" 或 "y" 确认继续，输入其他任何内容取消：'));
            $confirm = strtolower(trim($this->system->input()));
            
            if ($confirm !== 'yes' && $confirm !== 'y') {
                $this->printer->note(__('操作已取消。'));
                exit(0);
            }
        } else {
            $this->printer->note(__('强制模式：跳过确认，直接执行重装...'));
        }

        // 6. 生成统一的备份批次时间戳
        $this->backupTimestamp = date('Y_m_d_H_i_s');
        $this->printer->note('');
        $this->printer->setup(__('备份批次时间戳：%{1}', [$this->backupTimestamp]));
        
        // 7. 开始重新安装流程
        $this->printer->note('');
        $this->printer->note('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('开始重新安装模块...'));
        $this->printer->note('═══════════════════════════════════════════════════════════════');

        foreach ($moduleNames as $moduleName) {
            $this->reinstallModule($moduleName, $modules[$moduleName]);
        }

        // 7. 执行 module:upgrade 重新安装模块
        $this->printer->note('');
        $this->printer->setup(__('开始重新安装模块...'));
        
        // 确保模块被标记为安装状态（reinstallModule已经删除了，这里再次确认）
        $modulesFile = Env::path_MODULES_FILE;
        if (is_file($modulesFile)) {
            $modules = require $modulesFile;
            $needUpdate = false;
            foreach ($moduleNames as $moduleName) {
                if (isset($modules[$moduleName])) {
                    // 删除模块，让系统重新识别为全新安装
                    unset($modules[$moduleName]);
                    $needUpdate = true;
                }
            }
            if ($needUpdate) {
                $content = '<?php return ' . var_export($modules, true) . ';';
                file_put_contents($modulesFile, $content);
                $this->printer->note(__('已清除模块注册信息，准备重新安装...'));
            }
        }
        
        // 清除模块缓存，确保 Handle 重新加载模块列表
        $this->printer->note(__('清除模块缓存，确保重新加载模块列表...'));
        $this->system->exec(PHP_BINARY . ' php bin/w cache:clear -f');
        
        // 等待一下，确保文件系统同步
        usleep(100000); // 0.1秒
        
        // 重新注册模块
        $this->printer->note('');
        $this->printer->setup(__('重新注册并安装模块...'));
        list($origin_vendor_modules, $dependencyModules) = Register::getOriginModulesData();
        foreach ($moduleNames as $moduleName) {
            if (isset($dependencyModules[$moduleName])) {
                // 执行 register.php 文件
                if (is_file($dependencyModules[$moduleName]['register'])) {
                    require $dependencyModules[$moduleName]['register'];
                }
            }
        }
        
        // 等待一下，确保 modules.php 被更新
        usleep(200000); // 0.2秒
        
        // 重新加载模块列表
        $modules = Env::getInstance()->getModuleList();
        
        // 直接执行 Setup/Install.php 和 Model install，完全自己控制
        foreach ($moduleNames as $moduleName) {
            if (!isset($modules[$moduleName])) {
                $this->printer->warning(__('模块 %{1} 未找到，跳过安装。', [$moduleName]));
                continue;
            }
            
            $moduleData = $modules[$moduleName];
            $module = new Module($moduleData);
            
            $this->printer->note('');
            $this->printer->note('───────────────────────────────────────────────────────────────');
            $this->printer->setup(__('安装模块：%{1}', [$moduleName]));
            $this->printer->note('───────────────────────────────────────────────────────────────');
            
            try {
                // 1. 执行 Setup/Install.php
                $this->executeSetupInstall($module);
                
                // 2. 执行 Model install
                $this->executeModelInstall($module);
                
                // 3. 更新模块注册信息（标记为已安装）
                $this->updateModuleRegistration($moduleName, $moduleData);
                
                $this->printer->success(__('模块 %{1} 安装完成！', [$moduleName]));
            } catch (\Exception $e) {
                $this->printer->error(__('安装模块 %{1} 时出错：%{2}', [$moduleName, $e->getMessage()]));
            }
        }

        $this->printer->note('');
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('模块重新安装完成！'));
        $this->printer->success('═══════════════════════════════════════════════════════════════');

        // 8. 询问是否清理备份表
        $this->handleBackupTableCleanup();
    }

    /**
     * 重新安装单个模块
     *
     * @param string $moduleName
     * @param array $moduleData
     * @throws Exception
     * @throws \ReflectionException
     */
    private function reinstallModule(string $moduleName, array $moduleData): void
    {
        $this->printer->note('');
        $this->printer->note('───────────────────────────────────────────────────────────────');
        $this->printer->setup(__('处理模块：%{1}', [$moduleName]));
        $this->printer->note('───────────────────────────────────────────────────────────────');

        // 1. 获取模块的所有 Model 并备份、复制、删除表
        $this->printer->note(__('步骤 1/3：备份、复制并删除数据库表...'));
        $this->dropModuleTables($moduleName, $moduleData);

        // 2. 从 modules.php 删除模块
        $this->printer->note(__('步骤 2/3：从 modules.php 删除模块注册信息...'));
        $this->removeFromModulesFile($moduleName);

        // 3. 从 module_dependencies.php 删除模块
        $this->printer->note(__('步骤 3/3：从 module_dependencies.php 删除模块依赖信息...'));
        $this->removeFromDependenciesFile($moduleName);

        $this->printer->success(__('模块 %{1} 清理完成！', [$moduleName]));
    }

    /**
     * 备份、复制并删除模块的所有表
     * 
     * 步骤：
     * 1. 备份表到 SQL 文件（var/backup/db/）
     * 2. 复制表为 {表名}_backup（在数据库中）
     * 3. 删除原表
     *
     * @param string $moduleName
     * @param array $moduleData
     * @throws Exception
     * @throws \ReflectionException
     */
    private function dropModuleTables(string $moduleName, array $moduleData): void
    {
        $module = new Module($moduleData);
        
        // 获取模块的所有 Model 类
        $modelClasses = $this->moduleFileReader->readClass($module, 'Model');
        
        if (empty($modelClasses)) {
            $this->printer->warning(__('  未找到模块 Model，跳过数据库表删除。'));
            return;
        }

        $this->printer->note(__('  发现 %{1} 个 Model 类', [count($modelClasses)]));

        foreach ($modelClasses as $modelClass) {
            if (!class_exists($modelClass)) {
                $this->printer->warning(__('  跳过不存在的类：%{1}', [$modelClass]));
                continue;
            }

            try {
                // 实例化 Model
                $model = ObjectManager::getInstance($modelClass);
                
                if (!$model instanceof \Weline\Framework\Database\AbstractModel) {
                    continue;
                }

                // 确保模型已初始化连接，这样才能正确获取带前缀的表名
                $model->getConnection();
                
                $tableName = $model->getTable();
                $originTableName = $model->getOriginTableName();
                
                if (empty($tableName)) {
                    continue;
                }

                // 检查表是否存在（使用 PDO 直接查询，确保正确检查带前缀的表名）
                $connector = $model->getConnection()->getConnector();
                $prefix = $model->getConnection()->getConfigProvider()->getPrefix();
                $fullTableName = $prefix . $originTableName;
                
                // 使用 PDO 直接查询表是否存在
                if (method_exists($connector, 'getLink')) {
                    /** @var \PDO $pdo */
                    $pdo = call_user_func([$connector, 'getLink']);
                    $dbName = $model->getConnection()->getConfigProvider()->getDatabase();
                    $checkSql = "SHOW TABLES LIKE '{$fullTableName}'";
                    if ($dbName) {
                        $checkSql = "SHOW TABLES FROM `{$dbName}` LIKE '{$fullTableName}'";
                    }
                    $stmt = $pdo->query($checkSql);
                    $tableExists = $stmt->rowCount() > 0;
                } else {
                    // 降级方案：使用 tableExist 方法
                    $tableExists = $connector->tableExist($originTableName);
                    if ($tableExists) {
                        $fullTableName = $prefix . $originTableName;
                    }
                }
                
                if (!$tableExists) {
                    $this->printer->warning(__('  表 %{1} (完整名: %{2}) 不存在，跳过。', [$originTableName, $fullTableName]));
                    continue;
                }
                
                // 使用带前缀的完整表名
                $tableName = $fullTableName;

                // 备份表到文件（使用原始表名，不带数据库前缀）
                $this->printer->note(__('  备份表到文件：%{1}...', [$originTableName]));
                
                try {
                    // 确保备份目录存在
                    $backupDir = Env::backup_dir . 'db' . DS;
                    if (!is_dir($backupDir)) {
                        mkdir($backupDir, 0777, true);
                    }
                    
                    // 使用模型的查询对象进行备份
                    $model->clearQuery()->backup('', $originTableName);
                    $backupFile = $model->getQuery()->backup_file ?? 'var/backup/db/' . $originTableName;
                    $this->printer->success(__('  ✓ 备份文件：%{1}', [$backupFile]));
                } catch (\Exception $backupException) {
                    $this->printer->warning(__('  备份失败：%{1}', [$backupException->getMessage()]));
                    $this->printer->warning(__('  将继续执行表复制...'));
                }

                // 复制表（使用统一的批次时间戳）
                // 备份表名使用原始表名（不带前缀），因为备份表也不应该带前缀
                $backupTableName = $originTableName . '_backup_' . $this->backupTimestamp;
                $this->printer->note(__('  复制表：%{1} → %{2}...', [$tableName, $backupTableName]));
                
                try {
                    // 通过 Connector 获取 PDO 连接
                    $connector = $model->getConnection()->getConnector();
                    // getLink() 方法在具体的 Connector 实现类中存在（Mysql/Connector, Pgsql/Connector 等）
                    // 使用 call_user_func 来避免 linter 错误
                    if (method_exists($connector, 'getLink')) {
                        /** @var \PDO $pdo */
                        $pdo = call_user_func([$connector, 'getLink']);
                    } else {
                        throw new \Exception(__('无法获取数据库连接'));
                    }
                    
                    // 复制表结构和数据（不删除旧备份，支持多次备份）
                    // 使用实际存在的表名（$tableName）进行复制
                    $createBackupSql = "CREATE TABLE `{$backupTableName}` LIKE `{$tableName}`";
                    $pdo->exec($createBackupSql);
                    
                    $insertBackupSql = "INSERT INTO `{$backupTableName}` SELECT * FROM `{$tableName}`";
                    $pdo->exec($insertBackupSql);
                    
                    // 记录备份表信息（保存 connector 以便后续使用）
                    $this->backupTables[] = [
                        'original' => $originTableName,
                        'backup' => $backupTableName,
                        'module' => $moduleName,
                        'connector' => $connector
                    ];
                    
                    $this->printer->success(__('  ✓ 表已复制：%{1} (包含所有数据)', [$backupTableName]));
                } catch (\Exception $copyException) {
                    $this->printer->warning(__('  表复制失败：%{1}', [$copyException->getMessage()]));
                }

                // 删除原表（使用带前缀的完整表名）
                $this->printer->note(__('  删除原表：%{1}...', [$tableName]));
                $modelSetup = ObjectManager::make(ModelSetup::class);
                $modelSetup->putModel($model);
                // 使用完整表名（带前缀）删除表
                $modelSetup->dropTable($tableName);
                $this->printer->success(__('  ✓ 原表已删除：%{1}', [$tableName]));

            } catch (\Exception $e) {
                $this->printer->error(__('  错误：处理 %{1} 时发生异常：%{2}', [$modelClass, $e->getMessage()]));
                // 继续处理其他表
            }
        }
    }

    /**
     * 从 modules.php 文件中删除模块
     *
     * @param string $moduleName
     */
    private function removeFromModulesFile(string $moduleName): void
    {
        $modulesFile = Env::path_MODULES_FILE;
        
        if (!is_file($modulesFile)) {
            $this->printer->warning(__('  modules.php 文件不存在，跳过。'));
            return;
        }

        $modules = require $modulesFile;
        
        if (!isset($modules[$moduleName])) {
            $this->printer->warning(__('  模块 %{1} 在 modules.php 中不存在，跳过。', [$moduleName]));
            return;
        }

        // 从数组中删除模块
        unset($modules[$moduleName]);

        // 写回文件
        $content = '<?php return ' . var_export($modules, true) . ';';
        file_put_contents($modulesFile, $content);
        
        $this->printer->success(__('  ✓ 已从 modules.php 删除模块 %{1}', [$moduleName]));
    }

    /**
     * 从 module_dependencies.php 文件中删除模块
     *
     * @param string $moduleName
     */
    private function removeFromDependenciesFile(string $moduleName): void
    {
        $dependenciesFile = Env::path_MODULE_DEPENDENCIES_FILE;
        
        if (!is_file($dependenciesFile)) {
            $this->printer->warning(__('  module_dependencies.php 文件不存在，跳过。'));
            return;
        }

        $dependencies = require $dependenciesFile;
        
        if (!isset($dependencies[$moduleName])) {
            $this->printer->warning(__('  模块 %{1} 在 module_dependencies.php 中不存在，跳过。', [$moduleName]));
            return;
        }

        // 从数组中删除模块
        unset($dependencies[$moduleName]);

        // 写回文件
        $content = '<?php  return ' . w_var_export($dependencies, true) . ';';
        file_put_contents($dependenciesFile, $content);
        
        $this->printer->success(__('  ✓ 已从 module_dependencies.php 删除模块 %{1}', [$moduleName]));
    }

    /**
     * 打印多列表格
     * 
     * @param array $headers 表头数组
     * @param array $rows 数据行数组
     */
    private function printMultiColumnTable(array $headers, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        // 计算每列的最大宽度
        $columnWidths = [];
        foreach ($headers as $index => $header) {
            $columnWidths[$index] = $this->getDisplayWidth($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $width = $this->getDisplayWidth((string)$value);
                if ($width > $columnWidths[$index]) {
                    $columnWidths[$index] = $width;
                }
            }
        }

        // 添加左右padding（每列左右各1个空格）
        foreach ($columnWidths as $index => $width) {
            $columnWidths[$index] = $width + 2;
        }

        // 计算总宽度
        $totalWidth = array_sum($columnWidths) + count($headers) + 1;

        // 打印顶部边框
        echo "┌";
        foreach ($columnWidths as $index => $width) {
            echo str_repeat("─", $width);
            if ($index < count($columnWidths) - 1) {
                echo "┬";
            }
        }
        echo "┐\n";

        // 打印表头
        echo "│";
        foreach ($headers as $index => $header) {
            $headerWidth = $this->getDisplayWidth($header);
            $padding = $columnWidths[$index] - $headerWidth - 2;
            echo " " . $header . str_repeat(" ", $padding + 1);
            echo "│";
        }
        echo "\n";

        // 打印表头分隔线
        echo "├";
        foreach ($columnWidths as $index => $width) {
            echo str_repeat("─", $width);
            if ($index < count($columnWidths) - 1) {
                echo "┼";
            }
        }
        echo "┤\n";

        // 打印数据行
        foreach ($rows as $rowIndex => $row) {
            echo "│";
            foreach ($row as $colIndex => $value) {
                $valueStr = (string)$value;
                $valueWidth = $this->getDisplayWidth($valueStr);
                $padding = $columnWidths[$colIndex] - $valueWidth - 2;
                echo " " . $valueStr . str_repeat(" ", $padding + 1);
                echo "│";
            }
            echo "\n";
        }

        // 打印底部边框
        echo "└";
        foreach ($columnWidths as $index => $width) {
            echo str_repeat("─", $width);
            if ($index < count($columnWidths) - 1) {
                echo "┴";
            }
        }
        echo "┘\n";
    }

    /**
     * 处理备份表清理
     */
    private function handleBackupTableCleanup(): void
    {
        if (empty($this->backupTables)) {
            return;
        }

        $this->printer->note('');
        $this->printer->note('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('备份表管理'));
        $this->printer->note('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');

        // 收集所有相关的备份表（包括历史备份）
        $allBackupTables = [];
        $connector = null;
        
        foreach ($this->backupTables as $backupInfo) {
            $connector = $backupInfo['connector'];
            $originalTable = $backupInfo['original'];
            /** @var \PDO $pdo */
            $pdo = method_exists($connector, 'getLink') ? call_user_func([$connector, 'getLink']) : null;
            if (!$pdo) {
                continue;
            }
            
            // 查找该表的所有备份表
            $pattern = $originalTable . '_backup_%';
            $stmt = $pdo->query("SHOW TABLES LIKE '{$pattern}'");
            
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $backupTable = $row[0];
                
                // 获取表的记录数和创建时间
                $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$backupTable}`");
                $count = $countStmt->fetch(\PDO::FETCH_ASSOC)['cnt'];
                
                // 从表名提取时间戳
                $timeStr = str_replace($originalTable . '_backup_', '', $backupTable);
                $timeStr = str_replace('_', '-', substr($timeStr, 0, 10)) . ' ' . str_replace('_', ':', substr($timeStr, 11));
                
                $allBackupTables[] = [
                    'original' => $originalTable,
                    'backup' => $backupTable,
                    'count' => $count,
                    'time' => $timeStr,
                    'module' => $backupInfo['module']
                ];
            }
        }

        if (empty($allBackupTables)) {
            $this->printer->note(__('未发现备份表。'));
            return;
        }

        // 显示备份表列表（使用专业表格）
        $this->printer->setup(__('发现以下备份表：'));
        $this->printer->note('');
        
        // 准备表头和数据
        $headers = ['模块', '原表名', '备份表名', '创建时间', '记录数'];
        $rows = [];
        foreach ($allBackupTables as $table) {
            $rows[] = [
                $table['module'],
                $table['original'],
                $table['backup'],
                $table['time'],
                $table['count']
            ];
        }
        
        $this->printMultiColumnTable($headers, $rows);
        
        $this->printer->note('');
        $this->printer->note(__('共 %{1} 个备份表，占用数据库空间。', [count($allBackupTables)]));
        
        // 统计批次
        $batches = [];
        foreach ($allBackupTables as $table) {
            // 从表名提取时间戳作为批次标识
            $timeStr = str_replace($table['original'] . '_backup_', '', $table['backup']);
            $batches[$timeStr][] = $table;
        }
        
        $this->printer->note(__('共 %{1} 个备份批次。', [count($batches)]));
        $this->printer->note('');
        
        // 询问是否删除
        $this->printer->warning(__('这些备份表是历史备份，可以安全删除以释放空间。'));
        $this->printer->note(__('如果您需要恢复数据，请选择保留。'));
        $this->printer->note(__('提示：备份表按时间戳分批次，相同时间戳的表属于同一批次。'));
        $this->printer->note('');
        // 检查是否强制模式，强制模式下不询问，直接保留备份表
        $force = isset($this->args['f']) || isset($this->args['force']);
        if ($force) {
            $this->printer->note(__('强制模式：保留所有备份表。'));
            return;
        }

        $this->printer->setup(__('是否删除所有备份表？(yes/y=删除, no/n=保留)：'));
        
        $confirm = strtolower(trim($this->system->input()));
        
        if ($confirm === 'yes' || $confirm === 'y') {
            // 删除备份表
            $this->printer->note('');
            $this->printer->setup(__('开始删除备份表...'));
            
            foreach ($allBackupTables as $table) {
                try {
                    // 获取对应的 connector 对象
                    $connector = null;
                    foreach ($this->backupTables as $backupInfo) {
                        if ($backupInfo['backup'] === $table['backup'] || $backupInfo['original'] === $table['original']) {
                            $connector = $backupInfo['connector'];
                            break;
                        }
                    }
                    if (!$connector && !empty($this->backupTables)) {
                        $connector = $this->backupTables[0]['connector'];
                    }
                    if ($connector && method_exists($connector, 'getLink')) {
                        /** @var \PDO $pdo */
                        $pdo = call_user_func([$connector, 'getLink']);
                        $pdo->exec("DROP TABLE IF EXISTS `{$table['backup']}`");
                        $this->printer->success(__('  ✓ 已删除：%{1}', [$table['backup']]));
                    }
                } catch (\Exception $e) {
                    $this->printer->error(__('  ✗ 删除失败：%{1} - %{2}', [$table['backup'], $e->getMessage()]));
                }
            }
            
            $this->printer->note('');
            $this->printer->success(__('备份表清理完成！'));
        } else {
            $this->printer->note('');
            $this->printer->setup(__('备份表已保留。'));
            $this->printer->note(__('您可以稍后使用以下 SQL 手动删除：'));
            $this->printer->note('');
            foreach ($allBackupTables as $table) {
                $this->printer->note("  DROP TABLE IF EXISTS `{$table['backup']}`;");
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('重新安装模块（危险操作，仅限开发模式）。此命令将删除模块的所有数据并重新安装。');
    }

    /**
     * 执行 Setup/Install.php
     */
    private function executeSetupInstall(Module $module): void
    {
        $moduleName = $module->getName();
        $setup_dir = $module->getBasePath() . \Weline\Framework\Setup\Data\DataInterface::dir;
        
        if (!is_dir($setup_dir)) {
            $this->printer->warning(__('模块 %{1} 没有 Setup 目录，跳过 Setup 安装。', [$moduleName]));
            return;
        }
        
        // 先删除所有可能存在的表（包括没有 Model 的表）
        $this->dropAllModuleTables($module);
        
        $setup_namespace = $module->getNamespacePath() . '\\' . ucfirst(\Weline\Framework\Setup\Data\DataInterface::dir) . '\\';
        $setup_context = new \Weline\Framework\Setup\Data\Context(
            $module->getName(),
            $module->getVersion() ?? '1.0.0',
            $module->getDescription() ?? ''
        );
        $setup_data = ObjectManager::getInstance(\Weline\Framework\Setup\Data\Setup::class);
        
        // 执行 Setup/Install.php
        foreach (\Weline\Framework\Setup\Data\DataInterface::install_FILES as $install_FILE) {
            $setup_file = $setup_dir . DS . $install_FILE . '.php';
            if (file_exists($setup_file)) {
                $this->printer->note(__('执行安装文件：%{1}', [$setup_file]));
                $setup = ObjectManager::getInstance($setup_namespace . $install_FILE);
                $setup_data->setModuleContext($setup_context);
                $setup->setup($setup_data, $setup_context);
                $this->printer->success(__('安装文件执行完成：%{1}', [$install_FILE]));
            }
        }
    }
    
    /**
     * 删除模块的所有表（包括没有 Model 的表）
     * 通过读取 Setup/Install.php 中创建的表名来删除
     */
    private function dropAllModuleTables(Module $module): void
    {
        $setup_dir = $module->getBasePath() . \Weline\Framework\Setup\Data\DataInterface::dir;
        $install_file = $setup_dir . DS . 'Install.php';
        
        if (!file_exists($install_file)) {
            return;
        }
        
        // 读取 Install.php 文件，提取所有 createTable 的表名
        $content = file_get_contents($install_file);
        preg_match_all("/createTable\(['\"]([^'\"]+)['\"]/", $content, $matches);
        
        if (empty($matches[1])) {
            return;
        }
        
        $this->printer->note(__('删除模块的所有表（包括 Setup 中定义的表）...'));
        
        // 获取第一个 Model 的连接（所有 Model 应该使用同一个连接）
        $modelClasses = $this->moduleFileReader->readClass($module, 'Model');
        if (empty($modelClasses)) {
            return;
        }
        
        $firstModelClass = $modelClasses[0];
        if (!class_exists($firstModelClass)) {
            return;
        }
        
        try {
            $model = ObjectManager::getInstance($firstModelClass);
            if (!$model instanceof \Weline\Framework\Database\AbstractModel) {
                return;
            }
            
            $connector = $model->getConnection()->getConnector();
            $prefix = $model->getConnection()->getConfigProvider()->getPrefix();
            
            if (method_exists($connector, 'getLink')) {
                /** @var \PDO $pdo */
                $pdo = call_user_func([$connector, 'getLink']);
                
                foreach ($matches[1] as $tableName) {
                    $fullTableName = $prefix . $tableName;
                    // 检查表是否存在
                    $checkSql = "SHOW TABLES LIKE '{$fullTableName}'";
                    $stmt = $pdo->query($checkSql);
                    if ($stmt->rowCount() > 0) {
                        $pdo->exec("DROP TABLE IF EXISTS `{$fullTableName}`");
                        $this->printer->success(__('  ✓ 已删除表：%{1}', [$fullTableName]));
                    }
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('删除表时出错：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 执行 Model install
     */
    private function executeModelInstall(Module $module): void
    {
        $this->printer->note(__('执行 Model 安装...'));
        $setup_context = new \Weline\Framework\Setup\Data\Context(
            $module->getName(),
            $module->getVersion() ?? '1.0.0',
            $module->getDescription() ?? ''
        );
        
        /** @var ModelManager $modelManager */
        $modelManager = ObjectManager::getInstance(ModelManager::class);
        $modelManager->update($module, $setup_context, 'install');
        $this->printer->success(__('Model 安装完成'));
    }
    
    /**
     * 更新模块注册信息（标记为已安装）
     */
    private function updateModuleRegistration(string $moduleName, array $moduleData): void
    {
        $modulesFile = Env::path_MODULES_FILE;
        if (!is_file($modulesFile)) {
            return;
        }
        
        $modules = require $modulesFile;
        if (isset($modules[$moduleName])) {
            // 移除 installing 标志，标记为已安装
            unset($modules[$moduleName]['installing']);
            $modules[$moduleName]['installed'] = true;
            
            $content = '<?php return ' . var_export($modules, true) . ';';
            file_put_contents($modulesFile, $content);
            $this->printer->note(__('已更新模块注册信息：%{1}', [$moduleName]));
        }
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'module:reinstall',
            '重新安装模块（危险操作，仅限开发模式）',
            [
                '-m, --module=<模块名>' => '指定要重新安装的模块（必填，支持多个模块用空格分隔）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '仅在开发模式下可用' => '此命令仅在 deploy=dev 时可用',
                '数据将被删除' => '所有模块数据将被永久删除，虽然会自动备份但请手动备份重要数据',
                '需要确认' => '执行前需要输入 "yes" 或 "y" 确认',
                '双重备份保护' => '1) SQL文件备份到 var/backup/db/；2) 数据库中复制表为 {表名}_backup_{时间戳}',
                '备份表示例' => '如表 demo 会被复制为 demo_backup_2025_10_27_14_30_00，包含所有数据',
                '批次管理' => '同一次重装的所有表使用相同时间戳，便于批量管理',
                '智能清理' => '重装完成后可选择清理历史备份表，也可保留多个批次',
            ],
            [
                '重新安装单个模块' => 'php bin/w module:reinstall -m Weline_Demo',
                '重新安装多个模块' => 'php bin/w module:reinstall --module "Weline_Demo Weline_Test"',
            ],
            'php bin/w module:reinstall -m <模块名>'
        );
    }
}

