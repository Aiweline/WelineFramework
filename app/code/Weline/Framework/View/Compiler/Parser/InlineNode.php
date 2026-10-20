<?php

declare(strict_types=1);

/**
 * 内联标签节点：`@lang{...}`、`@url(...)`、`@if{cond=>'x'}` 这类。
 *
 * 与元素标签的区别：内联形态是**自包含**的（没有配对闭标签），
 * 所以本节点没有 children，只有 body 原文。
 *
 * `raw()` 仍是原文切片，保证「树重放 == 原文」对含内联标签的模板同样成立。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Lexer\Span;

final class InlineNode implements Node
{
    /**
     * @param bool $quoted 是否被成对引号包住（旧编译器对 `'@lang(词)'` 会消费引号并切到
     *                     属性标记模式；节点 raw 含引号，bodyRaw 不含）
     */
    public function __construct(
        private readonly string $name,
        private readonly string $delimiter,
        private readonly string $bodyRaw,
        private readonly string $raw,
        private readonly Span $span,
        private readonly bool $quoted = false,
    ) {
    }

    public function kind(): string
    {
        return 'inline';
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function span(): Span
    {
        return $this->span;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * 定界符：`(` 或 `{`。
     */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * 定界符之间的原文（未去空白、未做任何解析）。
     */
    public function bodyRaw(): string
    {
        return $this->bodyRaw;
    }

    public function quoted(): bool
    {
        return $this->quoted;
    }
}
