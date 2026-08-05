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
     * @return list<Module>
     */
    public function getInstallTasks(): array
    {
        return $this->installTasks;
    }

    /**
     * @return list<Module>
     */
    public function getUpgradeTasks(): array
    {
        return $this->upgradeTasks;
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
        
        // 同批先 Install 后 Upgrade（新模块可被同批 upgrade 依赖）
        foreach ($this->installTasks as $module) {
            try {
                $this->executedTasks[] = [
                    'module' => $module->getName(),
                    'type' => 'install',
                ];
                $this->moduleHandle->setupInstall($module);
                $this->hasModuleInstalledOrUpgraded = true;
            } catch (\Exception $e) {
                $this->addError(__('模块 %{1} 安装失败：%{2}', [
                    $module->getName(),
                    $e->getMessage()
                ]));
                $this->rollback();
                throw new Exception(__(
                    '模块安装失败：%{1}。注意：前置 Schema 可能已应用，Setup 脚本仍待重跑（setup_version 未推进）。',
                    [$e->getMessage()]
                ), 0, $e);
            }
        }

        foreach ($this->upgradeTasks as $module) {
            try {
                $this->executedTasks[] = [
                    'module' => $module->getName(),
                    'type' => 'upgrade',
                ];
                $this->moduleHandle->setupUpgrade($module);
                $this->hasModuleInstalledOrUpgraded = true;
            } catch (\Exception $e) {
                $this->addError(__('模块 %{1} 升级失败：%{2}', [
                    $module->getName(),
                    $e->getMessage()
                ]));
                $this->rollback();
                throw new Exception(__(
                    '模块升级失败：%{1}。注意：前置 Schema 可能已应用，Setup 脚本仍待重跑（setup_version 未推进）。',
                    [$e->getMessage()]
                ), 0, $e);
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

        // 已执行的 Install/Upgrade 脚本无法在此原子撤销；仅清理本阶段内存状态。
        // 运维应修复脚本后再次 setup:upgrade（依赖 setup_version 未推进以保留 pending）。
        $this->prepared = false;
        $this->committed = false;
        $this->executedTasks = [];
        $this->hasModuleInstalledOrUpgraded = false;
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
