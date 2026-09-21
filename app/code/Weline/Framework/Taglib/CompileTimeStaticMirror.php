<?php

declare(strict_types=1);

namespace Weline\Framework\Taglib;

/**
 * Helpers for compile-time static mirrors: bake final HTML/text into the
 * compiled template when attributes/content are pure literals (lang-style).
 */
final class CompileTimeStaticMirror
{
    /**
     * True when the markup fragment has no PHP open tags / short-echo embeds.
     */
    public static function isLiteralMarkup(string $markup): bool
    {
        if ($markup === '') {
            return true;
        }

        return !str_contains($markup, '<?');
    }

    /**
     * True when a single attribute value is a compile-time literal.
     *
     * Rejects PHP embeds and bare `$var` / expression-looking values that must
     * be resolved at request time.
     */
    public static function isLiteralAttributeValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $string = trim((string)$value);
        if ($string === '') {
            return true;
        }
        if (!self::isLiteralMarkup($string)) {
            return false;
        }
        if (str_starts_with($string, '$')) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function attributesAreLiteral(array $attributes, array $keys = []): bool
    {
        $scan = $keys === [] ? $attributes : array_intersect_key($attributes, array_flip($keys));
        foreach ($scan as $value) {
            if (!self::isLiteralAttributeValue($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a literal attribute to string (bool → "1"/"0" style avoided;
     * scalars cast; empty stays empty).
     */
    public static function literalString(mixed $value, string $default = ''): string
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            $string = trim((string)$value);

            return $string !== '' ? $string : $default;
        }

        return $default;
    }
}
