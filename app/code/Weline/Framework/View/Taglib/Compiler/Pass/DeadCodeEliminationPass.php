<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 死代码消除优化通道
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler\Pass
 */

namespace Weline\Framework\View\Taglib\Compiler\Pass;

use Weline\Framework\View\Taglib\Ast\{
    Node,
    ProgramNode,
    TextNode,
    TagNode,
    NodePool
};

/**
 * 死代码消除优化通道
 * 
 * 移除不会产生输出的代码
 * 
 * 优化示例：
 * - 移除空的文本节点
 * - 移除空的标签
 * - 移除 if(false) 分支
 */
final class DeadCodeEliminationPass implements CompilePassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ProgramNode $ast): ProgramNode
    {
        $optimizedChildren = $this->eliminateDeadCode($ast->children);
        return $ast->withChildren($optimizedChildren);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'dead-code-elimination';
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 20;
    }

    /**
     * 消除死代码
     * 
     * @param array<Node> $nodes
     * @return array<Node>
     */
    private function eliminateDeadCode(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            // 跳过空文本节点
            if ($node instanceof TextNode && $this->isEmptyText($node)) {
                continue;
            }

            // 处理标签节点
            if ($node instanceof TagNode) {
                $optimized = $this->optimizeTagNode($node);
                if ($optimized !== null) {
                    $result[] = $optimized;
                }
                continue;
            }

            // 保留其他节点
            $result[] = $node;
        }

        return $result;
    }

    /**
     * 检查是否为空文本
     */
    private function isEmptyText(TextNode $node): bool
    {
        // 只移除完全为空的文本，保留空白（可能是格式需要）
        return $node->value === '';
    }

    /**
     * 优化标签节点
     * 
     * @return TagNode|null 优化后的节点，如果应该移除返回 null
     */
    private function optimizeTagNode(TagNode $node): ?TagNode
    {
        // 递归处理子节点
        $optimizedChildren = $this->eliminateDeadCode($node->children);

        // 检查 if 标签的静态条件
        if ($node->name === 'if') {
            $condition = $node->getAttr('condition') ?? $node->getAttr('test');
            if ($condition !== null) {
                // 静态 false 条件
                if ($this->isStaticFalse($condition)) {
                    return null;
                }
                // 静态 true 条件可以简化（未来实现）
            }
        }

        // 返回优化后的节点
        if ($optimizedChildren !== $node->children) {
            return $node->withChildren($optimizedChildren);
        }

        return $node;
    }

    /**
     * 检查是否为静态 false
     */
    private function isStaticFalse(string $condition): bool
    {
        $condition = trim(strtolower($condition));
        return $condition === 'false' || $condition === '0' || $condition === '""' || $condition === "''";
    }
}
