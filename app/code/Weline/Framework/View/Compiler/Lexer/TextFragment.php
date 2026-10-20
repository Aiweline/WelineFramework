<?php

declare(strict_types=1);

/**
 * 模板里的纯文本片段（PHP 标签之外的一切字节）。
 *
 * 只有这一层才允许扫描标签起始 `<`：PHP 片段原样保留，
 * 所以字符串里的 `<div>`、注释里的 `@static(...)` 都不会被误伤。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Lexer;

final class TextFragment implements Fragment
{
    public function __construct(
        private readonly string $raw,
        private readonly Span $span,
    ) {
    }

    public function kind(): string
    {
        return 'text';
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function span(): Span
    {
        return $this->span;
    }
}
