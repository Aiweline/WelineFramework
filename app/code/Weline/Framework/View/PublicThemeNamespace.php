<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\App\Env;

/**
 * 公开设计主题命名空间归一化（单一权威）。
 *
 * 主题配置里的 `theme.path` 有多种形态，但 `pub/static` 下的发布目录与
 * `/static/{命名空间}/...` URL 只认**相对命名空间**：
 *
 * | 配置值 | 归一化结果 |
 * |--------|-----------|
 * | `Weline/hanfu` | `Weline/hanfu`（相对自定义主题路径保持不变） |
 * | `Weline_Hanfu::view/theme` 模块标识 | `Weline/Hanfu/view/theme`（**展开**，保留主题身份） |
 * | `<app/design>/{Vendor}/{theme}` 绝对路径 | `{Vendor}/{theme}` |
 * | `<app/code>/{Vendor}/{Module}/view/theme` 绝对路径 | `{Vendor}/{Module}/view/theme` |
 * | 项目内相对写法 `app/code/...` / `app/design/...` | 先补成项目内绝对路径再按上两行处理 |
 * | 其它绝对路径 / 盘符路径 / 空值 | 默认主题命名空间（`resolve()`）或 `null`（`tryResolve()`） |
 * | `..` / `.` / `a//b` / `a::b` 等不安全输入 | 同上（绝不原样返回） |
 *
 * **为什么必须有这一层**：发布目录与 URL 前缀必须来自同一个命名空间，否则资源 404。
 * 真实事故：`Deploy\Upgrade` 曾把未归一化的 `theme.path` 直接拼进发布目标，绝对路径
 * 因此被当成目录段，`pub/static` 下长出 `Users/<name>/.../app/code/...` 与
 * `Weline_Theme::view/` 这类畸形树（合计 500M+）。
 *
 * **为什么模块标识要展开而不是回落默认**：`Vendor_Module::path` 命名的是**某个具体主题**；
 * 若一律回落内置默认命名空间，两个不同主题会写进同一个 `pub/static/{默认}/...` 而互相覆盖。
 * 展开同时也与历史实现（`Theme\Service\ThemeStaticNamespaceService`）以及既有单测一致。
 *
 * **两个入口的分工**：
 * - `resolve()` 永不返回空——用于「必须得到一个可发布命名空间」的调用点（`Deploy\Upgrade`、`TraitTemplate`）。
 * - `tryResolve()` 返回 `?string`——用于需要区分「无法解析」的调用点
 *   （`ThemeStaticNamespaceService` 会据此回退配置项，`theme:upgrade` 据此报错中止）。
 */
final class PublicThemeNamespace
{
    /** 框架内置默认主题的公开命名空间（`Env::default_theme_DATA['path']` 取不到时的兜底）。 */
    private const BUILTIN_DEFAULT_NAMESPACE = 'Weline/Theme/view/theme';

    /**
     * 归一化为可直接用于 `pub/static/{...}` 与 `/static/{...}` 的相对命名空间。
     *
     * @param string|null $configuredPath `theme.path` 原值（相对、绝对或 `Module::path`）
     */
    public static function resolve(?string $configuredPath): string
    {
        return self::tryResolve($configuredPath) ?? self::defaultNamespace();
    }

    /**
     * 同 {@see resolve()}，但无法对应公开命名空间时返回 `null` 而非回落默认。
     *
     * @return string|null 安全的相对命名空间，或 `null` 表示「无法解析」
     */
    public static function tryResolve(?string $configuredPath): ?string
    {
        $themePath = self::normalize((string)$configuredPath);
        if ($themePath === '') {
            return null;
        }

        // 1) 模块主题标识 `Vendor_Module::path` → 展开为 `Vendor/Module/path`（保留主题身份）。
        if (preg_match('#^([^/:]+)_([^/:]+)::(.+)$#', $themePath, $matches) === 1) {
            $inner = trim(self::normalize($matches[3]), '/');

            return $inner === '' ? null : self::accept($matches[1] . '/' . $matches[2] . '/' . $inner);
        }

        // 2) 项目内相对写法 `app/code/...`、`app/design/...` → 补成项目内绝对路径再走下面的根判定。
        if (preg_match('#^app/(code|design)/#', $themePath) === 1 && defined('BP')) {
            $themePath = self::normalize(rtrim(BP, '/\\') . '/' . $themePath);
        }

        // 3) `app/design/{Vendor}/{theme}` → 相对 `{Vendor}/{theme}`。
        $designRoot = self::normalize(Env::path_THEME_DESIGN_DIR);
        if ($designRoot !== '' && self::isUnder($themePath, $designRoot)) {
            return self::accept(trim(substr($themePath, strlen($designRoot)), '/'));
        }

        // 4) `app/code/{Vendor}/{Module}/view/theme` → `{Vendor}/{Module}/view/theme`。
        $codeRoot = self::normalize(defined('APP_CODE_PATH') ? (string)APP_CODE_PATH : '');
        if ($codeRoot !== '' && self::isUnder($themePath, $codeRoot)) {
            $relative = trim(substr($themePath, strlen($codeRoot)), '/');
            if (preg_match('#^([^/]+)/([^/]+)/view/theme(?:/|$)#', $relative, $matches) === 1) {
                return self::accept($matches[1] . '/' . $matches[2] . '/view/theme');
            }

            return null;
        }

        // 5) 其余绝对路径 / 盘符路径：无法对应公开命名空间。
        if (self::isAbsolutePath($themePath)) {
            return null;
        }

        // 6) 相对自定义主题路径。
        return self::accept($themePath);
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

    private static function defaultNamespace(): string
    {
        $default = self::normalize((string)Env::default_theme_DATA['path']);

        return $default !== '' ? $default : self::BUILTIN_DEFAULT_NAMESPACE;
    }

    /** 仅在结果安全时接受，否则视为「无法解析」。 */
    private static function accept(string $namespace): ?string
    {
        $namespace = trim($namespace, '/');

        return self::isSafeRelativeNamespace($namespace) ? $namespace : null;
    }

    private static function isUnder(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
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
