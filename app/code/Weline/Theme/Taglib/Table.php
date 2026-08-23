<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 自研主题表格：标准 w-table + 可选悬浮起/止列。
 *
 * 用法：
 * <w:theme:table sticky-end="true" sticky-end-min="8.5rem">
 *   <thead>...</thead>
 *   <tbody>...</tbody>
 * </w:theme:table>
 */
final class Table implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:table';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => false,
            'class' => false,
            'wrap-class' => false,
            'sticky-start' => false,
            'sticky-end' => false,
            'sticky-start-min' => false,
            'sticky-end-min' => false,
            'style' => false,
            'wrap-style' => false,
            'role' => false,
            'aria-label' => false,
            'data-review-role' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $content = (string)($tagData[2] ?? '');
            $id = trim((string)($attributes['id'] ?? ''));
            $class = trim('w-table ' . (string)($attributes['class'] ?? ''));
            $wrapClass = trim('w-table-wrap ' . (string)($attributes['wrap-class'] ?? ''));
            $stickyStart = self::isTrue($attributes['sticky-start'] ?? false);
            $stickyEnd = self::isTrue($attributes['sticky-end'] ?? false);
            $stickyStartMin = trim((string)($attributes['sticky-start-min'] ?? ''));
            $stickyEndMin = trim((string)($attributes['sticky-end-min'] ?? ''));
            $style = trim((string)($attributes['style'] ?? ''));
            $wrapStyle = trim((string)($attributes['wrap-style'] ?? ''));
            $role = trim((string)($attributes['role'] ?? ''));
            $ariaLabel = trim((string)($attributes['aria-label'] ?? ''));
            $reviewRole = trim((string)($attributes['data-review-role'] ?? ''));

            $tableAttrs = [];
            if ($id !== '') {
                $tableAttrs[] = 'id="' . self::escape($id) . '"';
            }
            $tableAttrs[] = 'class="' . self::escape($class) . '"';
            if ($stickyStart) {
                $tableAttrs[] = 'data-w-sticky-start';
            }
            if ($stickyEnd) {
                $tableAttrs[] = 'data-w-sticky-end';
            }

            $cssVars = [];
            if ($stickyStartMin !== '') {
                $cssVars[] = '--w-table-sticky-start-min:' . self::escapeCssLength($stickyStartMin);
            }
            if ($stickyEndMin !== '') {
                $cssVars[] = '--w-table-sticky-end-min:' . self::escapeCssLength($stickyEndMin);
            }
            if ($style !== '') {
                $cssVars[] = $style;
            }
            if ($cssVars !== []) {
                $tableAttrs[] = 'style="' . self::escape(implode(';', $cssVars)) . '"';
            }

            $wrapAttrs = ['class="' . self::escape($wrapClass) . '"'];
            if ($role !== '') {
                $wrapAttrs[] = 'data-w-role="' . self::escape($role) . '"';
            }
            if ($reviewRole !== '') {
                $wrapAttrs[] = 'data-review-role="' . self::escape($reviewRole) . '"';
            }
            if ($ariaLabel !== '') {
                $wrapAttrs[] = 'aria-label="' . self::escape($ariaLabel) . '"';
            }
            if ($wrapStyle !== '') {
                $wrapAttrs[] = 'style="' . self::escape($wrapStyle) . '"';
            }

            return '<div ' . implode(' ', $wrapAttrs) . '><table '
                . implode(' ', $tableAttrs) . '>' . $content . '</table></div>';
        };
    }

    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string)$value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escapeCssLength(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^-?\d+(\.\d+)?(px|rem|em|%|vh|vw|ch|ex|vmin|vmax)?$/i', $value)) {
            return '8.5rem';
        }

        return $value;
    }
}
