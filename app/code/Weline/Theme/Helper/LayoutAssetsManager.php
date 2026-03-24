<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeResourceGateway;

class LayoutAssetsManager
{
    public function __construct(
        private readonly ThemeResourceGateway $themeResourceGateway,
    ) {
    }

    public function getGeneratedCssPath(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null
    ): string {
        $path = $this->themeResourceGateway->buildLayoutAssetDiskPath(
            $area,
            $layoutType,
            $layoutOption,
            'css',
            $theme
        );

        if ($path !== '') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        return $path;
    }

    public function getGeneratedJsPath(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null
    ): string {
        $path = $this->themeResourceGateway->buildLayoutAssetDiskPath(
            $area,
            $layoutType,
            $layoutOption,
            'js',
            $theme
        );

        if ($path !== '') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        return $path;
    }

    public function getCssUrl(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
        ?Template $template = null
    ): string {
        return $this->themeResourceGateway->buildLayoutAssetUrl(
            $area,
            $layoutType,
            $layoutOption,
            'css',
            $theme,
            true
        );
    }

    public function getJsUrl(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null,
        ?Template $template = null
    ): string {
        return $this->themeResourceGateway->buildLayoutAssetUrl(
            $area,
            $layoutType,
            $layoutOption,
            'js',
            $theme,
            true
        );
    }

    public function ensureLayoutCssGenerated(
        string $area,
        string $layoutType,
        string $layoutOption,
        ?WelineTheme $theme = null
    ): bool {
        $cssPath = $this->getGeneratedCssPath($area, $layoutType, $layoutOption, $theme);
        if ($cssPath === '') {
            return false;
        }

        if (is_file($cssPath)) {
            return true;
        }

        try {
            /** @var CssVariableInjector $injector */
            $injector = ObjectManager::getInstance(CssVariableInjector::class);
            $cssVariables = $injector->generateCssVariables($area, $theme, 'default');
            $cssContent = $cssVariables . "\n";
            if ($cssContent === '') {
                return false;
            }

            $dir = dirname($cssPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return file_put_contents($cssPath, $cssContent) !== false;
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                try {
                    Env::getInstance()->getLogger()?->warning('LayoutAssetsManager: failed to generate layout CSS on demand', [
                        'area' => $area,
                        'layoutType' => $layoutType,
                        'layoutOption' => $layoutOption,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                }
            }
        }

        return false;
    }

    public function copyToStatic(string $sourceFile, string $targetFile): bool
    {
        if (!is_file($sourceFile)) {
            return false;
        }

        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        return copy($sourceFile, $targetFile);
    }
}
