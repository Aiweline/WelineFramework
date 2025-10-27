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
use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Db\ModelSetup;

class Reinstall extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;

    /**
     * @var ModuleFileReader
     */
    private ModuleFileReader $moduleFileReader;

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

        // 4. 显示警告信息并要求确认
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
        $this->printer->note(__('2. 复制数据库表并添加 _backup 后缀（如：demo → demo_backup）'));
        $this->printer->note(__('3. 删除模块的所有数据库表'));
        $this->printer->note(__('4. 从 app/etc/modules.php 中删除模块注册信息'));
        $this->printer->note(__('5. 从 app/etc/module_dependencies.php 中删除模块依赖信息'));
        $this->printer->note(__('6. 重新安装指定的模块'));
        $this->printer->error('');
        $this->printer->error(__('⚠️  警告：此操作不可逆！所有模块数据将被永久删除！'));
        $this->printer->error(__('⚠️  警告：虽然会自动备份，但请确保您已手动备份重要数据！'));
        $this->printer->error(__('⚠️  警告：如果有其他模块依赖于这些模块，可能会导致系统错误！'));
        $this->printer->error('');
        
        // 5. 要求用户输入确认
        $this->printer->setup(__('请输入 "yes" 或 "y" 确认继续，输入其他任何内容取消：'));
        $confirm = strtolower(trim($this->system->input()));
        
        if ($confirm !== 'yes' && $confirm !== 'y') {
            $this->printer->note(__('操作已取消。'));
            exit(0);
        }

        // 6. 开始重新安装流程
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
        /**@var Upgrade $upgradeCommand */
        $upgradeCommand = ObjectManager::getInstance(Upgrade::class);
        $upgradeCommand->execute(['module' => $moduleNames]);

        $this->printer->note('');
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('模块重新安装完成！'));
        $this->printer->success('═══════════════════════════════════════════════════════════════');
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

                $tableName = $model->getTable();
                $originTableName = $model->getOriginTableName();
                
                if (empty($tableName)) {
                    continue;
                }

                // 检查表是否存在
                if (!$model->getConnection()->getConnector()->tableExist($originTableName)) {
                    $this->printer->warning(__('  表 %{1} 不存在，跳过。', [$originTableName]));
                    continue;
                }

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

                // 复制表（添加 _backup 后缀）
                $backupTableName = $originTableName . '_backup';
                $this->printer->note(__('  复制表：%{1} → %{2}...', [$originTableName, $backupTableName]));
                
                try {
                    $pdo = $model->getConnection()->getConnector()->getLink();
                    
                    // 先删除旧的备份表（如果存在）
                    $dropBackupSql = "DROP TABLE IF EXISTS `{$backupTableName}`";
                    $pdo->exec($dropBackupSql);
                    
                    // 复制表结构和数据
                    $createBackupSql = "CREATE TABLE `{$backupTableName}` LIKE `{$originTableName}`";
                    $pdo->exec($createBackupSql);
                    
                    $insertBackupSql = "INSERT INTO `{$backupTableName}` SELECT * FROM `{$originTableName}`";
                    $pdo->exec($insertBackupSql);
                    
                    $this->printer->success(__('  ✓ 表已复制：%{1} (包含所有数据)', [$backupTableName]));
                } catch (\Exception $copyException) {
                    $this->printer->warning(__('  表复制失败：%{1}', [$copyException->getMessage()]));
                }

                // 删除原表
                $this->printer->note(__('  删除原表：%{1}...', [$originTableName]));
                $modelSetup = ObjectManager::make(ModelSetup::class);
                $modelSetup->putModel($model);
                $modelSetup->dropTable($originTableName);
                $this->printer->success(__('  ✓ 原表已删除：%{1}', [$originTableName]));

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

        // 删除模块
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

        // 删除模块
        unset($dependencies[$moduleName]);

        // 写回文件
        $content = '<?php  return ' . var_export($dependencies, true) . ';';
        file_put_contents($dependenciesFile, $content);
        
        $this->printer->success(__('  ✓ 已从 module_dependencies.php 删除模块 %{1}', [$moduleName]));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('重新安装模块（危险操作，仅限开发模式）。此命令将删除模块的所有数据并重新安装。');
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
                '双重备份保护' => '1) SQL文件备份到 var/backup/db/；2) 数据库中复制表为 {表名}_backup',
                '备份表示例' => '如表 demo 会被复制为 demo_backup，包含所有数据',
            ],
            [
                '重新安装单个模块' => 'php bin/w module:reinstall -m Weline_Demo',
                '重新安装多个模块' => 'php bin/w module:reinstall --module "Weline_Demo Weline_Test"',
            ],
            'php bin/w module:reinstall -m <模块名>'
        );
    }
}

