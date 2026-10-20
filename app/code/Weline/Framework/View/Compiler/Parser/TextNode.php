<?php

declare(strict_types=1);

/**
 * 纯文本节点。P1 起标签扫描只在 `TextNode` 内进行。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Lexer\Span;

final class TextNode implements Node
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
