<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * CSV / 编译阶段词条校验（Framework 基线，不依赖 I18n）。
 */
final class DictionaryWordValidator
{
    /** 与 I18n Dictionary::MAX_WORD_LENGTH 对齐；Framework 不依赖 I18n。 */
    public const MAX_WORD_LENGTH = 512;

    private const CODE_PATTERNS = [
        '/\$[a-zA-Z_][a-zA-Z0-9_]*/',
        '/->[a-zA-Z_][a-zA-Z0-9_]*\s*\(/',
        '/::[a-zA-Z_][a-zA-Z0-9_]*/',
        '/\[.*?\$.*?\]/',
        '/\(.*?\$.*?\)/',
        '/function\s*\(/',
        '/return\s+/',
        '/if\s*\(/',
        '/else\s*\{/',
        '/foreach\s*\(/',
        '/while\s*\(/',
        '/for\s*\(/',
        '/class\s+/',
        '/namespace\s+/',
        '/use\s+/',
    ];

    public static function isValidTranslationString(string $str): bool
    {
        if ($str === '' || trim($str) === '') {
            return false;
        }

        if (!mb_check_encoding($str, 'UTF-8')) {
            return false;
        }

        if (mb_strlen($str, 'UTF-8') > self::MAX_WORD_LENGTH) {
            return false;
        }

        if (str_contains($str, "\u{FEFF}") || str_starts_with($str, "\xEF\xBB\xBF")) {
            return false;
        }

        if (str_contains($str, "\n") || str_contains($str, "\r")) {
            return false;
        }

        if (preg_match('/["\']{4,}/u', $str) === 1) {
            return false;
        }

        foreach (self::CODE_PATTERNS as $pattern) {
            if (preg_match($pattern, $str)) {
                return false;
            }
        }

        if (preg_match('/[{};]{2,}/', $str)) {
            return false;
        }

        $openParens = substr_count($str, '(');
        $closeParens = substr_count($str, ')');
        if ($openParens > 0 && $openParens !== $closeParens) {
            $placeholderParens = preg_match_all('/%\{[^}]+\}/', $str);
            $realOpenParens = $openParens - $placeholderParens;
            $realCloseParens = $closeParens - $placeholderParens;
            if ($realOpenParens !== $realCloseParens) {
                return false;
            }
        }

        return true;
    }
}
