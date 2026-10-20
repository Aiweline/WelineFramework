<?php

declare(strict_types=1);

/**
 * 词法片段。
 *
 * v3 的 Lexer 只做切分、不做替换：把模板源码切成 Text / Php 片段后，
 * 逐片段的 `raw()` 顺序拼接必须**字节等于**原文（P0 退出判据）。
 * 这样下游 Parser 就能把 PHP 当一等公民，不再需要 `PhpExtractor`
 * 占位符往返，也不再需要 `Taglib::isOffsetInsidePhp()` 这类守卫。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Lexer;

interface Fragment
{
    /**
     * 片段类型：`text` 或 `php`。
     */
    public function kind(): string;

    /**
     * 原始字节（含 PHP 开/闭标签），必须被无损保留。
     */
    public function raw(): string;

    public function span(): Span;
}
