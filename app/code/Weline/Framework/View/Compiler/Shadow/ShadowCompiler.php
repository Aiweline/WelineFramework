<?php

declare(strict_types=1);

/**
 * 影子编译骨架（计划书 §6.1）。
 *
 * 目前 v3 只覆盖 Text/Php，所以「影子」= 把源码走完整条
 * Lexer → Parser → Lower → Emitter 后与原文对比。
 *
 * 它的用途有两个，且都不依赖旧编译器：
 * 1. **回归护栏**：P1 起每个新增节点/语句都必须保持 `ok()`，否则不允许进 Resolve；
 * 2. **性能取样**：`duration_us` 就是 `taglib:bench` 的口径来源（进程内预热后取中位数）。
 *
 * 与真实影子编译的差别：不落盘、不渲染、不比对 HTML。落地顺序见
 * `dev/session/template-engine-v3/PLAN.md` §4（P1 起接 `template.engine.shadow`）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Shadow;

use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;

final class ShadowCompiler
{
    public function __construct(
        private readonly Lexer $lexer = new Lexer(),
        private readonly ?Parser $parser = null,
        private readonly ?Lower $lower = null,
        private readonly ?Emitter $emitter = null,
    ) {
    }

    public function compile(string $source): ShadowResult
    {
        $startedAt = \microtime(true);

        $fragments = $this->lexer->tokenize($source);
        $parser = $this->parser ?? new Parser($this->lexer);
        $document = $parser->parseFragments($fragments, $source);
        $statements = ($this->lower ?? new Lower())->lower($document);
        $output = ($this->emitter ?? new Emitter())->emit($statements);

        return new ShadowResult(
            $source,
            $output,
            \count($fragments),
            \count($document->nodes()),
            \count($statements),
            (\microtime(true) - $startedAt) * 1_000_000,
        );
    }
}
