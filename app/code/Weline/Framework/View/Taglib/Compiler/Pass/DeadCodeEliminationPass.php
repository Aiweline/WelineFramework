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
    PhpPlaceholder,
    TagNode,
    NodePool
};

/**
 * 死代码消除优化通道
 * 
 * 移除不会产生输出的代码
 * 
 * 优化规则：
 * 1. 移除完全为空的文本节点
 * 2. 移除 if(false) 分支
 * 3. 简化 if(true) 分支（只保留子内容）
 * 4. 移除空的自闭合标签（无属性且无副作用）
 * 5. 移除空内容的成对标签（无子内容且无副作用）
 * 
 * 优先级：20（死代码消除阶段）
 */
final class DeadCodeEliminationPass implements CompilePassInterface
{
    /**
     * 有副作用的标签列表（不应移除）
     */
    private const SIDE_EFFECT_TAGS = [
        'php', 'include', 'template', 'block', 'hook', 'csrf', 'method',
        'push', 'stack', 'section', 'yield', 'extends', 'slot', 'component',
    ];

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
     * 
     * 优先级 20：死代码消除阶段
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
                if ($optimized === null) {
                    // 标签被移除
                    continue;
                }
                if (is_array($optimized)) {
                    // 标签被展开为多个节点（如 if(true) 简化）
                    foreach ($optimized as $child) {
                        $result[] = $child;
                    }
                    continue;
                }
                $result[] = $optimized;
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
     * @return TagNode|array<Node>|null 优化后的节点、节点数组、或 null（移除）
     */
    private function optimizeTagNode(TagNode $node): TagNode|array|null
    {
        // 递归处理子节点
        $optimizedChildren = $this->eliminateDeadCode($node->children);

        // 检查 if 标签的静态条件
        if ($node->name === 'if') {
            $condition = $node->getAttr('condition') ?? $node->getAttr('test') ?? $node->getAttr('value');
            if ($condition !== null) {
                // 静态 false 条件：移除整个 if 块
                if ($this->isStaticFalse($condition)) {
                    return null;
                }
                // 静态 true 条件：简化为只保留子内容
                if ($this->isStaticTrue($condition)) {
                    return $optimizedChildren;
                }
            }
        }

        // 检查空的自闭合标签（无副作用）
        if ($node->selfClosing && empty($node->attributes) && !$this->hasSideEffect($node)) {
            return null;
        }

        // 检查空内容的成对标签（无子内容且无副作用）
        if (!$node->selfClosing && empty($optimizedChildren) && !$this->hasSideEffect($node)) {
            // 仅当没有任何有意义内容时移除
            if ($node->rawContent === '' && empty($node->attributes)) {
                return null;
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
        return in_array($condition, ['false', '0', '""', "''", 'null', '!true'], true);
    }

    /**
     * 检查是否为静态 true
     */
    private function isStaticTrue(string $condition): bool
    {
        $condition = trim(strtolower($condition));
        return in_array($condition, ['true', '1', '!false', '!null', '!0'], true);
    }

    /**
     * 检查标签是否有副作用
     */
    private function hasSideEffect(TagNode $node): bool
    {
        return in_array($node->name, self::SIDE_EFFECT_TAGS, true);
    }
}
