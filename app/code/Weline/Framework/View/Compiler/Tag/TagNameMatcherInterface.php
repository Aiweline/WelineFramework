<?php

declare(strict_types=1);

/**
 * 标签名判定器（按**语法形态**分开判定）。
 *
 * 这是 P1 第一个真实踩坑换来的接口：只按名字判定会把 HTML `<meta charset="utf-8">`
 * 当成模板标签 `meta`（仓内确实有一个 `Weline_Meta` 标签库，但它只支持
 * `@meta{...}` 内联形态，不支持 `<meta>` 元素形态）。若名字集合不分形态，
 * 解析阶段就会把普通 HTML 拐进结构树，等于在 Parser 里埋一个静默改写的坑。
 *
 * 因此判定权必须能回答两个问题：
 * - `matchesElement()`：`<name ...>` / `<name .../>` 这种元素形态是否成立；
 * - `matchesInline()`：`@name(...)` / `@name{...}` 这种内联形态是否成立。
 *
 * 具体名单由调用方注入（编译索引或 Registry），Parser 不维护第二份清单（G1）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

interface TagNameMatcherInterface
{
    /**
     * 元素形态：`<name ...>`、`<name .../>`、`<name>...</name>`。
     */
    public function matchesElement(string $name): bool;

    /**
     * 内联形态：`@name(...)`、`@name{...}`。
     */
    public function matchesInline(string $name): bool;
}
