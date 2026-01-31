<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST 程序根节点
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * 程序根节点
 * 
 * 包含所有子节点的根容器
 */
class ProgramNode extends Node
{
    /**
     * 构造函数
     *
     * @param int $line 源码行号
     * @param array<Node> $children 子节点列表
     * @param string $fileName 源文件名
     */
    public function __construct(
        int $line,
        public readonly array $children = [],
        public readonly string $fileName = '',
    ) {
        parent::__construct($line);
    }

    /**
     * Property Hook: 是否包含动态内容
     */
    public bool $isDynamic {
        get => array_any(
            $this->children,
            static fn(Node $node): bool => $node->isDynamic
        );
    }

    /**
     * Property Hook: 子节点数量
     */
    public int $childCount {
        get => count($this->children);
    }

    /**
     * 创建新的 ProgramNode，替换子节点
     */
    public function withChildren(array $children): self
    {
        return new self($this->line, $children, $this->fileName);
    }

    /**
     * @inheritDoc
     */
    public function toDebugString(): string
    {
        $childStrings = array_map(
            static fn(Node $n): string => $n->toDebugString(),
            $this->children
        );
        return sprintf(
            "Program[line=%d, file=%s, children=%d](\n  %s\n)",
            $this->line,
            $this->fileName ?: '(inline)',
            $this->childCount,
            implode(",\n  ", $childStrings)
        );
    }
}
