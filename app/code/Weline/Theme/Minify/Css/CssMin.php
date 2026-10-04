<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Minify\Css;

/**
 * Conservative CSS minifier: strip block comments and collapse whitespace.
 * Preserves content inside strings and url(...).
 */
final class CssMin
{
    /**
     * 其后空白冗余的字符：`{ } ; , : > ~ = ! ( [ /`
     *
     * 不含 `)` 与 `]` —— `translate(a) rotate(b)`、`.a[href] .b` 的后代空白必须保留。
     * 不含 `+` `-` —— 由 spaceCarriesMeaning() 的数学规则单独处理。
     */
    private const SPACE_DROPPABLE_AFTER = '{};,:>~=!([/';

    /**
     * 其前空白冗余的字符：`{ } ; , > ~ = ! ) ] /`
     *
     * 不含 `(` —— `@media (a) and (b)` 的 `and (` 必须保留空格，否则 `and(` 会被词法分析成函数 token。
     * 不含 `[` —— `.a [href]` 的后代空白必须保留。
     * 不含 `:` —— `.a :hover` 的后代空白必须保留。
     */
    private const SPACE_DROPPABLE_BEFORE = '{};,>~=!)]/';

    public static function minify(string $css): string
    {
        if ($css === '') {
            return '';
        }

        $length = strlen($css);
        $out = '';
        $i = 0;
        $inSingle = false;
        $inDouble = false;
        $inUrl = false;

        while ($i < $length) {
            $char = $css[$i];
            $next = $i + 1 < $length ? $css[$i + 1] : '';

            if ($inSingle) {
                $out .= $char;
                if ($char === '\\' && $next !== '') {
                    $out .= $next;
                    $i += 2;
                    continue;
                }
                if ($char === "'") {
                    $inSingle = false;
                }
                $i++;
                continue;
            }

            if ($inDouble) {
                $out .= $char;
                if ($char === '\\' && $next !== '') {
                    $out .= $next;
                    $i += 2;
                    continue;
                }
                if ($char === '"') {
                    $inDouble = false;
                }
                $i++;
                continue;
            }

            if ($inUrl) {
                $out .= $char;
                if ($char === ')') {
                    $inUrl = false;
                }
                $i++;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $length && !($css[$i] === '*' && $css[$i + 1] === '/')) {
                    $i++;
                }
                $i = min($i + 2, $length);
                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                $out .= $char;
                $i++;
                continue;
            }

            if ($char === '"') {
                $inDouble = true;
                $out .= $char;
                $i++;
                continue;
            }

            if (($char === 'u' || $char === 'U')
                && $i + 3 < $length
                && strcasecmp(substr($css, $i, 4), 'url(') === 0
            ) {
                $out .= substr($css, $i, 4);
                $i += 4;
                $inUrl = true;
                continue;
            }

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f") {
                $prev = $out !== '' ? $out[strlen($out) - 1] : '';
                $j = $i + 1;
                while ($j < $length && ctype_space($css[$j])) {
                    $j++;
                }
                $following = $j < $length ? $css[$j] : '';
                if ($prev !== '' && $following !== '' && self::spaceCarriesMeaning($prev, $following)) {
                    $out .= ' ';
                }
                $i = $j;
                continue;
            }

            $out .= $char;
            $i++;
        }

        return trim($out);
    }

    /**
     * 空白是否承载语义（true = 必须保留一个空格）。
     *
     * 保守策略：**两个 token 之间的空白一律收敛为一个空格**，只有在能证明冗余时才删除。
     * 旧实现相反 —— 默认删除、仅当「两侧都不是分隔符」才保留 —— 会把空格当成可随意丢弃的
     * 填充物，从而静默产出无效 CSS：
     *
     *   minmax(0, 1fr) minmax(0, 1fr)   → minmax(0,1fr)minmax(0,1fr)  值无效，整条 grid 作废
     *   translate(1px, 2px) rotate(45deg) → translate(1px,2px)rotate(45deg)  函数列表失效
     *   url(a.png) no-repeat center     → url(a.png)no-repeat center   值失效
     *   .card:not(.x) .body             → .card:not(.x).body           后代选择器变复合，语义改变
     *   .a [href]                       → .a[href]                     后代选择器变复合，语义改变
     *   .a :hover                       → .a:hover                     后代选择器变复合，语义改变
     *   @media (a) and (b)              → @media(a)and(b)              `and(` 变函数 token，媒体查询失效
     *
     * 因此 ( ) [ ] 不能作为「空白可丢」边界；`:` 只在**其后**可丢（`color: red` → `color:red`），
     * 其前不可丢（`.a :hover` 必须保空格）。`+` / `-` 一律先按数学表达式规则保白。
     */
    private static function spaceCarriesMeaning(string $prev, string $following): bool
    {
        // CSS 数学表达式里的二元 + - 必须两侧留白，即使紧邻括号（calc(1px + 2px)、calc(var(--g) - (2px))）。
        if (str_contains('+-', $prev) || str_contains('+-', $following)) {
            return true;
        }

        // 空白紧跟在这些分隔符之后 → 冗余（如 `{ color`、`; margin`、`color: red`、`foo( a`）。
        if (str_contains(self::SPACE_DROPPABLE_AFTER, $prev)) {
            return false;
        }

        // 空白紧邻这些分隔符之前 → 冗余（如 `a {`、`red ;`、`a , b`、`foo(a )`、`a > b`）。
        if (str_contains(self::SPACE_DROPPABLE_BEFORE, $following)) {
            return false;
        }

        return true;
    }
}
