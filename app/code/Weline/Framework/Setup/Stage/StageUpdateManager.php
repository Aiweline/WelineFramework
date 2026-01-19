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
use Weline\Framework\Output\Cli\Printing;

/**
 * 阶段更新管理器
 * 
 * 职责：协调所有阶段的执行，确保原子性（要么全部成功，要么全部回滚）
 * 
 * 遵循SOLID原则：
 * - 单一职责：只负责协调阶段执行
 * - 开闭原则：可以添加新阶段而不修改管理器代码
 * - 依赖倒置：依赖阶段接口而非具体实现
 * 
 * @package Weline\Framework\Setup\Stage
 */
class StageUpdateManager
{
    /**
     * @var StageInterface[] 阶段列表
     */
    private array $stages = [];
    
    /**
     * @var Printing 输出对象
     */
    private Printing $printing;
    
    /**
     * @var array 执行顺序
     */
    private array $executionOrder = [];
    
    /**
     * @param Printing $printing
     */
    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }
    
    /**
     * 注册阶段
     * 
     * @param StageInterface $stage 阶段对象
     * @param int|null $order 执行顺序（数字越小越先执行，null表示追加到最后）
     * @return void
     */
    public function registerStage(StageInterface $stage, ?int $order = null): void
    {
        $name = $stage->getName();
        
        if (isset($this->stages[$name])) {
            throw new Exception(__('阶段 %{1} 已注册', [$name]));
        }
        
        $this->stages[$name] = $stage;
        
        if ($order !== null) {
            $this->executionOrder[$name] = $order;
        } else {
            // 如果没有指定顺序，使用当前最大顺序+1
            $maxOrder = empty($this->executionOrder) ? 0 : max($this->executionOrder);
            $this->executionOrder[$name] = $maxOrder + 1;
        }
    }
    
    /**
     * 获取阶段
     * 
     * @param string $name 阶段名称
     * @return StageInterface|null
     */
    public function getStage(string $name): ?StageInterface
    {
        return $this->stages[$name] ?? null;
    }
    
    /**
     * 准备所有阶段
     * 
     * @param array $context 上下文数据
     * @return void
     * @throws Exception
     */
    public function prepareAll(array $context = []): void
    {
        $stages = $this->getStagesInOrder();
        
        foreach ($stages as $stage) {
            try {
                // 如果阶段已经准备过，跳过（避免重复准备导致数据丢失）
                if ($stage->isPrepared()) {
                    $this->printing->note(__('阶段 %{1} 已准备，跳过...', [$stage->getName()]));
                    continue;
                }
                
                $this->printing->note(__('准备阶段：%{1}...', [$stage->getName()]));
                $stage->prepare($context);
                $this->printing->success(__('✓ 阶段 %{1} 准备完成', [$stage->getName()]));
            } catch (\Exception $e) {
                $this->printing->error(__('阶段 %{1} 准备失败：%{2}', [$stage->getName(), $e->getMessage()]));
                throw new Exception(__('阶段 %{1} 准备失败：%{2}', [$stage->getName(), $e->getMessage()]), 0, $e);
            }
        }
    }
    
    /**
     * 验证所有阶段
     * 
     * @return bool
     * @throws Exception
     */
    public function validateAll(): bool
    {
        $stages = $this->getStagesInOrder();
        
        foreach ($stages as $stage) {
            if (!$stage->isPrepared()) {
                throw new Exception(__('阶段 %{1} 尚未准备，无法验证', [$stage->getName()]));
            }
            
            try {
                if (!$stage->validate()) {
                    $errors = $stage->getStatus()['errors'] ?? [];
                    $errorMsg = !empty($errors) ? implode('; ', $errors) : __('验证失败');
                    throw new Exception(__('阶段 %{1} 验证失败：%{2}', [$stage->getName(), $errorMsg]));
                }
            } catch (\Exception $e) {
                $this->printing->error(__('阶段 %{1} 验证失败：%{2}', [$stage->getName(), $e->getMessage()]));
                throw $e;
            }
        }
        
        return true;
    }
    
    /**
     * 提交所有阶段（一次性写入）
     * 
     * @return void
     * @throws Exception
     */
    public function commitAll(): void
    {
        $stages = $this->getStagesInOrder();
        $committedStages = [];
        
        try {
            foreach ($stages as $stage) {
                if (!$stage->isPrepared()) {
                    throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$stage->getName()]));
                }
                
                try {
                    $this->printing->note(__('提交阶段：%{1}...', [$stage->getName()]));
                    $stage->commit();
                    $committedStages[] = $stage;
                    $this->printing->success(__('✓ 阶段 %{1} 提交完成', [$stage->getName()]));
                } catch (\Exception $e) {
                    $this->printing->error(__('阶段 %{1} 提交失败：%{2}', [$stage->getName(), $e->getMessage()]));
                    // 回滚已提交的阶段
                    $this->rollbackCommittedStages($committedStages);
                    throw new Exception(__('阶段 %{1} 提交失败：%{2}', [$stage->getName(), $e->getMessage()]), 0, $e);
                }
            }
        } catch (\Exception $e) {
            // 确保所有已提交的阶段都被回滚
            if (!empty($committedStages)) {
                $this->rollbackCommittedStages($committedStages);
            }
            throw $e;
        }
    }
    
    /**
     * 回滚已提交的阶段
     * 
     * @param StageInterface[] $stages 需要回滚的阶段列表（按提交顺序的逆序）
     * @return void
     */
    private function rollbackCommittedStages(array $stages): void
    {
        // 按逆序回滚（最后提交的先回滚）
        $stages = array_reverse($stages);
        
        foreach ($stages as $stage) {
            try {
                $this->printing->warning(__('回滚阶段：%{1}...', [$stage->getName()]));
                $stage->rollback();
                $this->printing->note(__('✓ 阶段 %{1} 回滚完成', [$stage->getName()]));
            } catch (\Exception $e) {
                // 回滚失败，记录错误但不抛出异常（避免回滚过程中的异常覆盖原始异常）
                $this->printing->error(__('阶段 %{1} 回滚失败：%{2}', [$stage->getName(), $e->getMessage()]));
            }
        }
    }
    
    /**
     * 执行完整的阶段更新流程
     * 
     * @param array $context 上下文数据
     * @return void
     * @throws Exception
     */
    public function execute(array $context = []): void
    {
        try {
            // 1. 准备所有阶段
            $this->prepareAll($context);
            
            // 2. 验证所有阶段
            $this->validateAll();
            
            // 3. 提交所有阶段（一次性写入）
            $this->commitAll();
            
            $this->printing->success(__('✓ 所有阶段更新完成'));
        } catch (\Exception $e) {
            $this->printing->error(__('阶段更新失败：%{1}', [$e->getMessage()]));
            throw $e;
        }
    }
    
    /**
     * 获取按顺序排列的阶段列表
     * 
     * @return StageInterface[]
     */
    private function getStagesInOrder(): array
    {
        $stages = [];
        
        // 按执行顺序排序
        $sortedOrder = $this->executionOrder;
        asort($sortedOrder);
        
        foreach ($sortedOrder as $name => $order) {
            if (isset($this->stages[$name])) {
                $stages[] = $this->stages[$name];
            }
        }
        
        // 添加没有指定顺序的阶段（按注册顺序）
        foreach ($this->stages as $name => $stage) {
            if (!isset($this->executionOrder[$name])) {
                $stages[] = $stage;
            }
        }
        
        return $stages;
    }
    
    /**
     * 获取所有阶段的状态
     * 
     * @return array
     */
    public function getStatus(): array
    {
        $status = [];
        
        foreach ($this->stages as $name => $stage) {
            $status[$name] = $stage->getStatus();
        }
        
        return $status;
    }
    
    /**
     * 清除所有阶段
     * 
     * @return void
     */
    public function clear(): void
    {
        $this->stages = [];
        $this->executionOrder = [];
    }
}
