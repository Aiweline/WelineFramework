<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 编译通道接口
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler\Pass
 */

namespace Weline\Framework\View\Taglib\Compiler\Pass;

use Weline\Framework\View\Taglib\Ast\ProgramNode;

/**
 * 编译通道接口
 * 
 * 实现此接口以创建自定义编译优化
 */
interface CompilePassInterface
{
    /**
     * 处理 AST
     * 
     * @param ProgramNode $ast 输入 AST
     * @return ProgramNode 处理后的 AST
     */
    public function process(ProgramNode $ast): ProgramNode;

    /**
     * 获取通道名称
     */
    public function getName(): string;

    /**
     * 获取通道优先级（数字越小越先执行）
     */
    public function getPriority(): int;
}
