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
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Setup\Data\Context;

/**
 * 数据库更新阶段
 * 
 * 职责：批量收集所有模型的更新操作，最后一次性执行
 * 
 * @package Weline\Framework\Setup\Stage
 */
class DatabaseUpdateStage extends AbstractStage
{
    /**
     * @var ModelManager 模型管理器
     */
    private ModelManager $modelManager;
    
    /**
     * @var array 待更新的模型任务 [['module' => Module, 'context' => Context, 'type' => string], ...]
     */
    private array $updateTasks = [];
    
    /**
     * @var array 已执行的更新记录（用于回滚）
     */
    private array $executedUpdates = [];
    
    /**
     * @param ModelManager $modelManager
     */
    public function __construct(ModelManager $modelManager)
    {
        $this->modelManager = $modelManager;
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'database_update';
    }
    
    /**
     * 添加模型更新任务
     * 
     * @param Module $module 模块对象
     * @param Context $context 上下文
     * @param string $type 更新类型 (install|upgrade|setup)
     * @return void
     */
    public function addUpdateTask(Module $module, Context $context, string $type): void
    {
        if (!in_array($type, ['install', 'upgrade', 'setup'])) {
            throw new Exception(__('无效的更新类型：%{1}，允许的值：install, upgrade, setup', [$type]));
        }
        
        $this->updateTasks[] = [
            'module' => $module,
            'context' => $context,
            'type' => $type,
        ];
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
        
        // 数据库更新阶段不需要特殊的准备操作
        // 更新任务已经在 addUpdateTask 时添加
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
        
        // 验证所有更新任务
        foreach ($this->updateTasks as $task) {
            $module = $task['module'];
            $type = $task['type'];
            
            if (!$module instanceof Module) {
                $this->addError(__('无效的模块对象'));
                return false;
            }
            
            if (!in_array($type, ['install', 'upgrade', 'setup'])) {
                $this->addError(__('模块 %{1} 的更新类型无效：%{2}', [$module->getName(), $type]));
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
        
        // 批量执行所有更新任务
        foreach ($this->updateTasks as $task) {
            $module = $task['module'];
            $context = $task['context'];
            $type = $task['type'];
            
            try {
                // 记录执行前的状态（用于回滚）
                $this->executedUpdates[] = [
                    'module' => $module->getName(),
                    'type' => $type,
                    'context' => $context,
                ];
                
                // 执行模型更新
                $this->modelManager->update($module, $context, $type);
            } catch (\Exception $e) {
                $this->addError(__('模块 %{1} 的 %{2} 更新失败：%{3}', [
                    $module->getName(),
                    $type,
                    $e->getMessage()
                ]));
                
                // 回滚已执行的更新
                $this->rollback();
                throw new Exception(__('数据库更新失败：%{1}', [$e->getMessage()]), 0, $e);
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
        
        // 注意：数据库回滚比较复杂，因为：
        // 1. 表结构变更可能无法完全回滚
        // 2. 数据变更的回滚需要记录原始数据
        // 
        // 这里我们只记录回滚操作，实际的回滚应该由数据库事务或迁移系统处理
        // 
        // 对于生产环境，建议：
        // 1. 使用数据库事务（如果数据库支持）
        // 2. 在更新前创建数据库备份
        // 3. 使用迁移系统记录变更，支持回滚
        
        $this->prepared = false;
        $this->committed = false;
        $this->executedUpdates = [];
        
        // 注意：这里不清空 updateTasks，因为可能需要在回滚后重新准备
    }
    
    /**
     * 获取待更新的任务数量
     * 
     * @return int
     */
    public function getTaskCount(): int
    {
        return count($this->updateTasks);
    }
    
    /**
     * 清除所有更新任务
     * 
     * @return void
     */
    public function clearTasks(): void
    {
        $this->updateTasks = [];
        $this->executedUpdates = [];
        $this->prepared = false;
        $this->committed = false;
    }
}
