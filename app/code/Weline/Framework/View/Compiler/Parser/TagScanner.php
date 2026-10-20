<?php

declare(strict_types=1);

/**
 * 标签扫描器：只在一段纯文本里找「模板标签」的起止位置。
 *
 * 三条硬要求（都来自真实语料）：
 * 1. 属性值里可以出现 `>`（例如 `data-tip="a>b"`），不能在第一个 `>` 处截断；
 * 2. 普通 HTML（`<div>` / `<span>` / `<!-- 注释 -->`）必须原样留在文本里；
 * 3. 不认识的标签一律不进结构，避免把用户 HTML 当模板标签编译。
 *
 * 扫描器只负责「位置与原文」，不做任何替换：字节由调用方从原文切片，
 * 这样 Parser 的 `raw()` 天然可重放。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Tag\TagNameMatcherInterface;

final class TagScanner
{
    public function __construct(
        private readonly TagNameMatcherInterface $matcher,
    ) {
    }

    /**
     * 找到 `$from` 之后的下一个开标签。
     *
     * @return array{start:int,length:int,name:string,attributes_raw:string,self_closing:bool}|null
     */
    public function nextOpen(string $text, int $from): ?array
    {
        $length = \strlen($text);
        $offset = \max(0, $from);

        while (($lt = \strpos($text, '<', $offset)) !== false) {
            $cursor = $lt + 1;
            if ($cursor >= $length) {
                return null;
            }
            $next = $text[$cursor];
            // 闭标签、注释、处理指令、DOCTYPE 都不在这里处理。
            if ($next === '/' || $next === '!' || $next === '?') {
                $offset = $cursor;
                continue;
            }
            if (\preg_match('/\G([A-Za-z][A-Za-z0-9_.-]*(?::[A-Za-z0-9_.-]+)*)/', $text, $matches, 0, $cursor) !== 1) {
                $offset = $cursor;
                continue;
            }

            $name = $matches[1];
            $nameEnd = $cursor + \strlen($name);
            if (!$this->matcher->matchesElement($name)) {
                $offset = $nameEnd;
                continue;
            }

            $end = $this->findTagEnd($text, $nameEnd);
            if ($end === null) {
                return null;
            }
            $selfClosing = $end > $nameEnd && $text[$end - 1] === '/';
            $attributesEnd = $selfClosing ? $end - 1 : $end;
            // 返回属性区间的原始偏移，供 Parser 从「未掩码源码」切片：
            // 标签内 PHP 在扫描时被掩码，但 attributes 必须是原始字节（runtime 要解析表达式）。
            $attributesStart = $nameEnd;
            while ($attributesStart < $attributesEnd && \ctype_space($text[$attributesStart])) {
                $attributesStart++;
            }
            $attributesStop = $attributesEnd;
            while ($attributesStop > $attributesStart && \ctype_space($text[$attributesStop - 1])) {
                $attributesStop--;
            }
            $attributesRaw = \substr($text, $attributesStart, $attributesStop - $attributesStart);

            return [
                'start' => $lt,
                'length' => $end + 1 - $lt,
                'name' => $name,
                'attributes_raw' => $attributesRaw,
                'attributes_start' => $attributesStart,
                'attributes_end' => $attributesStop,
                'self_closing' => $selfClosing,
            ];
        }

        return null;
    }

    /**
     * 找 `$from` 之后、名字为 `$name` 的闭标签。
     *
     * @return array{start:int,length:int,name:string}|null
     */
    public function nextClose(string $text, int $from, string $name): ?array
    {
        $length = \strlen($text);
        $offset = \max(0, $from);
        $nameLength = \strlen($name);

        while (($pos = \stripos($text, '</', $offset)) !== false) {
            $cursor = $pos + 2;
            if ($cursor + $nameLength > $length) {
                return null;
            }
            if (\strcasecmp(\substr($text, $cursor, $nameLength), $name) !== 0) {
                $offset = $pos + 2;
                continue;
            }
            $after = $cursor + $nameLength;
            while ($after < $length && \ctype_space($text[$after])) {
                $after++;
            }
            if ($after < $length && $text[$after] === '>') {
                return [
                    'start' => $pos,
                    'length' => $after + 1 - $pos,
                    'name' => $name,
                ];
            }
            $offset = $pos + 2;
        }

        return null;
    }


    /**
     * 找到 `$from` 之后的下一个内联标签（`@name(...)` / `@name{...}`）。
     *
     * 三个必须处理的真实情况：
     * 1. 括号内可以出现引号与转义，`}` / `)` 只有在非引号状态且深度归零时才算结束；
     * 2. 未闭合的内联标签不吞字节，返回 null 交给上层按文本处理；
     * 3. `@media{...}` / `@font-face{...}`（CSS）与邮箱 `a@b.com` 靠**名单**排除，
     *    不靠正则形状——名单来自标签声明，Parser 不维护第二份清单。
     *
     * @return array{start:int,length:int,name:string,delimiter:string,body_raw:string}|null
     */
    public function nextInline(string $text, int $from): ?array
    {
        $length = \strlen($text);
        $offset = \max(0, $from);

        while (($at = \strpos($text, '@', $offset)) !== false) {
            $cursor = $at + 1;
            if ($cursor >= $length) {
                return null;
            }
            if (\preg_match('/\G([A-Za-z][A-Za-z0-9_:.\-]*)/', $text, $matches, 0, $cursor) !== 1) {
                $offset = $at + 1;
                continue;
            }
            $name = $matches[1];
            $after = $cursor + \strlen($name);
            if ($after >= $length) {
                return null;
            }
            $delimiter = $text[$after];
            if ($delimiter !== '(' && $delimiter !== '{') {
                $offset = $at + 1;
                continue;
            }
            if (!$this->matcher->matchesInline($name)) {
                $offset = $at + 1;
                continue;
            }
            $closing = $this->findMatchingDelimiter($text, $after, $delimiter);
            if ($closing === null) {
                $offset = $at + 1;
                continue;
            }

            return [
                'start' => $at,
                'length' => $closing + 1 - $at,
                'name' => $name,
                'delimiter' => $delimiter,
                'body_raw' => \substr($text, $after + 1, $closing - $after - 1),
            ];
        }

        return null;
    }

    /**
     * 从定界符位置找到配对的闭定界符（引号内的括号不计深度）。
     */
    private function findMatchingDelimiter(string $text, int $openAt, string $open): ?int
    {
        $close = $open === '(' ? ')' : '}';
        $length = \strlen($text);
        $depth = 0;
        $quote = null;

        for ($i = $openAt; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === $open) {
                $depth++;
                continue;
            }
            if ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 从名字之后开始找标签结束的 `>`（跳过引号内的内容）。
     */
    private function findTagEnd(string $text, int $from): ?int
    {
        $length = \strlen($text);
        $quote = null;
        for ($i = $from; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $i;
            }
        }

        return null;
    }

    /**
     * 解析属性原文：`name="v"` / `name='v'` / `name=v` / 裸属性（值为 true）。
     *
     * 不做类型转换与表达式求值：那是 Resolve 阶段（P1）的事，
     * 本方法只保证「不丢、不并、不误拆」。
     *
     * @return array<string, string|true>
     */
    /**
     * 跳过 `@tag{...}` / `@tag(...)` 整个 body，供属性引号扫描使用。
     * body 内可以有与属性外层相同的引号（如 `@url{'path'}`），不能提前闭合属性。
     */
    private static function skipInlineBody(string $raw, int $at): ?int
    {
        if (\preg_match('/\G@([A-Za-z][A-Za-z0-9_.:-]*)\s*([\(\{])/', $raw, $matches, 0, $at) !== 1) {
            return null;
        }
        $open = $matches[2];
        $close = $open === '(' ? ')' : '}';
        $length = \strlen($raw);
        $depth = 1;
        $quote = null;
        $i = $at + \strlen($matches[0]);
        while ($i < $length) {
            $char = $raw[$i];
            if (\substr($raw, $i, 2) === '<?') {
                $phpEnd = \strpos($raw, '?>', $i + 2);
                if ($phpEnd === false) {
                    return null;
                }
                $i = $phpEnd + 2;
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote && ($raw[$i - 1] ?? '') !== '\\') {
                    $quote = null;
                }
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $i++;
                continue;
            }
            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            $i++;
        }

        return null;
    }

    public static function parseAttributes(string $raw): array
    {
        $attributes = [];
        $length = \strlen($raw);
        $offset = 0;

        while ($offset < $length) {
            if (\ctype_space($raw[$offset])) {
                $offset++;
                continue;
            }
            if (\preg_match('/\G([A-Za-z_][A-Za-z0-9_:.\-]*)/', $raw, $matches, 0, $offset) !== 1) {
                // 属性区出现无法识别的内容（例如直接写 PHP），交给上层原样保留。
                break;
            }
            $name = $matches[1];
            $offset += \strlen($name);

            while ($offset < $length && \ctype_space($raw[$offset])) {
                $offset++;
            }
            if ($offset >= $length || $raw[$offset] !== '=') {
                $attributes[$name] = true;
                continue;
            }
            $offset++;
            while ($offset < $length && \ctype_space($raw[$offset])) {
                $offset++;
            }
            if ($offset >= $length) {
                $attributes[$name] = '';
                break;
            }

            $quote = $raw[$offset];
            if ($quote === '"' || $quote === "'") {
                // 属性值里的 PHP 可以再包含同种引号（例如 PHP 字符串参数），
                // 找闭合引号时必须跳过整个 PHP 片段，否则会把 PHP 字符串里的引号当属性结束。
                $closing = false;
                $cursor = $offset + 1;
                while ($cursor < $length) {
                    if (\substr($raw, $cursor, 2) === '<?') {
                        $phpEnd = \strpos($raw, '?>', $cursor + 2);
                        if ($phpEnd === false) {
                            break;
                        }
                        $cursor = $phpEnd + 2;
                        continue;
                    }
                    if ($raw[$cursor] === '@') {
                        $inlineEnd = self::skipInlineBody($raw, $cursor);
                        if ($inlineEnd !== null) {
                            $cursor = $inlineEnd;
                            continue;
                        }
                    }
                    if ($raw[$cursor] === $quote) {
                        $closing = $cursor;
                        break;
                    }
                    $cursor++;
                }
                if ($closing === false) {
                    $attributes[$name] = \substr($raw, $offset + 1);
                    break;
                }
                $attributes[$name] = \substr($raw, $offset + 1, $closing - $offset - 1);
                $offset = $closing + 1;
                continue;
            }

            $valueStart = $offset;
            while ($offset < $length && !\ctype_space($raw[$offset])) {
                $offset++;
            }
            $attributes[$name] = \substr($raw, $valueStart, $offset - $valueStart);
        }

        return $attributes;
    }
}
