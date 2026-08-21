<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\View\Template;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeResourceGateway;

/**
 * Read-only layout asset locator used by previews and explicit manifests.
 *
 * Weline UI 2.0 never extracts template content or writes compiled templates
 * during a request. Asset generation belongs exclusively to resource compilers.
 */
final class LayoutAssetsManager
{
    public function __construct(private readonly ThemeResourceGateway $themeResourceGateway)
    {
    }

    public function getGeneratedCssPath(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
    ): string {
        return $this->themeResourceGateway->buildLayoutAssetDiskPath(
            $area,
            $layoutType,
            $layoutOption,
            'css',
            $theme,
        );
    }

    public function getGeneratedJsPath(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
    ): string {
        return $this->themeResourceGateway->buildLayoutAssetDiskPath(
            $area,
            $layoutType,
            $layoutOption,
            'js',
            $theme,
        );
    }

    public function getCssUrl(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
        ?Template $template = null,
    ): string {
        return $this->themeResourceGateway->buildLayoutAssetUrl(
            $area,
            $layoutType,
            $layoutOption,
            'css',
            $theme,
            true,
        );
    }

    public function getJsUrl(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
        ?Template $template = null,
    ): string {
        return $this->themeResourceGateway->buildLayoutAssetUrl(
            $area,
            $layoutType,
            $layoutOption,
            'js',
            $theme,
            true,
        );
    }
}
