<?php

declare(strict_types=1);

namespace Weline\Framework\View\Helper;

/**
 * 嵌入页/Hook 内局部标题：过滤模块代号占位，避免 EXTR_SKIP 后显示 Weline_Customer 等。
 */
final class EmbeddedPageTitle
{
    public static function resolve(mixed $title, string $fallbackSourcePhrase): string
    {
        $title = trim((string) $title);
        if ($title === '' || self::isModulePlaceholder($title)) {
            return (string) __($fallbackSourcePhrase);
        }

        return $title;
    }

    public static function isModulePlaceholder(string $title): bool
    {
        $title = trim($title);
        if ($title === '') {
            return true;
        }

        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9_]*$/', $title);
    }
}
