<?php

declare(strict_types=1);

/**
 * Parser 的中间记号。
 *
 * 存在的理由：标签可能跨 PHP 片段（开标签在文本 A、闭标签在文本 B），
 * 所以必须先把「文本 / PHP / 开标签 / 闭标签」拉平成一条**绝对偏移**的记号流，
 * 再用栈配对成树。这样跨片段的配对不需要在字符串上做二次搜索。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Parser;

use Weline\Framework\View\Compiler\Lexer\Span;

final class ParseToken
{
    public const TEXT = 'text';
    public const PHP = 'php';
    public const TAG_OPEN = 'tag_open';
    public const TAG_CLOSE = 'tag_close';
    public const INLINE = 'inline';

    /**
     * @param array<string, string|true> $attributes
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $raw,
        public readonly Span $span,
        public readonly string $name = '',
        public readonly string $attributesRaw = '',
        public readonly array $attributes = [],
        public readonly bool $selfClosing = false,
        public readonly bool $echo = false,
        public readonly string $openTag = '<?php',
        public readonly string $delimiter = '',
        public readonly string $bodyRaw = '',
        public readonly bool $quoted = false,
        public readonly int $legacyLine = 0,
    ) {
    }

    public static function text(string $raw, Span $span): self
    {
        return new self(self::TEXT, $raw, $span);
    }

    public static function php(string $raw, Span $span, bool $echo = false, string $openTag = '<?php'): self
    {
        return new self(self::PHP, $raw, $span, '', '', [], false, $echo, $openTag);
    }

    /**
     * @param array<string, string|true> $attributes
     */
    public static function open(
        string $name,
        string $attributesRaw,
        array $attributes,
        bool $selfClosing,
        string $raw,
        Span $span,
        int $legacyLine = 0,
    ): self {
        return new self(self::TAG_OPEN, $raw, $span, $name, $attributesRaw, $attributes, $selfClosing, false, '<?php', '', '', false, $legacyLine);
    }

    public static function close(string $name, string $raw, Span $span): self
    {
        return new self(self::TAG_CLOSE, $raw, $span, $name);
    }

    public static function inline(
        string $name,
        string $delimiter,
        string $bodyRaw,
        string $raw,
        Span $span,
        bool $quoted = false,
    ): self {
        return new self(
            self::INLINE,
            $raw,
            $span,
            $name,
            '',
            [],
            false,
            false,
            '<?php',
            $delimiter,
            $bodyRaw,
            $quoted,
        );
    }
}
