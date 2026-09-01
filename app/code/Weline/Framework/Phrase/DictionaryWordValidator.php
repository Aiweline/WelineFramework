<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * CSV / 编译阶段词条校验（Framework 基线，不依赖 I18n）。
 */
final class DictionaryWordValidator
{
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
