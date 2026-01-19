<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;

/**
 * 模块安装/升级阶段
 * 
 * 职责：批量处理所有模块的安装和升级操作
 * 
 * @package Weline\Framework\Setup\Stage
 */
class ModuleSetupStage extends AbstractStage
{
    /**
     * @var Handle 模块处理器
     */
    private Handle $moduleHandle;
    
    /**
     * @var array 待安装的模块 [Module, ...]
     */
    private array $installTasks = [];
    
    /**
     * @var array 待升级的模块 [Module, ...]
     */
    private array $upgradeTasks = [];
    
    /**
     * @var array 已执行的安装/升级记录（用于回滚）
     */
    private array $executedTasks = [];
    
    /**
     * @var bool 是否有模块被安装或升级
     */
    private bool $hasModuleInstalledOrUpgraded = false;
    
    /**
     * @param Handle $moduleHandle
     */
    public function __construct(Handle $moduleHandle)
    {
        $this->moduleHandle = $moduleHandle;
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'module_setup';
    }
    
    /**
     * 添加安装任务
     * 
     * @param Module $module 模块对象
     * @return void
     */
    public function addInstallTask(Module $module): void
    {
        $this->installTasks[] = $module;
    }
    
    /**
     * 添加升级任务
     * 
     * @param Module $module 模块对象
     * @return void
     */
    public function addUpgradeTask(Module $module): void
    {
        $this->upgradeTasks[] = $module;
    }
    
    /**
     * 检查是否有模块被安装或升级
     * 
     * @return bool
     */
    public function hasModuleInstalledOrUpgraded(): bool
    {
        return $this->hasModuleInstalledOrUpgraded;
    }
    
    /**
     * @inheritDoc
     */
    public function prepare(array $context = []): void
    {
        // 如果已经准备过，跳过（避免重复准备）
        if ($this->prepared) {
            return;
        }
        
        // 模块安装/升级阶段不需要特殊的准备操作
        // 安装/升级任务已经在 addInstallTask/addUpgradeTask 时添加
        $this->prepared = true;
        $this->clearErrors();
    }
    
    /**
     * @inheritDoc
     */
    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }
        
        // 验证所有安装/升级任务
        foreach ($this->installTasks as $module) {
            if (!$module instanceof Module) {
                $this->addError(__('无效的安装模块对象'));
                return false;
            }
        }
        
        foreach ($this->upgradeTasks as $module) {
            if (!$module instanceof Module) {
                $this->addError(__('无效的升级模块对象'));
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        if (!$this->prepared) {
            throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$this->getName()]));
        }
        
        if ($this->committed) {
            // 已经提交过，跳过
            return;
        }
        
        // 先执行所有升级任务（升级应该在安装之前）
        foreach ($this->upgradeTasks as $module) {
            try {
                // 记录执行前的状态（用于回滚）
                $this->executedTasks[] = [
                    'module' => $module->getName(),
                    'type' => 'upgrade',
                ];
                
                // 执行模块升级
                $this->moduleHandle->setupUpgrade($module);
                $this->hasModuleInstalledOrUpgraded = true;
            } catch (\Exception $e) {
                $this->addError(__('模块 %{1} 升级失败：%{2}', [
                    $module->getName(),
                    $e->getMessage()
                ]));
                
                // 回滚已执行的任务
                $this->rollback();
                throw new Exception(__('模块升级失败：%{1}', [$e->getMessage()]), 0, $e);
            }
        }
        
        // 再执行所有安装任务
        foreach ($this->installTasks as $module) {
            try {
                // 记录执行前的状态（用于回滚）
                $this->executedTasks[] = [
                    'module' => $module->getName(),
                    'type' => 'install',
                ];
                
                // 执行模块安装
                $this->moduleHandle->setupInstall($module);
                $this->hasModuleInstalledOrUpgraded = true;
            } catch (\Exception $e) {
                $this->addError(__('模块 %{1} 安装失败：%{2}', [
                    $module->getName(),
                    $e->getMessage()
                ]));
                
                // 回滚已执行的任务
                $this->rollback();
                throw new Exception(__('模块安装失败：%{1}', [$e->getMessage()]), 0, $e);
            }
        }
        
        $this->committed = true;
        $this->clearErrors();
    }
    
    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        if (!$this->prepared) {
            return;
        }
        
        // 注意：模块安装/升级的回滚比较复杂，因为：
        // 1. 安装脚本可能创建了数据库表、文件等
        // 2. 升级脚本可能修改了数据结构
        // 
        // 这里我们只记录回滚操作，实际的回滚应该由：
        // 1. 模块的 Remove 脚本处理（卸载）
        // 2. 数据库迁移系统处理（数据回滚）
        // 3. 文件系统备份恢复
        
        // 按逆序回滚（最后执行的最先回滚）
        $executedTasks = array_reverse($this->executedTasks);
        
        foreach ($executedTasks as $task) {
            // 这里可以添加回滚逻辑
            // 例如：如果是安装，可以调用卸载脚本
            // 如果是升级，可以尝试恢复到之前的版本
        }
        
        $this->prepared = false;
        $this->committed = false;
        $this->executedTasks = [];
        $this->hasModuleInstalledOrUpgraded = false;
        
        // 注意：这里不清空 installTasks 和 upgradeTasks，因为可能需要在回滚后重新准备
    }
    
    /**
     * 获取待处理的任务数量
     * 
     * @return int
     */
    public function getTaskCount(): int
    {
        return count($this->installTasks) + count($this->upgradeTasks);
    }
    
    /**
     * 清除所有任务
     * 
     * @return void
     */
    public function clearTasks(): void
    {
        $this->installTasks = [];
        $this->upgradeTasks = [];
        $this->executedTasks = [];
        $this->prepared = false;
        $this->committed = false;
        $this->hasModuleInstalledOrUpgraded = false;
    }
}
