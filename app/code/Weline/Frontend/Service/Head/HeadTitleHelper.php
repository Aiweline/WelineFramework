<?php

declare(strict_types=1);

namespace Weline\Frontend\Service\Head;

/**
 * 页面标题净化：过滤模块代号等无 SEO 价值的占位标题。
 */
final class HeadTitleHelper
{
    public static function sanitize(string $title, ?string $moduleName = null): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        if (self::isModuleCodeTitle($title) || self::isModuleDefaultTitle($title, $moduleName)) {
            return '';
        }

        return $title;
    }

    public static function isModuleCodeTitle(string $title): bool
    {
        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9_]*$/', trim($title));
    }

    public static function isModuleDefaultTitle(string $title, ?string $moduleName = null): bool
    {
        $title = trim($title);
        $moduleName = trim((string) $moduleName);
        return $moduleName !== '' && $title === $moduleName;
    }

    public static function requestModuleName(): string
    {
        try {
            if (class_exists(\Weline\Framework\Manager\ObjectManager::class)
                && class_exists(\Weline\Framework\Http\Request::class)) {
                $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
                if (method_exists($request, 'getModuleName')) {
                    return trim((string) $request->getModuleName());
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
