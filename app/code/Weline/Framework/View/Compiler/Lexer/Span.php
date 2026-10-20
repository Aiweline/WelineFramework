<?php

declare(strict_types=1);

/**
 * 模板源码中的一个字节区间。
 *
 * 行号 1 起；列号按**字节**从 1 起算（模板以 ASCII 结构为主，报错时再按行回查原文即可）。
 * 之所以不用字符列：Lexer 必须做到「切分 + 重放 == 原文」的字节级无损，
 * 逐字节对齐才能保证 span 与 substr 结果一致。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Lexer;

final class Span
{
    public function __construct(
        public readonly int $start,
        public readonly int $length,
        public readonly int $line,
        public readonly int $column,
    ) {
    }

    public function end(): int
    {
        return $this->start + $this->length;
    }

    public function contains(int $offset): bool
    {
        return $offset >= $this->start && $offset < $this->end();
    }
}
