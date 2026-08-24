<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Extends;

/**
 * extends/module 路径规约辅助类。
 *
 * 命名空间使用 Extends\Module（PSR 风格），磁盘目录统一为 extends/module（小写）。
 */
class ExtendsPath
{
    public const NS_SEGMENT = 'Extends\\Module';

    public const DIR_SEGMENT = 'extends' . DIRECTORY_SEPARATOR . 'module';

    public static function classToRelativePath(string $class): string
    {
        if (\function_exists('weline_class_to_relative_path')) {
            return weline_class_to_relative_path($class);
        }

        $path = \str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
        $needle = DIRECTORY_SEPARATOR . 'Extends' . DIRECTORY_SEPARATOR . 'Module' . DIRECTORY_SEPARATOR;

        return \str_replace(
            $needle,
            DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR,
            $path
        );
    }

    public static function resolveFile(string $filePath): ?string
    {
        if (\function_exists('weline_resolve_php_file')) {
            return weline_resolve_php_file($filePath);
        }

        $real = \realpath($filePath);

        return ($real !== false && \is_file($real)) ? $real : (\is_file($filePath) ? $filePath : null);
    }

    public static function safeRequireOnce(string $filePath, ?string $className = null): bool
    {
        if (\function_exists('weline_safe_require_once')) {
            return weline_safe_require_once($filePath, $className);
        }

        if ($className !== null
            && (\class_exists($className, false)
                || \interface_exists($className, false)
                || \trait_exists($className, false))) {
            return true;
        }

        $resolved = self::resolveFile($filePath);
        if ($resolved === null) {
            return false;
        }

        require_once $resolved;

        return true;
    }

    /**
     * 判断路径是否位于 extends/module 目录下。
     */
    public static function isExtendsModulePath(string $path): bool
    {
        $normalized = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return \str_contains($normalized, DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR)
            || \str_contains($normalized, '/extends/module/');
    }
}
