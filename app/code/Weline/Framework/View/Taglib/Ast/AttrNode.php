<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST 属性节点
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * 属性节点
 * 
 * 表示标签属性（name="value"）
 */
class AttrNode extends Node
{
    /**
     * 构造函数
     *
     * @param int $line 源码行号
     * @param string $name 属性名
     * @param string|array<Node> $value 属性值（字符串或节点数组）
     * @param string $rawValue 原始属性值字符串
     */
    public function __construct(
        int $line,
        public readonly string $name,
        public readonly string|array $value,
        public readonly string $rawValue = '',
    ) {
        parent::__construct($line);
    }

    /**
     * Property Hook: 是否为动态属性
     */
    public bool $isDynamic {
        get {
            if (is_string($this->value)) {
                return false;
            }
            return array_any(
                $this->value,
                static fn(Node $n): bool => $n instanceof PhpPlaceholder
            );
        }
    }

    /**
     * Property Hook: 是否为纯文本值
     */
    public bool $isStatic {
        get => !$this->isDynamic;
    }

    /**
     * Property Hook: 获取静态值（仅当非动态时有效）
     */
    public ?string $staticValue {
        get {
            if (is_string($this->value)) {
                return $this->value;
            }
            if ($this->isDynamic) {
                return null;
            }
            // 所有节点都是 TextNode
            return implode('', array_map(
                static fn(Node $n): string => $n instanceof TextNode ? $n->value : '',
                $this->value
            ));
        }
    }

    /**
     * @inheritDoc
     */
    public function toDebugString(): string
    {
        if (is_string($this->value)) {
            return sprintf('Attr[line=%d](%s="%s")', $this->line, $this->name, $this->value);
        }
        $valueStr = implode(', ', array_map(
            static fn(Node $n): string => $n->toDebugString(),
            $this->value
        ));
        return sprintf('Attr[line=%d](%s=[%s])', $this->line, $this->name, $valueStr);
    }
}
