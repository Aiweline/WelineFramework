<?php

declare(strict_types=1);

/**
 * 模板里一整段 PHP（含开标签与闭标签本身）。
 *
 * `raw()` 是原文切片，必须原样可重放；`isEcho()` 用于下游区分
 * 短回显片段与标准 PHP 块（前者在 Emit 阶段等价于 EchoStmt，
 * 后者要按语句序列处理）。
 *
 * 注意：闭标签之后紧跟的第一个换行在 PHP 词法里属于 T_CLOSE_TAG，
 * 因此它可能被包含在 `raw()` 末尾——这不是丢字节，是 PHP 的真实语义。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Lexer;

final class PhpFragment implements Fragment
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

    /**
     * 是否为短回显片段（问号等号开标签）。
     */
    public function isEcho(): bool
    {
        return $this->echo;
    }

    /**
     * 原始开标签（标准 / 短回显 / 短开标签三种形态之一），供 Emit 决定是否补分号。
     */
    public function openTag(): string
    {
        return $this->openTag;
    }
}
