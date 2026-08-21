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
                if ($prev !== '' && $following !== ''
                    && !self::isSafeCollapseBoundary($prev)
                    && !self::isSafeCollapseBoundary($following)
                ) {
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

    private static function isSafeCollapseBoundary(string $char): bool
    {
        return str_contains('{}:;,>+~=![]()/', $char);
    }
}
