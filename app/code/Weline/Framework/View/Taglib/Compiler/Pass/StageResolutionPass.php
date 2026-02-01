<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 阶段解析优化通道
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler\Pass
 */

namespace Weline\Framework\View\Taglib\Compiler\Pass;

use Weline\Framework\View\Taglib\Ast\{
    Node,
    ProgramNode,
    TagNode,
    NodePool
};
use Weline\Framework\View\Taglib\Compiler\StageResolver;
use Weline\Framework\View\Taglib\Registry\TagRegistry;

/**
 * 阶段解析优化通道
 * 
 * 遍历 AST 并根据 TagDefinition 元数据设置每个 TagNode 的编译阶段
 * 
 * 优先级：5（在其他优化通道之前执行）
 */
final class StageResolutionPass implements CompilePassInterface
{
    /**
     * 阶段解析器
     */
    private readonly StageResolver $resolver;

    public function __construct(?StageResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new StageResolver(new TagRegistry());
    }

    /**
     * @inheritDoc
     */
    public function process(ProgramNode $ast): ProgramNode
    {
        $resolvedChildren = $this->resolveNodes($ast->children);
        return $ast->withChildren($resolvedChildren);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'stage-resolution';
    }

    /**
     * @inheritDoc
     * 
     * 优先级 5：预处理阶段，在其他优化通道之前执行
     */
    public function getPriority(): int
    {
        return 5;
    }

    /**
     * 解析节点列表的编译阶段
     * 
     * @param array<Node> $nodes
     * @return array<Node>
     */
    private function resolveNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $result[] = $this->resolveNode($node);
        }

        return $result;
    }

    /**
     * 解析单个节点的编译阶段
     */
    private function resolveNode(Node $node): Node
    {
        // 只处理标签节点
        if (!$node instanceof TagNode) {
            return $node;
        }

        // 解析阶段
        $stage = $this->resolver->resolve($node);

        // 递归处理子节点
        $resolvedChildren = $this->resolveNodes($node->children);

        // 创建新的 TagNode，设置阶段和子节点
        if ($resolvedChildren !== $node->children || $stage !== $node->stage) {
            return new TagNode(
                $node->line,
                $node->name,
                $node->attributes,
                $resolvedChildren,
                $node->rawContent,
                $node->selfClosing,
                $stage
            );
        }

        return $node;
    }

    /**
     * 获取阶段解析器
     */
    public function getResolver(): StageResolver
    {
        return $this->resolver;
    }
}
