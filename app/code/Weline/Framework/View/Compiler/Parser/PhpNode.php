<?php

declare(strict_types=1);

/**
 * PHP 节点：一段原样保留的 PHP（含开闭标签）。
 *
 * `code()` 是去掉开标签后的 PHP 代码，供 Resolve/Lower 分析语句；
 * `raw()` 保留原始字节，供 P0 的「树重放 == 原文」校验与逃生舱使用。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Lexer\Span;

final class PhpNode implements Node
{
    public function __construct(
        private readonly string $raw,
        private readonly Span $span,
        private readonly bool $echo,
        private readonly string $openTag,
    ) {
    }

    public function kind(): string
    {
        return 'php';
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function span(): Span
    {
        return $this->span;
    }

    public function isEcho(): bool
    {
        return $this->echo;
    }

    public function openTag(): string
    {
        return $this->openTag;
    }

    /**
     * 去掉开闭标签后的 PHP 代码（短回显片段保留表达式原文）。
     */
    public function code(): string
    {
        $body = \substr($this->raw, \strlen($this->openTag));

        // PHP 会把闭标签与其后第一个换行一起并入 T_CLOSE_TAG，
        // 这里只剥掉「真正的尾部闭标签」，不能按字符集 rtrim ——那会吃掉
        // 代码里合法结尾的 `>`（例如 `echo $a > $b ? 'x' : 'y'` 这类表达式）。
        if (\preg_match('/\?>\r?\n?$/', $body, $matches) === 1) {
            $body = \substr($body, 0, -\strlen($matches[0]));
        }

        return $body;
    }
}
