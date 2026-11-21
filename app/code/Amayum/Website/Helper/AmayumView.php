<?php

declare(strict_types=1);

namespace Amayum\Website\Helper;

use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * Amayum 店面固定页的展示辅助（只做格式化，不持有业务事实）。
 *
 * 本类是模块模板的无条件依赖，必须随模块部署，因此放在模块内由约定自动
 * 加载；主题目录 app/design/Amayum/official/frontend/includes/AmayumView.php
 * 只留 class_alias 垫片，兼容仍 require 旧路径的已编译模板。
 *
 * THEME_ROOT 仍指向配对设计主题：asset()/themeFile() 需要按主题磁盘文件
 * 计算静态版本号（模块 etc/site.php 同样声明该主题）。文件缺失时按
 * StaticBuildId 兜底，不影响类本身可用。
 *
 * 站点资料优先读 Amayum\Website\Service\SiteProfileService；404 静态发布等
 * 无控制器上下文时回退到模块 etc/site.php 默认值（同一事实源）。
 */
final class AmayumView
{
    public const THEME_ROOT = '/app/design/Amayum/official/frontend';

    /** @var array<string, mixed>|null */
    private static ?array $defaults = null;

    /** @var array<string, string> */
    private static array $versionCache = [];

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        if (self::$defaults === null) {
            $file = rtrim((string)BP, '/\\') . '/app/code/Amayum/Website/etc/site.php';
            $data = is_file($file) ? require $file : [];
            self::$defaults = is_array($data) ? $data : [];
        }

        return self::$defaults;
    }

    /** @return array<string, mixed> */
    public static function profile(): array
    {
        $service = self::siteService();
        if ($service !== null) {
            try {
                $profile = $service->profile();
                if (is_array($profile) && $profile !== []) {
                    return $profile;
                }
            } catch (\Throwable) {
            }
        }

        $profile = (array)(self::defaults()['profile'] ?? []);
        $links = (array)($profile['links'] ?? []);
        $profile['github'] = (string)($links['github'] ?? '');
        $profile['gitee'] = (string)($links['gitee'] ?? '');
        $profile['docs'] = (string)($links['docs'] ?? '');

        return $profile;
    }

    /** @return array{items: list<array<string, mixed>>, cta: array<string, string>} */
    public static function nav(): array
    {
        $service = self::siteService();
        if ($service !== null) {
            try {
                $nav = $service->nav();
                if (is_array($nav) && ($nav['items'] ?? []) !== []) {
                    return $nav;
                }
            } catch (\Throwable) {
            }
        }

        $nav = (array)(self::defaults()['nav'] ?? []);
        $items = array_values(array_filter(
            (array)($nav['items'] ?? []),
            static fn(mixed $item): bool => is_array($item) && ($item['visible'] ?? true) !== false
        ));
        usort($items, static fn(array $a, array $b): int => (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0));
        $cta = (array)($nav['cta'] ?? []);

        return [
            'items' => $items,
            'cta' => [
                'label' => (string)($cta['label'] ?? '预约架构诊断'),
                'href' => (string)($cta['href'] ?? '/diagnosis#diagnosis'),
            ],
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, bottom: list<array<string, mixed>>} */
    public static function footer(): array
    {
        $service = self::siteService();
        if ($service !== null) {
            try {
                $footer = $service->footer();
                if (is_array($footer) && ($footer['columns'] ?? []) !== []) {
                    return $footer;
                }
            } catch (\Throwable) {
            }
        }

        $footer = (array)(self::defaults()['footer'] ?? []);

        return [
            'columns' => array_values((array)($footer['columns'] ?? [])),
            'bottom' => array_values((array)($footer['bottom'] ?? [])),
        ];
    }

    public static function image(string $file): string
    {
        return self::publishedUrl('assets/images/' . ltrim($file, '/'));
    }

    public static function asset(string $file): string
    {
        $relative = ltrim($file, '/');
        if (str_starts_with($relative, 'assets/')) {
            $relative = substr($relative, 7);
        }

        return self::publishedUrl('assets/' . $relative);
    }

    /** 主题 frontend 下的任意文件（colors/、variables/、assets/…）。 */
    public static function themeFile(string $file): string
    {
        return self::publishedUrl(ltrim($file, '/'));
    }

    public static function url(Template $template, string $href): string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return $href;
        }
        if (str_starts_with($href, '#')) {
            return $href;
        }
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $href) === 1) {
            $parts = parse_url($href);
            $base = parse_url((string)$template->getRequest()->getBaseHost());
            if (is_array($parts) && is_array($base)
                && !empty($parts['host']) && !empty($base['host'])
                && strcasecmp((string)$parts['host'], (string)$base['host']) === 0
            ) {
                return (string)($parts['path'] ?? '/')
                    . (isset($parts['query']) ? '?' . $parts['query'] : '')
                    . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
            }

            return $href;
        }
        if (preg_match('#^(?:mailto:|tel:|javascript:)#i', $href) === 1) {
            return $href;
        }

        $fragment = '';
        if (str_contains($href, '#')) {
            [$href, $fragment] = explode('#', $href, 2);
            $fragment = '#' . $fragment;
        }
        $query = '';
        if (str_contains($href, '?')) {
            [$href, $query] = explode('?', $href, 2);
            $query = '?' . $query;
        }

        $path = ltrim($href, '/');
        $built = $path === '' ? (string)$template->getUrl('/') : (string)$template->getUrl($path);
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $built) === 1) {
            $parts = parse_url($built);
            if (is_array($parts)) {
                $built = (string)($parts['path'] ?? '/')
                    . (isset($parts['query']) ? '?' . $parts['query'] : '')
                    . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
            }
        }

        return $built . $query . $fragment;
    }

    public static function currentPath(): string
    {
        $origin = trim((string)($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? ''));
        $uri = $origin !== '' && $origin !== '/'
            ? $origin
            : (string)($_SERVER['REQUEST_URI'] ?? $origin);
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        if (preg_match('#^/~site/[^/]+(/.*)?$#', $path, $matches) === 1) {
            $path = (string)($matches[1] ?? '/');
        }
        $path = (string)(preg_replace('#^/index\.php#', '', $path) ?: '/');
        $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
        while ($parts !== [] && (
            preg_match('/^[a-z]{2}(?:_[A-Za-z]{2,4}){1,2}$/', $parts[0]) === 1
            || preg_match('/^[A-Z]{3}$/', $parts[0]) === 1
        )) {
            array_shift($parts);
        }

        return '/' . implode('/', $parts);
    }

    public static function isCurrent(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')
            || preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $href) === 1
            || preg_match('#^(?:mailto:|tel:)#i', $href) === 1
        ) {
            return false;
        }
        $path = (string)(parse_url($href, PHP_URL_PATH) ?: '/');
        $path = '/' . trim($path, '/');
        $current = self::currentPath();
        if ($path === '/') {
            return $current === '/';
        }

        return $path === $current || str_starts_with($current, $path . '/');
    }

    private static function siteService(): ?object
    {
        if (!class_exists(\Amayum\Website\Service\SiteProfileService::class)) {
            return null;
        }
        try {
            return ObjectManager::getInstance(\Amayum\Website\Service\SiteProfileService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function publishedUrl(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $absolute = rtrim((string)BP, '/\\') . self::THEME_ROOT . '/' . $relative;
        $version = self::$versionCache[$relative] ?? null;
        if ($version === null) {
            if (is_file($absolute)) {
                $version = base_convert(
                    substr(md5($absolute . '|' . filemtime($absolute) . '|' . filesize($absolute)), 0, 10),
                    16,
                    36
                );
            } else {
                try {
                    $version = StaticBuildId::current() ?: '0';
                } catch (\Throwable) {
                    $version = '0';
                }
            }
            self::$versionCache[$relative] = $version;
        }

        foreach (self::publishedBases() as $base) {
            if (is_file(self::staticDiskPath($base) . $relative)) {
                return $base . $relative . '?v=' . $version;
            }
        }

        return self::themeBase() . $relative . '?v=' . $version;
    }

    /**
     * 静态 URL 前缀到磁盘的映射（URL 的 `/static/` 段对应 `pub/static/`）。
     */
    private static function staticDiskPath(string $base): string
    {
        $relative = ltrim($base, '/');
        if (str_starts_with($relative, 'static/')) {
            $relative = substr($relative, strlen('static/'));
        }

        return rtrim((string)BP, '/\\') . '/pub/static/' . $relative;
    }

    /**
     * 已发布主题静态的候选前缀，按优先级：主题更新代次 → 部署构建代 → 扁平发布树。
     *
     * deploy:upgrade（扁平静态）只发布 `/static/<namespace>/frontend/`，
     * 不铺 `official/v1/<build>/<namespace>/frontend/` 整树；沿用代次前缀会让
     * 皮肤 CSS 全部 404 并阻塞首绘。
     *
     * @return list<string>
     */
    private static function publishedBases(): array
    {
        $bases = [self::themeBase()];

        try {
            if (class_exists(\Weline\Theme\Helper\ThemeData::class)
                && class_exists(\Weline\Theme\Service\ThemeStaticNamespaceService::class)
            ) {
                $theme = \Weline\Theme\Helper\ThemeData::getCurrentTheme();
                if ($theme !== null && (int)$theme->getId() > 0) {
                    $namespace = trim((string)ObjectManager::getInstance(
                        \Weline\Theme\Service\ThemeStaticNamespaceService::class
                    )->resolvePublicThemePath($theme), '/');
                    if ($namespace !== '') {
                        $bases[] = '/static/' . $namespace . '/frontend/';
                    }
                }
            }
        } catch (\Throwable) {
        }

        $bases[] = '/static/Amayum/official/frontend/';

        return array_values(array_unique($bases));
    }

    /**
     * 主题自有静态资源前缀（到 frontend/ 为止）。
     *
     * theme:upgrade 把设计主题发布到 `{pair_segment}/{static_build}/{theme_prefix}`
     * （例如 `theme/v1/87d75b385c01/Amayum/official`），不是部署构建根。
     * 这里读主题更新指针；没有指针时回退当前主题公开命名空间 + 部署构建号。
     */
    private static function themeBase(): string
    {
        try {
            if (class_exists(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::class)) {
                $active = \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::active();
                $pair = trim((string)($active['pair_segment'] ?? ''));
                $build = trim((string)($active['static_build'] ?? ''));
                $prefix = trim((string)($active['theme_prefix'] ?? ''));
                if ($pair !== '' && $build !== '' && $prefix !== '') {
                    return '/static/' . $pair . '/' . $build . '/' . $prefix . '/frontend/';
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (class_exists(\Weline\Theme\Helper\ThemeData::class)
                && class_exists(\Weline\Theme\Service\ThemeStaticNamespaceService::class)
            ) {
                $theme = \Weline\Theme\Helper\ThemeData::getCurrentTheme();
                if ($theme !== null && (int)$theme->getId() > 0) {
                    $namespace = ObjectManager::getInstance(
                        \Weline\Theme\Service\ThemeStaticNamespaceService::class
                    )->resolvePublicThemePath($theme);
                    if ($namespace !== '') {
                        $build = StaticBuildId::current();
                        $base = $build !== ''
                            ? '/static/theme/v1/' . $build . '/' . $namespace
                            : '/static/' . $namespace;

                        return $base . '/frontend/';
                    }
                }
            }
        } catch (\Throwable) {
        }

        return '/static/Amayum/official/frontend/';
    }
}
