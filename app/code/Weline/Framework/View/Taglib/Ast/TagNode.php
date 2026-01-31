<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST 标签节点
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * 标签节点
 * 
 * 表示框架标签（如 <block>、<lang> 等）
 */
class TagNode extends Node
{
    /**
     * 编译阶段常量
     */
    public const STAGE_COMPILE = 'compile';
    public const STAGE_RUNTIME = 'runtime';

    /**
     * 构造函数
     *
     * @param int $line 源码行号
     * @param string $name 标签名（如 block、lang、if）
     * @param array<AttrNode> $attributes 属性节点列表
     * @param array<Node> $children 子节点列表
     * @param string $rawContent 原始内容（用于某些标签）
     * @param bool $selfClosing 是否自闭合标签
     * @param string $stage 编译阶段
     */
    public function __construct(
        int $line,
        public readonly string $name,
        public readonly array $attributes = [],
        public readonly array $children = [],
        public readonly string $rawContent = '',
        public readonly bool $selfClosing = false,
        public readonly string $stage = self::STAGE_COMPILE,
    ) {
        parent::__construct($line);
    }

    /**
     * Property Hook: 是否包含动态内容
     */
    public bool $isDynamic {
        get => $this->hasDynamicAttrs || $this->hasDynamicChildren;
    }

    /**
     * Property Hook: 是否有动态属性
     */
    public bool $hasDynamicAttrs {
        get => array_any(
            $this->attributes,
            static fn(AttrNode $a): bool => $a->isDynamic
        );
    }

    /**
     * Property Hook: 是否有动态子节点
     */
    public bool $hasDynamicChildren {
        get => array_any(
            $this->children,
            static fn(Node $n): bool => $n->isDynamic
        );
    }

    /**
     * Property Hook: 属性名到属性节点的映射
     */
    public array $attrMap {
        get {
            $map = [];
            foreach ($this->attributes as $attr) {
                $map[$attr->name] = $attr;
            }
            return $map;
        }
    }

    /**
     * Property Hook: 子节点数量
     */
    public int $childCount {
        get => count($this->children);
    }

    /**
     * Property Hook: 是否需要运行期编译
     */
    public bool $isRuntime {
        get => $this->stage === self::STAGE_RUNTIME || $this->hasDynamicAttrs;
    }

    /**
     * 获取属性值
     *
     * @param string $name 属性名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getAttr(string $name, mixed $default = null): mixed
    {
        $attr = $this->attrMap[$name] ?? null;
        if ($attr === null) {
            return $default;
        }
        return $attr->staticValue ?? $default;
    }

    /**
     * 获取属性节点
     */
    public function getAttrNode(string $name): ?AttrNode
    {
        return $this->attrMap[$name] ?? null;
    }

    /**
     * 检查是否有属性
     */
    public function hasAttr(string $name): bool
    {
        return isset($this->attrMap[$name]);
    }

    /**
     * 创建新的 TagNode，替换子节点
     */
    public function withChildren(array $children): self
    {
        return new self(
            $this->line,
            $this->name,
            $this->attributes,
            $children,
            $this->rawContent,
            $this->selfClosing,
            $this->stage
        );
    }

    /**
     * 创建新的 TagNode，替换属性
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            $this->line,
            $this->name,
            $attributes,
            $this->children,
            $this->rawContent,
            $this->selfClosing,
            $this->stage
        );
    }

    /**
     * 创建新的 TagNode，设置阶段
     */
    public function withStage(string $stage): self
    {
        return new self(
            $this->line,
            $this->name,
            $this->attributes,
            $this->children,
            $this->rawContent,
            $this->selfClosing,
            $stage
        );
    }

    /**
     * @inheritDoc
     */
    public function toDebugString(): string
    {
        $attrsStr = implode(', ', array_map(
            static fn(AttrNode $a): string => $a->toDebugString(),
            $this->attributes
        ));
        $childrenStr = implode(', ', array_map(
            static fn(Node $n): string => $n->toDebugString(),
            $this->children
        ));
        return sprintf(
            'Tag[line=%d, stage=%s]<%s%s>(%s){%s}',
            $this->line,
            $this->stage,
            $this->name,
            $this->selfClosing ? '/' : '',
            $attrsStr,
            $childrenStr
        );
    }
}
