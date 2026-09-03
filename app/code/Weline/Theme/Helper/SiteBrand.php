<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\FileManager\Api\Image as ImageHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\ThemeBrandResolver;

/**
 * 站点品牌资源（Logo / Icon）统一解析。
 * 优先 Theme Scope 外观 brand；其次 Weline_Backend 基础配置；最后 Theme 默认静态资源。
 */
class SiteBrand
{
    public const CONFIG_MODULE = 'Weline_Backend';

    public const DEFAULT_LOGO_FRONTEND_STATIC = 'Weline_Theme::theme/frontend/assets/images/theme/logo.png';
    public const DEFAULT_LOGO_BACKEND_STATIC = 'Weline_Theme::theme/backend/assets/images/theme/logo.png';
    public const DEFAULT_ICON_STATIC = 'Weline_Theme::theme/frontend/assets/images/theme/icon.png';

    /** Public URL path for Theme default favicon (no Taglib / no @static). */
    public const DEFAULT_ICON_PUBLIC_PATH = '/Weline/Theme/view/theme/frontend/assets/images/theme/icon.png';

    /** Public URL path for Theme default apple-touch-icon. */
    public const DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH = '/Weline/Theme/view/theme/frontend/assets/images/theme/apple-touch-icon.png';

    public function __construct(
        private readonly BackendConfigStore $backendConfig,
    ) {
    }

    public function isLegacyPlaceholder(string $path): bool
    {
        return str_contains($path, 'image/backend/logo/');
    }

    public function getRawConfig(string $key): string
    {
        $value = trim((string)($this->backendConfig->getConfig($key, self::CONFIG_MODULE) ?? ''));
        if ($value === '' || $this->isLegacyPlaceholder($value)) {
            return '';
        }

        return $value;
    }

    public function resolveMediaUrl(string $configKey, int $width, int $height): string
    {
        $configured = $this->getRawConfig($configKey);
        if ($configured === '') {
            return '';
        }

        if (str_starts_with($configured, 'http') || str_starts_with($configured, '//') || str_starts_with($configured, '/static/') || str_starts_with($configured, '/pub/media/') || str_starts_with($configured, '/Weline/')) {
            return $configured;
        }

        return ImageHelper::pathToMediaUrl($configured, $width, $height);
    }

    public function resolvePathToUrl(string $path, int $width, int $height): string
    {
        $path = trim($path);
        if ($path === '' || $this->isLegacyPlaceholder($path)) {
            return '';
        }
        if (str_starts_with($path, 'http') || str_starts_with($path, '//') || str_starts_with($path, '/static/') || str_starts_with($path, '/Weline/')) {
            return $path;
        }
        if (str_starts_with($path, '/pub/media/')) {
            return $path;
        }

        return ImageHelper::pathToMediaUrl($path, $width, $height);
    }

    public function resolveStaticUrl(Template $template, string $staticSource, string $fallbackPath): string
    {
        $url = trim((string)$template->fetchTagSourceFile('statics', $staticSource));
        if ($url !== '') {
            return $url;
        }

        return $fallbackPath;
    }

    public function resolveIconUrl(Template $template, int $size = 128): string
    {
        $fromTheme = $this->resolveThemeBrandUrl('favicon', $size, $size);
        if ($fromTheme !== '') {
            return $fromTheme;
        }

        $configured = $this->resolveMediaUrl('site_icon', $size, $size);
        if ($configured !== '') {
            return $configured;
        }

        unset($template);

        return self::DEFAULT_ICON_PUBLIC_PATH;
    }

    public function resolveAppleTouchIconUrl(Template $template): string
    {
        $fromTheme = $this->resolveThemeBrandUrl('apple_touch_icon', 180, 180);
        if ($fromTheme === '') {
            $fromTheme = $this->resolveThemeBrandUrl('favicon', 180, 180);
        }
        if ($fromTheme !== '') {
            return $fromTheme;
        }

        $configured = $this->resolveMediaUrl('site_icon', 180, 180);
        if ($configured !== '') {
            return $configured;
        }

        unset($template);

        return self::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH;
    }

    public function resolveFrontendLogoUrl(Template $template, int $width = 240, int $height = 80): string
    {
        foreach (['logo_light', 'logo_dark'] as $key) {
            $fromTheme = $this->resolveThemeBrandUrl($key, $width, $height);
            if ($fromTheme !== '') {
                return $fromTheme;
            }
        }
        foreach (['logo_light', 'logo_dark'] as $key) {
            $url = $this->resolveMediaUrl($key, $width, $height);
            if ($url !== '') {
                return $url;
            }
        }

        unset($template);

        return '/Weline/Theme/view/theme/frontend/assets/images/theme/logo.png';
    }

    /**
     * 前台品牌字标：显式自定义文案优先；再回落当前 Website 名称 / Backend site_name；
     * 框架占位（Weline/韦林）不作为最终展示。站名权威源是 Website 实体（基础信息 identity slot），
     * 不是 appearance.brand。
     */
    public function resolveFrontendSiteName(string $configured = '', string $themeFallback = '云裳汉服 · Hanfu Atelier'): string
    {
        $configured = trim($configured);
        if ($configured !== '' && !$this->isGenericBrandPlaceholder($configured)) {
            return WidgetI18n::label($configured);
        }

        $fromWebsite = $this->resolveWebsiteDisplayName();
        if ($fromWebsite !== '' && !$this->isGenericBrandPlaceholder($fromWebsite)) {
            return $fromWebsite;
        }

        $fromBackend = trim($this->getRawConfig('site_name'));
        if ($fromBackend !== '' && !$this->isGenericBrandPlaceholder($fromBackend)) {
            return $fromBackend;
        }

        $fallback = trim($themeFallback) !== '' ? trim($themeFallback) : '云裳汉服 · Hanfu Atelier';

        return WidgetI18n::label($fallback);
    }

    public function resolveFrontendSiteDescription(string $fallback = ''): string
    {
        $fromBackend = trim($this->getRawConfig('site_description'));
        if ($fromBackend !== '') {
            return $fromBackend;
        }

        return trim($fallback);
    }

    public function isGenericBrandPlaceholder(string $value): bool
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, [
            'Weline',
            'weline',
            'Weline Framework',
            '韦林',
            '默认网站',
            'Default Website',
        ], true);
    }

    private function resolveWebsiteDisplayName(): string
    {
        try {
            if (!class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                return '';
            }

            return trim((string)(\Weline\Websites\Data\WebsiteData::getName() ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    public function resolveBackendLogoUrl(Template $template, string $configKey, int $width, int $height): string
    {
        $url = $this->resolveMediaUrl($configKey, $width, $height);
        if ($url !== '') {
            return $url;
        }

        return '/Weline/Theme/view/theme/backend/assets/images/theme/logo.png';
    }

    private function resolveThemeBrandUrl(string $brandKey, int $width, int $height): string
    {
        try {
            /** @var ThemeBrandResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemeBrandResolver::class);
            $path = $resolver->resolveBrandPath($brandKey, 'frontend');

            return $this->resolvePathToUrl($path, $width, $height);
        } catch (\Throwable) {
            return '';
        }
    }
}
