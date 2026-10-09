<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\ThemePathResolver;
use Weline\Theme\Minify\StaticAssetMinifier;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskCompileService;
use Weline\Theme\Service\Disk\ThemeDiskHeadService;
use Weline\Theme\Service\Disk\ThemeDiskKeys;
use Weline\Theme\Service\LayoutEntity\WidgetAssetArtifactPublisher;

/**
 * Compile-time head chrome CSS packs for storefront/backend.
 *
 * Source leaves (colors/_*.css, variables/_*.css, ui foundation) stay editable;
 * gated by resource_files/theme_css_merge (auto: off in DEV, on in PROD)—independent of widget css_merge.
 * theme.css + style-layer toast stay independent (architecture ≠ brand shell).
 *
 * work_mode=theme_module_runtime
 */
final class ThemeHeadChromeCssPack
{
    public const PACK_TOKENS_PRE = 'tokens-pre';
    public const PACK_TOKENS_POST = 'tokens-post';
    public const PACK_TOKENS_MINIMAL = 'tokens-minimal';
    public const PACK_TOKENS_BACKEND = 'tokens-backend';
    public const PACK_UI = 'ui';

    public function __construct(
        private readonly ThemeResourceConfig $resourceConfig,
        private readonly ThemeContextService $themeContext,
        private readonly ThemePathResolver $pathResolver,
        private readonly ThemeResourceGateway $gateway,
        private readonly ThemeDiskHeadService $diskHead,
        private readonly ThemeDiskCompileService $diskCompile,
        private readonly WidgetAssetArtifactPublisher $publisher,
        private readonly StaticAssetMinifier $minifier,
        private readonly AtomicCompiledFilePublisher $atomicPublisher,
    ) {
    }

    public function isEnabled(string $area = 'frontend'): bool
    {
        $area = $this->themeContext->normalizeArea($area);
        try {
            return !empty($this->resourceConfig->resolve(null, $area)['theme_css_merge']);
        } catch (\Throwable) {
            return defined('PROD') && PROD;
        }
    }

    /**
     * Emit a packed &lt;link&gt;, or null when packing is off / publish failed (caller keeps fragments).
     */
    public function tryLinkHtml(string $area, string $pack, array $options = []): ?string
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return null;
        }
        try {
            $url = $this->publishPack($area, $pack, $options);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS pack failed: ' . $e->getMessage());
            }

            return null;
        }
        if ($url === '') {
            return null;
        }
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attrs = ' rel="stylesheet" type="text/css" data-weline-theme-head-pack="'
            . htmlspecialchars($pack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if (!empty($options['layout_css'])) {
            $attrs .= ' data-weline-layout-css';
        }

        return '<link' . $attrs . ' href="' . $safe . '"/>';
    }

    /**
     * Whether tokens-post pack already absorbed the disk override (skip separate link).
     */
    public function diskIncludedInPack(string $area, string $pack): bool
    {
        return $this->isEnabled($area)
            && in_array($pack, [self::PACK_TOKENS_POST, self::PACK_TOKENS_MINIMAL, self::PACK_TOKENS_BACKEND], true);
    }

    private function publishPack(string $area, string $pack, array $options): string
    {
        $theme = $this->resolveTheme($area);
        $sources = $this->collectSources($area, $pack, $theme, $options);
        if ($sources === []) {
            return '';
        }
        $minify = !empty($this->resourceConfig->resolve($theme, $area)['css_minify']);
        $key = hash('sha256', json_encode([
            'theme-head-chrome-v1',
            $area,
            $pack,
            $minify,
            (int)($theme?->getId() ?? 0),
            array_column($sources, 'hash'),
        ], JSON_THROW_ON_ERROR));
        $target = $this->gateway->buildHeadChromeArtifact($key, $area, 'css');
        if ($target === null) {
            return '';
        }
        if (!is_file($target['path'])) {
            $chunks = [];
            foreach ($sources as $source) {
                $content = $source['content'];
                try {
                    $content = $this->publisher->rewriteCssUrls($content, $source['url']);
                } catch (\Throwable) {
                    // Keep original relative URLs when rewrite cannot prove safety.
                }
                if ($minify) {
                    $content = $this->minifier->minifyFileContent($content, 'css');
                }
                $chunks[] = '/* ' . $source['label'] . ' */' . "\n" . $content;
            }
            $body = implode("\n", $chunks);
            if ($body === '') {
                return '';
            }
            $this->atomicPublisher->publish($target['path'], $body);
        }

        return $target['url'];
    }

    /**
     * @return list<array{label:string,path:string,url:string,content:string,hash:string}>
     */
    private function collectSources(string $area, string $pack, ?WelineTheme $theme, array $options): array
    {
        $leaves = match ($pack) {
            self::PACK_TOKENS_PRE => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
            ],
            self::PACK_TOKENS_POST => [
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_spacing.css',
                'theme/' . $area . '/variables/_typography.css',
            ],
            self::PACK_TOKENS_MINIMAL => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_typography.css',
            ],
            self::PACK_TOKENS_BACKEND => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_dark.css',
            ],
            self::PACK_UI => $area === 'backend'
                ? ['statics:ui/weline-foundation.css', 'statics:ui/weline-backend.css']
                : ['statics:ui/weline-foundation.css', 'statics:ui/weline-frontend.css'],
            default => [],
        };
        $sources = [];
        foreach ($leaves as $leaf) {
            $resolved = str_starts_with($leaf, 'statics:')
                ? $this->resolveStaticsLeaf(substr($leaf, strlen('statics:')))
                : $this->resolveThemeLeaf($leaf, $theme);
            if ($resolved !== null) {
                $sources[] = $resolved;
            }
        }
        $includeDisk = ($options['include_disk'] ?? true)
            && in_array($pack, [self::PACK_TOKENS_POST, self::PACK_TOKENS_MINIMAL, self::PACK_TOKENS_BACKEND], true);
        if ($includeDisk) {
            $disk = $this->resolveDiskOverrideSource($area, $theme);
            if ($disk !== null) {
                $sources[] = $disk;
            }
        }

        return $sources;
    }

    /**
     * @return array{label:string,path:string,url:string,content:string,hash:string}|null
     */
    private function resolveThemeLeaf(string $relativeUnderTheme, ?WelineTheme $theme): ?array
    {
        $relativeUnderTheme = ltrim(str_replace('\\', '/', $relativeUnderTheme), '/');
        if (str_starts_with($relativeUnderTheme, 'theme/')) {
            $relativeUnderTheme = substr($relativeUnderTheme, strlen('theme/'));
        }
        $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
        if (!is_array($module)) {
            return null;
        }
        $modulePath = rtrim((string)$module['base_path'], '/\\') . '/view/theme/' . $relativeUnderTheme;
        $absolute = $modulePath;
        if ($theme instanceof WelineTheme && (int)$theme->getId() > 0) {
            $absolute = $this->pathResolver->resolveThemeFile($modulePath, $theme);
        }
        if (!is_file($absolute)) {
            return null;
        }
        $content = file_get_contents($absolute);
        if (!is_string($content) || $content === '') {
            return null;
        }
        $tagSource = 'Weline_Theme::theme/' . $relativeUnderTheme;
        $url = $this->fetchThemeUrl($tagSource);
        if ($url === '') {
            $url = '/static/Weline/Theme/view/theme/' . $relativeUnderTheme;
        }

        return [
            'label' => $relativeUnderTheme,
            'path' => $absolute,
            'url' => $url,
            'content' => $content,
            'hash' => hash('sha256', $absolute . '|' . filemtime($absolute) . '|' . $content),
        ];
    }

    /**
     * @return array{label:string,path:string,url:string,content:string,hash:string}|null
     */
    private function resolveStaticsLeaf(string $relativeUnderStatics): ?array
    {
        $relativeUnderStatics = ltrim(str_replace('\\', '/', $relativeUnderStatics), '/');
        $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
        if (!is_array($module)) {
            return null;
        }
        $absolute = rtrim((string)$module['base_path'], '/\\') . '/view/statics/' . $relativeUnderStatics;
        if (!is_file($absolute)) {
            return null;
        }
        $content = file_get_contents($absolute);
        if (!is_string($content) || $content === '') {
            return null;
        }
        $url = $this->fetchStaticsUrl('Weline_Theme::' . $relativeUnderStatics);
        if ($url === '') {
            $url = '/static/Weline/Theme/' . $relativeUnderStatics;
        }

        return [
            'label' => $relativeUnderStatics,
            'path' => $absolute,
            'url' => $url,
            'content' => $content,
            'hash' => hash('sha256', $absolute . '|' . filemtime($absolute) . '|' . $content),
        ];
    }

    /**
     * @return array{label:string,path:string,url:string,content:string,hash:string}|null
     */
    private function resolveDiskOverrideSource(string $area, ?WelineTheme $theme): ?array
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return null;
        }
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = 'default';
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $bundleMap = ThemeData::getConfigList($area, 'disk_bundle', $scope);
        $hash = (string)($bundleMap[$scope] ?? $bundleMap['default'] ?? '');
        if ($hash === '') {
            return null;
        }
        $path = $this->diskCompile->resolveBundlePath((int)$theme->getId(), $area, $scope, $hash);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return null;
        }
        $href = $this->diskHead->getOverrideHref($area, $theme, $scope);
        if ($href === '') {
            $href = '/theme/' . $area . '/disk/override';
        }

        return [
            'label' => 'disk-override.' . $hash,
            'path' => $path,
            'url' => $href,
            'content' => $content,
            'hash' => hash('sha256', $path . '|' . filemtime($path) . '|' . $content),
        ];
    }

    private function fetchThemeUrl(string $moduleSource): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            return (string)$template->fetchTagSource(DataInterface::dir_type_THEME, $moduleSource);
        } catch (\Throwable) {
            return '';
        }
    }

    private function fetchStaticsUrl(string $moduleSource): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            return (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $moduleSource);
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveTheme(string $area): ?WelineTheme
    {
        $current = ThemeData::getCurrentTheme();
        if ($current instanceof WelineTheme && (int)$current->getId() > 0) {
            return $current;
        }
        try {
            $resolved = $this->themeContext->resolveTheme($area)
                ?? $this->themeContext->resolveRegisteredDefaultTheme($area);

            return $resolved instanceof WelineTheme && (int)$resolved->getId() > 0 ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
