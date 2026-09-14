<?php
declare(strict_types=1);

namespace Weline\Framework\View\Taglib;

/** Pure source analysis. Translation values remain in the framework Phrase cache. */
final class TemplateTranslationWords
{
    /** Only the global helper with a complete literal first argument is eligible. */
    public static function phpWords(string $source): array
    {
        if (!str_contains($source, '__')) {
            return [];
        }
        $tokens = array_values(array_filter(token_get_all($source), static fn($token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $words = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                || !in_array($token[1], ['__', '\\__'], true)
            ) {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $argument = $tokens[$index + 2] ?? null;
            if (($tokens[$index + 1] ?? null) !== '(' || !is_array($argument)
                || $argument[0] !== T_CONSTANT_ENCAPSED_STRING
                || !in_array($tokens[$index + 3] ?? null, [',', ')'], true)
            ) {
                continue;
            }
            $word = self::decodePhpString($argument[1]);
            if ($word !== null && trim($word) !== '') {
                $words[$word] = $word;
            }
        }
        return array_values($words);
    }

    /** Mirrors the existing lang callback's literal source identity, without executing it. */
    public static function inlineWord(string $expression): ?string
    {
        $word = trim($expression);
        if (preg_match('/^([^,]+?)\s*,\s*(.+)$/s', $word, $matches)) {
            $word = trim(trim($matches[1]), '\'"');
        } elseif (preg_match('/^([\'"])(.*)\1$/s', $word, $matches)) {
            return $matches[2];
        }
        if ($word === '' || str_starts_with($word, '$') || str_contains($word, '->')
            || str_contains($word, '::') || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $word)
        ) {
            return null;
        }
        return $word;
    }

    public static function pairedWords(string $source): array
    {
        $words = [];
        $markup = '';
        foreach (token_get_all($source) as $token) {
            // Keep a boundary at PHP blocks so dynamic children cannot turn into static text.
            $markup .= is_array($token) && $token[0] === T_INLINE_HTML ? $token[1] : '<';
        }
        $markup = preg_replace('/<!--[\s\S]*?-->/', '', $markup);
        // Only plain static children; nested/dynamic children retain their runtime path.
        preg_match_all('~<(?:w:)?lang\b(?:[^\'"<>]|"[^"]*"|\'[^\']*\')*>([^<]*)</(?:w:)?lang\s*>~is', $markup, $matches);
        foreach ($matches[1] ?? [] as $value) {
            $word = trim($value);
            if ($word !== '' && !str_contains($word, '{{') && !str_contains($word, '@')) {
                $words[$word] = $word;
            }
        }
        return array_values($words);
    }

    /** Embed source words in the compiled artifact; do not read/scan source on every request. */
    public static function withRuntimePrefetch(string $compiled): string
    {
        $words = self::phpWords($compiled);
        if ($words === []) {
            return $compiled;
        }
        // A prefix must never displace PHP's mandatory leading declarations.
        // Those uncommon PHP units keep their unchanged normal translation path.
        foreach (token_get_all($compiled) as $token) {
            if (is_array($token) && in_array($token[0], [T_DECLARE, T_NAMESPACE], true)) {
                return $compiled;
            }
        }
        // 编译结果跨 Worker 共享，旧 Parser 跳过预取后仍执行原翻译调用。
        return '<?php if (\\method_exists(\\Weline\\Framework\\Phrase\\Parser::class, \'prefetchTemplateWords\')) { '
            . '\\Weline\\Framework\\Phrase\\Parser::prefetchTemplateWords('
            . var_export($words, true) . '); } ?>' . $compiled;
    }

    private static function decodePhpString(string $literal): ?string
    {
        $value = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }
        // Keep uncommon Unicode escape forms on the ordinary PHP translation path.
        if (str_contains($value, '\\u{')) {
            return null;
        }
        return preg_replace_callback('/\\\\(x[0-9a-fA-F]{1,2}|[0-7]{1,3}|[nrtvef\\\\$"])/', static function (array $match): string {
            $escape = $match[1];
            if ($escape[0] === 'x') { return chr(hexdec(substr($escape, 1))); }
            if (ctype_digit($escape[0])) { return chr(octdec($escape) & 255); }
            return ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\033", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'][$escape];
        }, $value);
    }
}
