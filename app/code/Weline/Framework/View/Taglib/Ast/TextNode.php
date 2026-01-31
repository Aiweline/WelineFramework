<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST 文本节点
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * 文本节点
 * 
 * 表示模板中的纯文本内容
 */
class TextNode extends Node
{
    /**
     * 构造函数
     *
     * @param int $line 源码行号
     * @param string $value 文本内容
     */
    public function __construct(
        int $line,
        public readonly string $value,
    ) {
        parent::__construct($line);
    }

    /**
     * Property Hook: 文本节点始终非动态
     */
    public bool $isDynamic {
        get => false;
    }

    /**
     * Property Hook: 是否为空白文本
     */
    public bool $isWhitespace {
        get => trim($this->value) === '';
    }

    /**
     * Property Hook: 文本长度
     */
    public int $length {
        get => strlen($this->value);
    }

    /**
     * @inheritDoc
     */
    public function toDebugString(): string
    {
        $preview = strlen($this->value) > 30 
            ? substr($this->value, 0, 30) . '...' 
            : $this->value;
        $preview = str_replace(["\n", "\r", "\t"], ['\\n', '\\r', '\\t'], $preview);
        return sprintf('Text[line=%d]("%s")', $this->line, $preview);
    }
}
