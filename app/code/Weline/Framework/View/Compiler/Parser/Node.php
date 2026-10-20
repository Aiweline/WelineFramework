<?php

declare(strict_types=1);

/**
 * 语法树节点（v3 第二层产物）。
 *
 * P0 只处理 `Text` 与 `Php` 两类节点；`TagNode` / `InlineNode` 在 P1 接入，
 * 但接口从第一天起就按最终形态定义，避免 P1 回头改所有调用点。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Lexer\Span;

interface Node
{
    /**
     * 节点类型：`text` / `php`（P1 起增加 `tag` / `inline`）。
     */
    public function kind(): string;

    /**
     * 该节点对应的原始字节，用于「树重放 == 原文」的校验。
     */
    public function raw(): string;

    public function span(): Span;
}
