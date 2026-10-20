<?php

declare(strict_types=1);

/**
 * 模板词法切分器（v3 第一层）。
 *
 * 设计要点（与《模板引擎重构计划书》§4.2 一致）：
 * 1. **只切分，不替换**：Text / Php 片段的 `raw()` 顺序拼接必须字节等于原文。
 * 2. **PHP 是一等片段**：用 PHP 自己的词法器（`token_get_all`）识别 PHP 区段，
 *    因此字符串、注释、heredoc 里的闭标签与短开标签写法都不会被误切；
 *    下游不再需要 `PhpExtractor` 的占位符往返，也不需要 `isOffsetInsidePhp()` 守卫。
 * 3. **不做语义判断**：标签（`<w:*>`、`<lang>`、`@url()`）留给 Parser；
 *    本层只保证「哪里是 PHP、哪里是文本」这件事绝对正确。
 *
 * 为什么用 `token_get_all` 而不是手写状态机：闭标签的终止语义受字符串/注释/heredoc
 * 影响，手写状态机是这类编译器最经典的错源；PHP 自己的词法器是 C 层实现，
 * 对 10 KB 模板的 token 数只有几十到几百（绝大部分字节落在 T_INLINE_HTML），
 * 性能不是瓶颈。P0 的 `taglib:bench` 会把这个口径钉成基线。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Lexer;

final class Lexer
{
    /**
     * 把模板源码切成片段序列。
     *
     * @return list<Fragment>
     */
    public function tokenize(string $source): array
    {
        if ($source === '') {
            return [];
        }

        /** @var list<Fragment> $fragments */
        $fragments = [];

        $offset = 0;
        $line = 1;
        $lineStart = 0;

        $buffer = '';
        $bufferStart = 0;
        $bufferLine = 1;
        $bufferColumn = 1;
        $bufferEcho = false;
        $bufferOpenTag = '';

        $flush = static function () use (
            &$buffer,
            &$bufferStart,
            &$bufferLine,
            &$bufferColumn,
            &$bufferEcho,
            &$bufferOpenTag,
            &$fragments
        ): void {
            if ($buffer === '') {
                return;
            }
            $fragments[] = new PhpFragment(
                $buffer,
                new Span($bufferStart, \strlen($buffer), $bufferLine, $bufferColumn),
                $bufferEcho,
                $bufferOpenTag,
            );
            $buffer = '';
            $bufferEcho = false;
            $bufferOpenTag = '';
        };

        foreach (\token_get_all($source) as $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? (string)$token[1] : (string)$token;
            $length = \strlen($text);

            if ($id === \T_INLINE_HTML) {
                $flush();
                $fragments[] = new TextFragment(
                    $text,
                    new Span($offset, $length, $line, $offset - $lineStart + 1),
                );
            } else {
                if ($buffer === '') {
                    $bufferStart = $offset;
                    $bufferLine = $line;
                    $bufferColumn = $offset - $lineStart + 1;
                    if ($id === \T_OPEN_TAG_WITH_ECHO) {
                        $bufferEcho = true;
                        $bufferOpenTag = '<?=';
                    } elseif ($id === \T_OPEN_TAG) {
                        $bufferOpenTag = \str_starts_with($text, '<?php') ? '<?php' : '<?';
                    }
                }
                $buffer .= $text;
            }

            $lastNewline = \strrpos($text, "\n");
            if ($lastNewline !== false) {
                $line += \substr_count($text, "\n");
                $lineStart = $offset + $lastNewline + 1;
            }
            $offset += $length;
        }

        $flush();

        return $fragments;
    }

    /**
     * 按片段顺序重放原文（P0 退出判据的实际执行点）。
     *
     * @param list<Fragment> $fragments
     */
    public function replay(array $fragments): string
    {
        $replayed = '';
        foreach ($fragments as $fragment) {
            $replayed .= $fragment->raw();
        }

        return $replayed;
    }

    /**
     * 切分 + 重放是否与原文逐字节相同。
     */
    public function isRoundTrip(string $source): bool
    {
        return $this->replay($this->tokenize($source)) === $source;
    }
}
