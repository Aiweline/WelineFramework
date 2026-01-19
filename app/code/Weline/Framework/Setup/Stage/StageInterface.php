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

/**
 * 阶段更新接口
 * 
 * 遵循单一职责原则：每个阶段只负责一种类型的更新
 * 遵循开闭原则：可以扩展新的阶段类型而不修改现有代码
 * 
 * @package Weline\Framework\Setup\Stage
 */
interface StageInterface
{
    /**
     * 准备阶段数据（在内存中收集，不写入文件或数据库）
     * 
     * @param array $context 上下文数据
     * @return void
     * @throws Exception
     */
    public function prepare(array $context = []): void;
    
    /**
     * 验证阶段数据
     * 
     * @return bool 验证是否通过
     * @throws Exception 验证失败时抛出异常
     */
    public function validate(): bool;
    
    /**
     * 提交阶段更改（一次性写入文件或数据库）
     * 
     * @return void
     * @throws Exception 提交失败时抛出异常
     */
    public function commit(): void;
    
    /**
     * 回滚阶段更改（如果提交失败，恢复原始状态）
     * 
     * @return void
     */
    public function rollback(): void;
    
    /**
     * 获取阶段名称
     * 
     * @return string
     */
    public function getName(): string;
    
    /**
     * 检查阶段是否已准备
     * 
     * @return bool
     */
    public function isPrepared(): bool;
    
    /**
     * 检查阶段是否已提交
     * 
     * @return bool
     */
    public function isCommitted(): bool;
    
    /**
     * 获取阶段状态信息
     * 
     * @return array
     */
    public function getStatus(): array;
}
