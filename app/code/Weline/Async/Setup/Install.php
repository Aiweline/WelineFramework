<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Setup;

use Weline\Async\Model\SyncHost;
use Weline\Async\Model\SyncMapping;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Async Module Installation Script
 * 
 * Handles database schema creation and initial directory setup for the Weline_Async module.
 * 
 * @package Weline_Async
 */
class Install implements InstallInterface
{
    /**
     * Execute installation
     * 
     * Creates all required database tables and directories for the Async module.
     * 
     * @param Setup $setup Setup instance
     * @param Context $context Installation context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 创建数据库表
        $this->installDatabaseTables($context);
        
        // 创建运行时目录
        $this->createRuntimeDirectories();
        
        // 检查并安装 Node.js 依赖
        $this->installNodeDependencies();
    }

    /**
     * 安装数据库表
     * 
     * @param Context $context
     * @return void
     */
    private function installDatabaseTables(Context $context): void
    {
        // 安装同步主机表
        /** @var SyncHost $syncHost */
        $syncHost = ObjectManager::getInstance(SyncHost::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($syncHost);
        $syncHost->setup($modelSetup, $context);
        
        // 安装目录映射表
        /** @var SyncMapping $syncMapping */
        $syncMapping = ObjectManager::getInstance(SyncMapping::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($syncMapping);
        $syncMapping->setup($modelSetup, $context);
    }

    /**
     * 创建运行时目录
     * 
     * @return void
     */
    private function createRuntimeDirectories(): void
    {
        $baseDir = BP . DS . 'var' . DS . 'async';
        $dirs = [
            $baseDir,
            $baseDir . DS . 'logs',
            $baseDir . DS . 'pids',
            $baseDir . DS . 'config',
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * 检查并安装 Node.js 依赖
     * 
     * @return void
     */
    private function installNodeDependencies(): void
    {
        // 检查 Node.js 是否已安装
        $nodeVersion = $this->checkNodeInstalled();
        
        if (!$nodeVersion) {
            // Node.js 未安装，记录警告但不阻止安装
            w_log_warning('Weline_Async: Node.js 未安装，无法自动安装依赖。请手动安装 Node.js 后运行: cd ' . BP . 'app/code/Weline/Async/bin && npm install');
            return;
        }

        // Node.js 已安装，检查 npm 是否可用
        $npmVersion = $this->checkNpmInstalled();
        if (!$npmVersion) {
            w_log_warning('Weline_Async: npm 未安装，无法自动安装依赖。请手动运行: cd ' . BP . 'app/code/Weline/Async/bin && npm install');
            return;
        }

        // 检查 package.json 是否存在
        $packageJsonPath = BP . DS . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Async' . DS . 'bin' . DS . 'package.json';
        if (!file_exists($packageJsonPath)) {
            w_log_warning('Weline_Async: package.json 不存在: ' . $packageJsonPath);
            return;
        }

        // 检查 node_modules 是否已存在
        $nodeModulesPath = BP . DS . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Async' . DS . 'bin' . DS . 'node_modules';
        if (is_dir($nodeModulesPath)) {
            // 依赖已安装，跳过
            return;
        }

        // 执行 npm install
        $binDir = BP . DS . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Async' . DS . 'bin';
        $command = 'cd ' . escapeshellarg($binDir) . ' && npm install 2>&1';
        
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0) {
            w_log_info('Weline_Async: Node.js 依赖安装成功');
        } else {
            w_log_error('Weline_Async: Node.js 依赖安装失败: ' . implode("\n", $output));
        }
    }

    /**
     * 检查 Node.js 是否已安装
     * 
     * @return string|null Node.js 版本号，如果未安装返回 null
     */
    private function checkNodeInstalled(): ?string
    {
        $output = [];
        $returnVar = 0;
        exec('node --version 2>&1', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output[0])) {
            return trim($output[0]);
        }
        
        return null;
    }

    /**
     * 检查 npm 是否已安装
     * 
     * @return string|null npm 版本号，如果未安装返回 null
     */
    private function checkNpmInstalled(): ?string
    {
        $output = [];
        $returnVar = 0;
        exec('npm --version 2>&1', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output[0])) {
            return trim($output[0]);
        }
        
        return null;
    }
}
