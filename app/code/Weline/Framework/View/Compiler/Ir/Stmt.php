<?php

declare(strict_types=1);

/**
 * 语句（v3 第四层 Lower 的产物）。
 *
 * Emitter 只认 Stmt，不再认字符串拼接。P0 只有两种：
 * - `TextStmt`：字面量字节，原样输出（不是 echo，编译产物里 HTML 就是 HTML）；
 * - `RawPhpStmt`：逃生舱，原样输出一段 PHP（模块标签回调的现有形态由此承接）。
 *
 * P1+ 会增加 `EchoStmt` / `IfStmt` / `ForeachStmt` / `CaptureStmt` / `IncludeStmt`，
 * 它们的闭合由树结构保证，而不是拼字符串。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Ir;

use Weline\Framework\View\Compiler\Lexer\Span;

interface Stmt
{
    public function kind(): string;

    public function span(): Span;
}
