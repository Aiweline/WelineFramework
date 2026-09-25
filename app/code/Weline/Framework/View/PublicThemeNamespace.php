<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\App\Env;

/**
 * 公开设计主题命名空间归一化（单一权威）。
 *
 * 主题配置里的 `theme.path` 可能是三种形态，但 `pub/static` 下的发布目录与
 * `/static/{命名空间}/...` URL 只认**相对命名空间**：
 *
 * | 配置值 | 归一化结果 |
 * |--------|-----------|
 * | `Weline/hanfu` | `Weline/hanfu`（相对自定义主题路径保持不变） |
 * | `app/code/Weline/Theme/view/theme` 绝对路径 | 默认主题命名空间 |
 * | `Weline_Theme::view/theme` 模块标识 | 默认主题命名空间 |
 * | `<app/design>/{Vendor}/{theme}` 绝对路径 | `{Vendor}/{theme}` |
 * | 其它绝对路径 / 盘符路径 | 默认主题命名空间 |
 *
 * **为什么必须有这一层**：发布目录与 URL 前缀必须来自同一个命名空间，否则资源 404。
 * 真实事故：`Deploy\Upgrade` 曾把未归一化的 `theme.path` 直接拼进发布目标，绝对路径
 * 因此被当成目录段，`pub/static` 下长出 `Users/<name>/.../app/code/...` 与
 * `Weline_Theme::view/` 这类畸形树（合计 500M+）。
 */
final class PublicThemeNamespace
{
    /** 框架内置默认主题的公开命名空间（绝对路径/模块标识的归一化落点）。 */
    private const BUILTIN_DEFAULT_NAMESPACE = 'Weline/Theme/view/theme';

    /**
     * @param string|null $configuredPath `theme.path` 原值（可为相对、绝对或 `Module::path`）
     * @return string 可直接用于 `pub/static/{...}` 与 `/static/{...}` 的相对命名空间
     */
    public static function resolve(?string $configuredPath): string
    {
        $defaultPath = self::normalize((string)Env::default_theme_DATA['path']);
        $themePath = self::normalize((string)$configuredPath);

        if ($themePath === '') {
            return $defaultPath;
        }

        // 模块主题标识（`Vendor_Module::path`）不是公开设计主题命名空间。
        if (preg_match('#^[^/:]+_[^/:]+::.+$#', $themePath) === 1) {
            return $defaultPath;
        }

        // `app/design/{Vendor}/{theme}` 绝对路径 → 相对 `{Vendor}/{theme}`。
        $designRoot = self::normalize(Env::path_THEME_DESIGN_DIR);
        if ($designRoot !== '' && ($themePath === $designRoot || str_starts_with($themePath, $designRoot . '/'))) {
            $relativePath = trim(substr($themePath, strlen($designRoot)), '/');

            return $relativePath !== '' ? $relativePath : $defaultPath;
        }

        if (self::isAbsolutePath($themePath)
            || strcasecmp(trim($themePath, '/'), self::BUILTIN_DEFAULT_NAMESPACE) === 0
        ) {
            return $defaultPath;
        }

        $themePath = trim($themePath, '/');

        // 兜底：仍不是安全的相对命名空间（含 `..` / `::` / 空段）时回落默认主题。
        return self::isSafeRelativeNamespace($themePath) ? $themePath : $defaultPath;
    }

    /**
     * 是否为可用于 `pub/static` 的安全相对命名空间。
     *
     * 发布目标必须落在 `pub/static` 之内；`..` 可越出该目录，绝对路径与盘符路径同理。
     */
    public static function isSafeRelativeNamespace(string $namespace): bool
    {
        if ($namespace === '' || self::isAbsolutePath($namespace)) {
            return false;
        }

        foreach (explode('/', $namespace) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
            if (str_contains($segment, '::')) {
                return false;
            }
        }

        return true;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }
}
