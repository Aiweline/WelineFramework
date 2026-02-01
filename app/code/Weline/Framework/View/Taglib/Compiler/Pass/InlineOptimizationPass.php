<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 内联优化通道
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
 * 内联优化通道
 * 
 * 对运行期标签进行内联优化，减少不必要的 ob_start/ob_get_clean 调用
 * 
 * 优化规则：
 * 1. 运行期自闭合标签（无子内容）：直接调用渲染方法，不需要缓冲区
 * 2. 运行期标签的静态子内容：预先合并为字符串，减少运行时拼接
 * 3. 连续的静态文本节点：合并以减少内存分配
 * 
 * 优先级：60（在死代码消除之后执行）
 */
final class InlineOptimizationPass implements CompilePassInterface
{
    /**
     * 是否启用激进优化
     */
    private bool $aggressive = false;

    public function __construct(bool $aggressive = false)
    {
        $this->aggressive = $aggressive;
    }

    /**
     * @inheritDoc
     */
    public function process(ProgramNode $ast): ProgramNode
    {
        $optimizedChildren = $this->optimizeNodes($ast->children);
        return $ast->withChildren($optimizedChildren);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'inline-optimization';
    }

    /**
     * @inheritDoc
     * 
     * 优先级 60：内联优化，在死代码消除之后执行
     */
    public function getPriority(): int
    {
        return 60;
    }

    /**
     * 优化节点列表
     * 
     * @param array<Node> $nodes
     * @return array<Node>
     */
    private function optimizeNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $optimized = $this->optimizeNode($node);
            if ($optimized !== null) {
                $result[] = $optimized;
            }
        }

        // 合并连续的文本节点
        return $this->mergeConsecutiveTextNodes($result);
    }

    /**
     * 优化单个节点
     * 
     * @return Node|null 优化后的节点，null 表示移除
     */
    private function optimizeNode(Node $node): ?Node
    {
        // 文本节点直接返回
        if ($node instanceof TextNode) {
            return $node;
        }

        // PHP 占位符直接返回
        if ($node instanceof PhpPlaceholder) {
            return $node;
        }

        // 标签节点需要优化
        if ($node instanceof TagNode) {
            return $this->optimizeTagNode($node);
        }

        return $node;
    }

    /**
     * 优化标签节点
     */
    private function optimizeTagNode(TagNode $node): TagNode
    {
        // 递归优化子节点
        $optimizedChildren = $this->optimizeNodes($node->children);

        // 运行期标签优化
        if ($node->stage === TagNode::STAGE_RUNTIME) {
            return $this->optimizeRuntimeTag($node, $optimizedChildren);
        }

        // 返回优化后的节点
        if ($optimizedChildren !== $node->children) {
            return $node->withChildren($optimizedChildren);
        }

        return $node;
    }

    /**
     * 优化运行期标签
     * 
     * 对于运行期标签，检查是否可以进行内联优化：
     * 1. 自闭合标签：标记为可内联，代码生成时避免 ob_start
     * 2. 纯静态子内容：预先合并为字符串
     */
    private function optimizeRuntimeTag(TagNode $node, array $children): TagNode
    {
        // 检查是否为自闭合标签（无子内容）
        if ($node->selfClosing || empty($children)) {
            // 自闭合运行期标签可以直接调用渲染方法，不需要缓冲区
            // 通过 rawContent 标记为已优化（添加前缀）
            $rawContent = $node->rawContent;
            if (!str_starts_with($rawContent, '__INLINE_OPTIMIZED__')) {
                $rawContent = '__INLINE_OPTIMIZED__' . $rawContent;
            }
            
            return new TagNode(
                $node->line,
                $node->name,
                $node->attributes,
                [],
                $rawContent,
                true, // 强制标记为自闭合
                $node->stage
            );
        }

        // 检查子内容是否全部为静态（纯文本节点）
        $allStatic = $this->isAllStaticContent($children);
        
        if ($allStatic && $this->aggressive) {
            // 激进模式：将静态子内容预先合并为单个 rawContent
            $staticContent = $this->extractStaticContent($children);
            if ($staticContent !== null) {
                return new TagNode(
                    $node->line,
                    $node->name,
                    $node->attributes,
                    [], // 清空子节点
                    '__STATIC_CHILDREN__' . $staticContent, // 将子内容移到 rawContent
                    false,
                    $node->stage
                );
            }
        }

        // 返回优化后的节点
        if ($children !== $node->children) {
            return $node->withChildren($children);
        }

        return $node;
    }

    /**
     * 检查节点列表是否全部为静态内容
     */
    private function isAllStaticContent(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!$node instanceof TextNode) {
                return false;
            }
        }
        return true;
    }

    /**
     * 提取静态内容
     */
    private function extractStaticContent(array $nodes): ?string
    {
        $content = '';
        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                $content .= $node->value;
            } else {
                return null;
            }
        }
        return $content;
    }

    /**
     * 合并连续的文本节点
     * 
     * @param array<Node> $nodes
     * @return array<Node>
     */
    private function mergeConsecutiveTextNodes(array $nodes): array
    {
        if (count($nodes) <= 1) {
            return $nodes;
        }

        $result = [];
        $textBuffer = '';
        $textLine = 1;

        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                if ($textBuffer === '') {
                    $textLine = $node->line;
                }
                $textBuffer .= $node->value;
            } else {
                // 刷新文本缓冲区
                if ($textBuffer !== '') {
                    $result[] = NodePool::textNode($textLine, $textBuffer);
                    $textBuffer = '';
                }
                $result[] = $node;
            }
        }

        // 刷新剩余文本
        if ($textBuffer !== '') {
            $result[] = NodePool::textNode($textLine, $textBuffer);
        }

        return $result;
    }

    /**
     * 设置激进优化模式
     */
    public function setAggressive(bool $aggressive): void
    {
        $this->aggressive = $aggressive;
    }

    /**
     * 是否启用激进优化
     */
    public function isAggressive(): bool
    {
        return $this->aggressive;
    }
}
