<?php

declare(strict_types=1);

/**
 * 字面量语句：编译产物里原样出现的文本，不经过 echo。
 *
 * 分开 Text 与 Php 的原因：编译产物是「HTML 文本 + PHP 语句」的混合文件，
 * 若把文本走 EchoStmt 会改变产物结构（也改变 opcache/输出缓冲行为），
 * 与 C2「com_*.phtml 仍是最终产物」冲突。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Ir;

use Weline\Framework\View\Compiler\Lexer\Span;

final class TextStmt implements Stmt
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

    public function span(): Span
    {
        return $this->span;
    }

    public function raw(): string
    {
        return $this->raw;
    }
}
