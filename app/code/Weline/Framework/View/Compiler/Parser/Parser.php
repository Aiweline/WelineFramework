<?php

declare(strict_types=1);

/**
 * Parser：片段 → 记号流 → 节点树。
 *
 * 两条不变量（每次改这个类都要先看）：
 * 1. **字节不变量**：`Document::replay()` 必须与原文逐字节相同。所有节点/记号的 `raw()`
 *    都是原文切片，任何「丢弃」都必须显式变成 TextNode。
 * 2. **结构不变量**：标签配对只由记号流 + 栈决定；开标签在文本 A、闭标签在文本 B
 *    （中间隔着 PHP）也必须配成同一个 TagNode。
 *
 * P0 只有 Text/Php；P1 加入 TagNode。Inline 标签（`@lang(...)` 这类）下一刀再接。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Lexer\PhpFragment;
use Weline\Framework\View\Compiler\Lexer\Span;
use Weline\Framework\View\Compiler\Lexer\TextFragment;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Compiler\Tag\TagNameMatcherInterface;

final class Parser
{
    private readonly TagNameMatcherInterface $matcher;
    private readonly TagScanner $scanner;

    public function __construct(
        private readonly Lexer $lexer = new Lexer(),
        ?TagNameMatcherInterface $matcher = null,
    ) {
        $this->matcher = $matcher ?? new StaticTagNameMatcher([]);
        $this->scanner = new TagScanner($this->matcher);
    }

    public function parse(string $source): Document
    {
        // 标签属性里的 PHP（如 form 的 action 写成短回显）会把开标签切成
        // 文本/PHP 片段，纯文本扫描就看不到完整标签。先用同长度掩码只掩盖
        // 「已注册模板标签」内部的 PHP，扫描完再用原始源码切片还原字节。
        $fragments = $this->lexer->tokenize($this->maskPhpInTags($source));

        return new Document($source, $this->buildTree($this->tokenizeTags($fragments, $source), $source));
    }

    /**
     * @param list<\Weline\Framework\View\Compiler\Lexer\Fragment> $fragments
     */
    public function parseFragments(array $fragments, string $source): Document
    {
        return new Document($source, $this->buildTree($this->tokenizeTags($fragments, $source), $source));
    }

    /**
     * 把片段流拉平成绝对偏移的记号流。
     *
     * @param list<\Weline\Framework\View\Compiler\Lexer\Fragment> $fragments
     * @return list<ParseToken>
     */
    private function tokenizeTags(array $fragments, string $source): array
    {
        $tokens = [];
        // 旧 AST 的 line 不计 PHP 块内换行；累计后用于 runtime children 变量命名。
        $phpNewlines = 0;

        foreach ($fragments as $fragment) {
            if ($fragment instanceof PhpFragment) {
                $phpNewlines += \substr_count($fragment->raw(), "\n");
                $tokens[] = ParseToken::php(
                    $fragment->raw(),
                    $fragment->span(),
                    $fragment->isEcho(),
                    $fragment->openTag(),
                );
                continue;
            }
            if (!$fragment instanceof TextFragment) {
                throw new \LogicException('未支持的片段类型：' . $fragment::class);
            }

            $text = $fragment->raw();
            // 扫描用掩码文本；发射 raw 用原始源码（同长度、同偏移）。
            $original = \substr($source, $fragment->span()->start, \strlen($text));
            $commentNewlines = self::commentNewlineOffsets($text);
            $base = $fragment->span()->start;
            // 文本片段内的行状态：行号与「当前行起点绝对偏移」。
            $line = $fragment->span()->line;
            $lineStart = $fragment->span()->start - ($fragment->span()->column - 1);
            $cursor = 0;
            $search = 0;

            $emitText = static function (int $from, int $to) use (&$tokens, &$line, &$lineStart, $base, $original): void {
                if ($to <= $from) {
                    return;
                }
                $raw = \substr($original, $from, $to - $from);
                $absolute = $base + $from;
                $tokens[] = ParseToken::text($raw, new Span($absolute, $to - $from, $line, $absolute - $lineStart + 1));
                $lastNewline = \strrpos($raw, "\n");
                if ($lastNewline !== false) {
                    $line += \substr_count($raw, "\n");
                    $lineStart = $absolute + $lastNewline + 1;
                }
            };

            while (true) {
                $lt = \strpos($text, '<', $search);
                $at = \strpos($text, '@', $search);
                if ($lt === false && $at === false) {
                    break;
                }

                // 内联标签先到就先处理：`@lang{...}` / `@url(...)` 是自包含的，
                // 与元素标签不同，它不进配对栈。
                if ($at !== false && ($lt === false || $at < $lt)) {
                    $inline = $this->scanner->nextInline($text, $at);
                    if ($inline === null || $inline['start'] !== $at) {
                        // 不是注册过的内联标签（CSS 的 @media、邮箱的 @ 等）：
                        // 只推进搜索位置，不能推进已发射游标。
                        $search = $at + 1;
                        continue;
                    }
                    // 旧编译器对 `'@lang(词)'` / `"@lang(词)"` 会消费成对引号并切到
                    // 属性标记模式（`InlinePhraseMarker::emit(词, true)`）；这会出现在
                    // HTML 属性、JS 字符串等所有文本上下文，不只是 block 属性。
                    $start = $at;
                    $length = $inline['length'];
                    $quoted = false;
                    if (self::isLangInline($inline['name'])
                        && $at > 0
                        && $at + $length < \strlen($text)) {
                        $before = $text[$at - 1];
                        $after = $text[$at + $length];
                        if (($before === '"' || $before === "'") && $before === $after) {
                            $quoted = true;
                            $start = $at - 1;
                            $length += 2;
                        }
                    }

                    $emitText($cursor, $start);
                    $absolute = $base + $start;
                    $raw = \substr($original, $start, $length);
                    // body 也用原始源码，避免标签内 PHP 被掩码文本替换。
                    $bodyRaw = \substr(
                        $original,
                        $at + \strlen($inline['name']) + 2,
                        \max(0, $inline['length'] - \strlen($inline['name']) - 3),
                    );
                    $tokens[] = ParseToken::inline(
                        $inline['name'],
                        $inline['delimiter'],
                        $bodyRaw,
                        $raw,
                        new Span($absolute, \strlen($raw), $line, $absolute - $lineStart + 1),
                        $quoted,
                    );
                    $lastNewline = \strrpos($raw, "\n");
                    if ($lastNewline !== false) {
                        $line += \substr_count($raw, "\n");
                        $lineStart = $absolute + $lastNewline + 1;
                    }
                    $cursor = $start + $length;
                    $search = $cursor;
                    continue;
                }

                // 先试闭标签：它必须与开标签走同一条配对路径。
                if (\preg_match('/\G<\/\s*([A-Za-z][A-Za-z0-9_.:-]*)\s*>/', $text, $matches, 0, $lt) === 1
                    && $this->matcher->matchesElement($matches[1])
                ) {
                    $emitText($cursor, $lt);
                    $absolute = $base + $lt;
                    $raw = \substr($original, $lt, \strlen($matches[0]));
                    $tokens[] = ParseToken::close(
                        $matches[1],
                        $raw,
                        new Span($absolute, \strlen($raw), $line, $absolute - $lineStart + 1),
                    );
                    $lastNewline = \strrpos($raw, "\n");
                    if ($lastNewline !== false) {
                        $line += \substr_count($raw, "\n");
                        $lineStart = $absolute + $lastNewline + 1;
                    }
                    $cursor = $lt + \strlen($raw);
                    $search = $cursor;
                    continue;
                }

                $tag = $this->scanner->nextOpen($text, $lt);
                if ($tag === null || $tag['start'] !== $lt) {
                    // 不是模板标签（普通 HTML / 注释 / 处理指令）：只推进搜索位置，
                    // 不能推进已发射游标，否则这一个 `<` 就丢了。
                    $search = $lt + 1;
                    continue;
                }

                $emitText($cursor, $lt);
                $absolute = $base + $lt;
                $raw = \substr($original, $lt, $tag['length']);
                $attributesRaw = \substr(
                    $original,
                    $tag['attributes_start'],
                    \max(0, $tag['attributes_end'] - $tag['attributes_start']),
                );
                $tokens[] = ParseToken::open(
                    $tag['name'],
                    $attributesRaw,
                    TagScanner::parseAttributes($attributesRaw),
                    $tag['self_closing'],
                    $raw,
                    new Span($absolute, \strlen($raw), $line, $absolute - $lineStart + 1),
                    \max(1, $line - $phpNewlines - count(array_filter(
                        $commentNewlines,
                        static fn (int $offset): bool => $offset < $lt,
                    ))),
                );
                $lastNewline = \strrpos($raw, "\n");
                if ($lastNewline !== false) {
                    $line += \substr_count($raw, "\n");
                    $lineStart = $absolute + $lastNewline + 1;
                }
                $cursor = $lt + $tag['length'];
                $search = $cursor;
            }

            $emitText($cursor, \strlen($text));
        }

        return $tokens;
    }

    /**
     * 只掩码「已注册模板标签」内部的 PHP，长度不变、偏移不变。
     *
     * 不能全量掩码：普通 HTML 属性里的 PHP 仍应由 Lexer 当 PhpFragment 处理。
     * 仍应由 Lexer 当 PhpFragment 处理。只有模板标签需要跨 PHP 扫描属性。
     */
    /**
     * 旧 `Taglib::compile()` 在 PROD 会先删 HTML 注释，注释里的换行不计入 AST line。
     *
     * @return list<int> 注释内换行相对片段起点的偏移
     */
    private static function commentNewlineOffsets(string $text): array
    {
        if (!\defined('PROD') || !\PROD) {
            return [];
        }
        if (\preg_match_all('/<!--[\s\S]*?-->/', $text, $matches, PREG_OFFSET_CAPTURE) !== 1
            && ($matches[0] ?? []) === []) {
            return [];
        }
        $offsets = [];
        foreach ($matches[0] ?? [] as $match) {
            [$raw, $start] = $match;
            $length = \strlen($raw);
            for ($i = 0; $i < $length; $i++) {
                if ($raw[$i] === "\n") {
                    $offsets[] = $start + $i;
                }
            }
        }

        return $offsets;
    }

    private function maskPhpInTags(string $source): string
    {
        $length = \strlen($source);
        $masked = $source;
        $offset = 0;

        while (($lt = \strpos($masked, '<', $offset)) !== false) {
            $cursor = $lt + 1;
            if (\preg_match('/\G([A-Za-z][A-Za-z0-9_.-]*(?::[A-Za-z0-9_.-]+)*)/', $masked, $matches, 0, $cursor) !== 1) {
                $offset = $lt + 1;
                continue;
            }
            $name = $matches[1];
            if (!$this->matcher->matchesElement($name)) {
                $offset = $lt + 1;
                continue;
            }

            $i = $cursor + \strlen($name);
            $quote = null;
            while ($i < $length) {
                $char = $masked[$i];
                // PHP 可能出现在属性引号内，必须先于引号状态判断。
                if ($char === '<' && ($masked[$i + 1] ?? '') === '?') {
                    $end = \strpos($masked, '?>', $i + 2);
                    if ($end === false) {
                        break;
                    }
                    for ($j = $i; $j <= $end + 1; $j++) {
                        $masked[$j] = "\x01";
                    }
                    $i = $end + 2;
                    continue;
                }
                if ($quote !== null) {
                    if ($char === $quote) {
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
                if ($char === '>') {
                    break;
                }
                $i++;
            }
            $offset = $i + 1;
        }

        return $masked;
    }

    private static function isLangInline(string $name): bool
    {
        $normalized = \strtolower($name);

        return $normalized === 'lang'
            || $normalized === 'trans'
            || $normalized === 'w:lang'
            || $normalized === 'w:trans';
    }

    /**
     * 记号流 → 节点树（标签用栈配对；未闭合/多余闭标签一律降级为文本，保证字节不丢）。
     *
     * @param list<ParseToken> $tokens
     * @return list<Node>
     */
    private function buildTree(array $tokens, string $source): array
    {
        /** @var list<Node> $root */
        $root = [];
        /** @var list<array{name:string,token:ParseToken,children:list<Node>}> $stack */
        $stack = [];

        $append = static function (Node $node) use (&$stack, &$root): void {
            if ($stack === []) {
                $root[] = $node;

                return;
            }
            $stack[\count($stack) - 1]['children'][] = $node;
        };

        $makeTag = static function (
            string $name,
            ParseToken $openToken,
            array $children,
            bool $paired,
            int $rawEnd
        ) use ($source): TagNode {
            $start = $openToken->span->start;
            $raw = \substr($source, $start, \max(0, $rawEnd - $start));

            return new TagNode(
                $name,
                $openToken->attributesRaw,
                $openToken->attributes,
                $openToken->selfClosing,
                $paired,
                $children,
                $raw,
                $openToken->span,
                $openToken->raw,
                $openToken->legacyLine,
            );
        };

        foreach ($tokens as $token) {
            if ($token->kind === ParseToken::TEXT) {
                $append(new TextNode($token->raw, $token->span));
                continue;
            }
            if ($token->kind === ParseToken::PHP) {
                $append(new PhpNode($token->raw, $token->span, $token->echo, $token->openTag));
                continue;
            }
            if ($token->kind === ParseToken::INLINE) {
                $append(new InlineNode(
                    $token->name,
                    $token->delimiter,
                    $token->bodyRaw,
                    $token->raw,
                    $token->span,
                    $token->quoted,
                ));
                continue;
            }
            if ($token->kind === ParseToken::TAG_OPEN) {
                if ($token->selfClosing) {
                    $append($makeTag($token->name, $token, [], false, $token->span->end()));
                    continue;
                }
                $stack[] = ['name' => $token->name, 'token' => $token, 'children' => []];
                continue;
            }

            // 闭标签：从栈顶向下找同名帧；找不到就按文本处理（不吞字节）。
            $matched = null;
            for ($i = \count($stack) - 1; $i >= 0; $i--) {
                if (\strcasecmp($stack[$i]['name'], $token->name) === 0) {
                    $matched = $i;
                    break;
                }
            }
            if ($matched === null) {
                $append(new TextNode($token->raw, $token->span));
                continue;
            }

            // 中间未闭合的帧：截到闭标签之前，按「单标签」收口。
            while (\count($stack) - 1 > $matched) {
                $frame = \array_pop($stack);
                $append($makeTag(
                    $frame['name'],
                    $frame['token'],
                    $frame['children'],
                    false,
                    $token->span->start,
                ));
            }

            $frame = \array_pop($stack);
            $append($makeTag(
                $frame['name'],
                $frame['token'],
                $frame['children'],
                true,
                $token->span->end(),
            ));
        }

        // 文件末尾仍未闭合的帧：按单标签收口到文件尾，保证字节完整。
        while ($stack !== []) {
            $frame = \array_pop($stack);
            $append($makeTag(
                $frame['name'],
                $frame['token'],
                $frame['children'],
                false,
                \strlen($source),
            ));
        }

        return $root;
    }
}
