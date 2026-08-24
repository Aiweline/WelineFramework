<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * 自研主题 UI 颜色值规范化（hex / rgba / var(--weline-theme-*) 等）。
 */
final class ThemeUiColor
{
    /** @var list<string> */
    public const SEMANTIC_PRESETS = [
        'var(--weline-theme-primary)',
        'var(--weline-theme-primary-surface)',
        'var(--weline-theme-on-primary)',
        'var(--weline-theme-surface)',
        'var(--weline-theme-surface-muted)',
        'var(--weline-theme-surface-raised)',
        'var(--weline-theme-text)',
        'var(--weline-theme-text-muted)',
        'var(--weline-theme-border)',
        'var(--weline-theme-success)',
        'var(--weline-theme-success-surface)',
        'var(--weline-theme-warning)',
        'var(--weline-theme-warning-surface)',
        'var(--weline-theme-danger)',
        'var(--weline-theme-danger-surface)',
        'var(--weline-theme-info)',
        'var(--weline-theme-info-surface)',
        'var(--weline-theme-canvas)',
    ];

    public static function sanitize(mixed $value, string $fallback): string
    {
        if (!is_scalar($value)) {
            return $fallback;
        }
        $color = trim((string)$value);
        if ($color === '') {
            return $fallback;
        }
        if (self::isValid($color)) {
            return $color;
        }

        return $fallback;
    }

    public static function isValid(string $color): bool
    {
        $color = trim($color);
        if ($color === '' || strlen($color) > 128) {
            return false;
        }
        if (preg_match('/[;"\'\\\\{}<>]/', $color)) {
            return false;
        }
        if (in_array(strtolower($color), ['transparent', 'inherit', 'initial', 'currentcolor'], true)) {
            return true;
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            return true;
        }
        if (preg_match('/^(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch)\([0-9a-z.+\-%,\/\s]+\)$/i', $color)) {
            return true;
        }
        if (self::isThemeVar($color)) {
            return true;
        }

        return false;
    }

    public static function isThemeVar(string $color): bool
    {
        $color = trim($color);
        if (!preg_match('/^var\(\s*(--[a-z0-9-]+)\s*(?:,\s*[^)]+)?\s*\)$/i', $color, $matches)) {
            return false;
        }
        $token = strtolower($matches[1]);

        return str_starts_with($token, '--weline-theme-')
            || str_starts_with($token, '--weline-')
            || str_starts_with($token, '--backend-theme-');
    }

    public static function cssProperty(string $property, string $value): string
    {
        return $property . ':' . self::sanitize($value, 'inherit');
    }
}
