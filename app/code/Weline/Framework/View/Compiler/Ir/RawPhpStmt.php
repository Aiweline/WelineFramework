<?php

declare(strict_types=1);

/**
 * 逃生舱语句：一段原样输出到产物里的 PHP。
 *
 * 它的存在有明确理由（计划书 §5.1 / R3）：86 个模块标签的现有回调返回的是
 * PHP 源码字符串，适配器阶段必须原样承接，不能假装已经结构化。
 * 每引入一个 `RawPhpStmt` 都要在影子比对里单独签核。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Ir;

use Weline\Framework\View\Compiler\Lexer\Span;

final class RawPhpStmt implements Stmt
{
    public function __construct(
        private readonly string $code,
        private readonly Span $span,
    ) {
    }

    public function kind(): string
    {
        return 'raw_php';
    }

    public function span(): Span
    {
        return $this->span;
    }

    public function code(): string
    {
        return $this->code;
    }
}
