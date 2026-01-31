<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 编译管道
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler
 */

namespace Weline\Framework\View\Taglib\Compiler;

use Weline\Framework\View\Taglib\Ast\ProgramNode;
use Weline\Framework\View\Taglib\Compiler\Pass\CompilePassInterface;

/**
 * 编译管道
 * 
 * 协调多个编译优化通道，按优先级顺序执行
 */
final class CompilePipeline
{
    /**
     * 编译通道列表
     * @var CompilePassInterface[]
     */
    private array $passes = [];

    /**
     * 是否已排序
     */
    private bool $sorted = true;

    /**
     * 添加编译通道
     */
    public function addPass(CompilePassInterface $pass): self
    {
        $this->passes[] = $pass;
        $this->sorted = false;
        return $this;
    }

    /**
     * 移除编译通道
     */
    public function removePass(string $name): self
    {
        $this->passes = array_filter(
            $this->passes,
            static fn(CompilePassInterface $p): bool => $p->getName() !== $name
        );
        return $this;
    }

    /**
     * 执行编译管道
     */
    public function process(ProgramNode $ast): ProgramNode
    {
        $this->sortPasses();

        foreach ($this->passes as $pass) {
            $ast = $pass->process($ast);
        }

        return $ast;
    }

    /**
     * 获取所有通道名称
     * 
     * @return array<string>
     */
    public function getPassNames(): array
    {
        return array_map(
            static fn(CompilePassInterface $p): string => $p->getName(),
            $this->passes
        );
    }

    /**
     * 获取通道数量
     */
    public function count(): int
    {
        return count($this->passes);
    }

    /**
     * 按优先级排序通道
     */
    private function sortPasses(): void
    {
        if ($this->sorted) {
            return;
        }

        usort(
            $this->passes,
            static fn(CompilePassInterface $a, CompilePassInterface $b): int 
                => $a->getPriority() <=> $b->getPriority()
        );

        $this->sorted = true;
    }

    /**
     * 创建默认管道
     */
    public static function createDefault(): self
    {
        $pipeline = new self();
        
        // 添加默认优化通道
        $pipeline->addPass(new Pass\ConstantFoldingPass());
        $pipeline->addPass(new Pass\DeadCodeEliminationPass());
        
        return $pipeline;
    }
}
