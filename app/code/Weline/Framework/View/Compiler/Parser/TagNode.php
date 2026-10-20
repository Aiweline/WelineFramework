<?php

declare(strict_types=1);

/**
 * 标签节点。
 *
 * `raw()` 永远是**原文切片**（含开标签、子节点、闭标签），因此「树重放 == 原文」
 * 对含标签的模板同样成立；`children()` 才是结构化视图。
 * P1 起 Resolve 只读结构化字段，Emit 只读 Stmt，两者都不再拼裸字符串。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Lexer\Span;

final class TagNode implements Node
{
    /**
     * @param list<Node> $children
     * @param array<string, string|true> $attributes
     */
    public function __construct(
        private readonly string $name,
        private readonly string $attributesRaw,
        private readonly array $attributes,
        private readonly bool $selfClosing,
        private readonly bool $paired,
        private readonly array $children,
        private readonly string $raw,
        private readonly Span $span,
        private readonly string $openRaw = '',
        private readonly int $legacyLine = 0,
    ) {
    }

    public function kind(): string
    {
        return 'tag';
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function span(): Span
    {
        return $this->span;
    }

    /**
     * 旧 AST 的 `$node->line`：不计 PHP 块内换行（旧 PhpExtractor 把 PHP 换成无换行占位符）。
     * runtime tag 的 children 变量名依赖它，不能直接用源码行号。
     */
    public function legacyLine(): int
    {
        return $this->legacyLine > 0 ? $this->legacyLine : $this->span->line;
    }

    /**
     * 开标签原文（不含子节点与闭标签）。
     *
     * 旧编译器的 `callback()` 拿到的 `$tag_data[0]` 就是它，桥接时必须给同样的值。
     */
    public function openRaw(): string
    {
        return $this->openRaw;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function attributesRaw(): string
    {
        return $this->attributesRaw;
    }

    /**
     * @return array<string, string|true>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function isSelfClosing(): bool
    {
        return $this->selfClosing;
    }

    /**
     * 是否找到了配对的闭标签（自闭合标签恒为 false）。
     */
    public function isPaired(): bool
    {
        return $this->paired;
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return $this->children;
    }
}
