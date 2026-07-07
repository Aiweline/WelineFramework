<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Backend\Model\Config as BackendConfig;
use Weline\FileManager\Helper\Image as ImageHelper;
use Weline\Framework\View\Template;

/**
 * 站点品牌资源（Logo / Icon）统一解析。
 * 优先读取 Weline_Backend 基础配置；未配置或占位路径时使用 Theme 默认静态资源。
 */
class SiteBrand
{
    public const CONFIG_MODULE = 'Weline_Backend';

    public const DEFAULT_LOGO_FRONTEND_STATIC = 'Weline_Theme::theme/frontend/assets/images/theme/logo.png';
    public const DEFAULT_LOGO_BACKEND_STATIC = 'Weline_Theme::theme/backend/assets/images/theme/logo.png';
    public const DEFAULT_ICON_STATIC = 'Weline_Theme::theme/frontend/assets/images/theme/icon.png';

    public function __construct(
        private readonly BackendConfig $backendConfig,
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

        if (str_starts_with($configured, 'http') || str_starts_with($configured, '//') || str_starts_with($configured, '/static/')) {
            return $configured;
        }

        return ImageHelper::pathToMediaUrl($configured, $width, $height);
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
        $configured = $this->resolveMediaUrl('site_icon', $size, $size);
        if ($configured !== '') {
            return $configured;
        }

        return $this->resolveStaticUrl(
            $template,
            self::DEFAULT_ICON_STATIC,
            '/Weline/Theme/view/theme/frontend/assets/images/theme/icon.png',
        );
    }

    public function resolveAppleTouchIconUrl(Template $template): string
    {
        $configured = $this->resolveMediaUrl('site_icon', 180, 180);
        if ($configured !== '') {
            return $configured;
        }

        $url = trim((string)$template->fetchTagSourceFile('statics', 'Weline_Theme::theme/frontend/assets/images/theme/apple-touch-icon.png'));
        if ($url !== '') {
            return $url;
        }

        return $this->resolveIconUrl($template, 180);
    }

    public function resolveFrontendLogoUrl(Template $template, int $width = 240, int $height = 80): string
    {
        foreach (['logo_light', 'logo_dark'] as $key) {
            $url = $this->resolveMediaUrl($key, $width, $height);
            if ($url !== '') {
                return $url;
            }
        }

        return $this->resolveStaticUrl(
            $template,
            self::DEFAULT_LOGO_FRONTEND_STATIC,
            '/Weline/Theme/view/theme/frontend/assets/images/theme/logo.png',
        );
    }

    public function resolveBackendLogoUrl(Template $template, string $configKey, int $width, int $height): string
    {
        $url = $this->resolveMediaUrl($configKey, $width, $height);
        if ($url !== '') {
            return $url;
        }

        return $this->resolveStaticUrl(
            $template,
            self::DEFAULT_LOGO_BACKEND_STATIC,
            '/Weline/Theme/view/theme/backend/assets/images/theme/logo.png',
        );
    }
}
