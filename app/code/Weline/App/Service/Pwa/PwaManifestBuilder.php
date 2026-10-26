<?php

declare(strict_types=1);

namespace Weline\App\Service\Pwa;

use Weline\App\Service\SiteProfile;
use Weline\Framework\Manager\ObjectManager;

/**
 * 店面 PWA manifest / head 标签的数据面。
 *
 * 站名、图标、主题色来自当前 Website 的品牌配置；语言与货币来自当前店面上下文。
 * 路由只负责输出，不在这里写任何站点品牌字面量。
 */
final class PwaManifestBuilder
{
    public const DEFAULT_ICON_192 = '/pwa-icon-192.png';
    public const DEFAULT_ICON_512 = '/pwa-icon-512.png';

    public function __construct(
        private readonly SiteProfile $siteProfile,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $websiteId, string $lang, string $currency, string $origin): array
    {
        $profile = $this->siteProfile->resolve($websiteId, true);
        $name = $this->siteName($profile);
        $description = $this->siteDescription($profile);
        $langTag = $this->langTag($lang);

        $payload = [
            'id' => '/',
            'name' => $name,
            'short_name' => $this->shortName($name),
            'description' => $description,
            'lang' => $langTag,
            'start_url' => $this->startUrl($lang, $currency),
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['standalone', 'minimal-ui'],
            'orientation' => 'portrait-primary',
            'categories' => ['shopping'],
            'prefer_related_applications' => false,
            'icons' => $this->icons($origin),
        ];

        $themeColor = $this->themeColor($profile);
        if ($themeColor !== '') {
            $payload['theme_color'] = $themeColor;
        }
        $background = \trim((string)($profile['theme']['surface'] ?? ''));
        if ($background !== '') {
            $payload['background_color'] = $background;
        }

        return $payload;
    }

    public function manifestHref(string $lang, string $currency): string
    {
        $query = [];
        if ($lang !== '') {
            $query['lang'] = $lang;
        }
        if ($currency !== '') {
            $query['currency'] = $currency;
        }

        return '/manifest.webmanifest' . ($query !== [] ? '?' . \http_build_query($query) : '');
    }

    public function appTitle(int $websiteId): string
    {
        return $this->siteName($this->siteProfile->resolve($websiteId, true));
    }

    public function themeColorForWebsite(int $websiteId): string
    {
        return $this->themeColor($this->siteProfile->resolve($websiteId, true));
    }

    public function appleTouchIcon(): string
    {
        foreach (['apple_touch_icon', 'favicon'] as $key) {
            $url = $this->brandIconUrl($key, 180);
            if ($url !== '') {
                return $url;
            }
        }
        $url = $this->configuredIconUrl(180);
        if ($url !== '') {
            return $url;
        }

        if (\class_exists(\Weline\Theme\Helper\SiteBrand::class)) {
            return \Weline\Theme\Helper\SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH;
        }

        return self::DEFAULT_ICON_192;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function siteName(array $profile): string
    {
        $name = \trim((string)($profile['name'] ?? ''));

        return $name !== '' ? $name : (string)__('在线商店');
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function siteDescription(array $profile): string
    {
        $description = \trim((string)($profile['description'] ?? ''));
        if ($description !== '') {
            return $description;
        }

        return (string)__('精选优质商品与可靠服务。');
    }

    private function shortName(string $name): string
    {
        if (\function_exists('mb_substr')) {
            return \mb_substr($name, 0, 12);
        }

        return \substr($name, 0, 36);
    }

    private function langTag(string $lang): string
    {
        $lang = \trim($lang);

        return $lang !== '' ? \str_replace('_', '-', $lang) : '';
    }

    private function startUrl(string $lang, string $currency): string
    {
        $query = ['source' => 'pwa'];
        if ($lang !== '') {
            $query['lang'] = $lang;
        }
        if ($currency !== '') {
            $query['currency'] = $currency;
        }

        return '/?' . \http_build_query($query);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function themeColor(array $profile): string
    {
        $primary = \trim((string)($profile['theme']['primary'] ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        try {
            if (\class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                $primary = \trim((string)(\Weline\Theme\Helper\ThemeData::getColorConfig('frontend') ?? ''));
                if (\preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $primary) === 1) {
                    return $primary;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @return list<array{src:string,sizes:string,type:string,purpose?:string}>
     */
    private function icons(string $origin): array
    {
        $brand = '';
        foreach (['favicon', 'apple_touch_icon'] as $key) {
            $brand = $this->brandIconUrl($key, 512);
            if ($brand !== '') {
                break;
            }
        }
        if ($brand === '') {
            $brand = $this->configuredIconUrl(512);
        }

        if ($brand !== '') {
            $src = $this->absoluteUrl($origin, $brand);
            $type = $this->iconType($src);
            if ($type === 'image/svg+xml') {
                return [
                    ['src' => $src, 'sizes' => 'any', 'type' => $type, 'purpose' => 'any'],
                    ['src' => $src, 'sizes' => 'any', 'type' => $type, 'purpose' => 'maskable'],
                ];
            }

            return [
                ['src' => $src, 'sizes' => '192x192', 'type' => $type, 'purpose' => 'any'],
                ['src' => $src, 'sizes' => '512x512', 'type' => $type, 'purpose' => 'any'],
                ['src' => $src, 'sizes' => '512x512', 'type' => $type, 'purpose' => 'maskable'],
            ];
        }

        return [
            ['src' => $this->absoluteUrl($origin, self::DEFAULT_ICON_192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $this->absoluteUrl($origin, self::DEFAULT_ICON_512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $this->absoluteUrl($origin, self::DEFAULT_ICON_512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }

    private function brandIconUrl(string $key, int $size): string
    {
        try {
            if (!\class_exists(\Weline\Theme\Service\ThemeBrandResolver::class)
                || !\class_exists(\Weline\Theme\Helper\SiteBrand::class)
            ) {
                return '';
            }
            $resolver = ObjectManager::getInstance(\Weline\Theme\Service\ThemeBrandResolver::class);
            $brand = $resolver->resolvePublishedBrand('frontend');
            $path = \trim((string)($brand[$key] ?? ''));
            if ($path === '') {
                return '';
            }
            $siteBrand = ObjectManager::getInstance(\Weline\Theme\Helper\SiteBrand::class);

            return $siteBrand->resolvePathToUrl($path, $size, $size);
        } catch (\Throwable) {
            return '';
        }
    }

    private function configuredIconUrl(int $size): string
    {
        try {
            if (!\class_exists(\Weline\Theme\Helper\SiteBrand::class)) {
                return '';
            }
            $siteBrand = ObjectManager::getInstance(\Weline\Theme\Helper\SiteBrand::class);

            return $siteBrand->resolveMediaUrl('site_icon', $size, $size);
        } catch (\Throwable) {
            return '';
        }
    }

    private function absoluteUrl(string $origin, string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return '';
        }
        if (\str_starts_with($url, 'http://') || \str_starts_with($url, 'https://') || \str_starts_with($url, '//')) {
            return $url;
        }

        return \rtrim($origin, '/') . '/' . \ltrim($url, '/');
    }

    private function iconType(string $src): string
    {
        $path = (string)\parse_url($src, PHP_URL_PATH);
        $ext = \strtolower((string)\pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };
    }
}
