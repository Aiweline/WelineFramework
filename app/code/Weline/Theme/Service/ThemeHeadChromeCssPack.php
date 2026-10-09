<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ThemeApplicationContext;
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
 * Compile-time / runtime head chrome CSS packs for storefront/backend.
 *
 * Prefer PACK_STOREFRONT / PACK_BACKEND (one link each). Legacy fragment packs remain
 * for collectSources composition and merge=off fallbacks in templates.
 * Gated by resource_files/theme_css_merge (auto/on = always on). Source leaves stay editable;
 * runtime join may include theme.css + toast + **current theme overlay** bytes — never write
 * back into source theme.css. Artifacts publish under the **active theme** static namespace
 * and are keyed by **application scope** (scope_key/store_mode/…) so themes and scopes
 * never cross-pollute (design shell must not land in base Theme; website A must not reuse B).
 *
 * Overlay list (theme-owned): `theme/{area}/partials/head/theme-head-css-overlay.php`
 * returning list&lt;string&gt; of theme-relative CSS leaves. Design themes (hanfu/daocharms/…)
 * declare their own list; default Theme has none.
 *
 * work_mode=theme_module_runtime
 */
final class ThemeHeadChromeCssPack
{
    public const PACK_STOREFRONT = 'storefront';
    public const PACK_BACKEND = 'backend';
    public const PACK_TOKENS_PRE = 'tokens-pre';
    public const PACK_TOKENS_POST = 'tokens-post';
    public const PACK_TOKENS_MINIMAL = 'tokens-minimal';
    public const PACK_TOKENS_BACKEND = 'tokens-backend';
    public const PACK_UI = 'ui';

    /** Design/theme-owned overlay list relative to theme view root. */
    public const OVERLAY_LIST_RELATIVE = 'partials/head/theme-head-css-overlay.php';

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
            return true;
        }
    }

    /**
     * Publish area packs so Formal/com warm path does not cold-miss on first request.
     */
    public function warmAreaPacks(string $area = 'frontend'): void
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area)) {
            return;
        }
        $pack = $area === 'backend' ? self::PACK_BACKEND : self::PACK_STOREFRONT;
        try {
            $this->publishPack($area, $pack, ['include_disk' => true, 'layout_css' => true]);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS warm failed: ' . $e->getMessage());
            }
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
        if (!empty($options['layout_css']) || in_array($pack, [self::PACK_STOREFRONT, self::PACK_BACKEND, self::PACK_UI], true)) {
            $attrs .= ' data-weline-layout-css';
        }
        if ($pack === self::PACK_STOREFRONT && $this->themeOverlayAbsorbed($area, $pack)) {
            $attrs .= ' data-weline-theme-overlay="1"';
        }

        return '<link' . $attrs . ' href="' . $safe . '"/>';
    }

    /**
     * Whether the pack already absorbed the disk override (skip separate link).
     */
    public function diskIncludedInPack(string $area, string $pack): bool
    {
        return $this->isEnabled($area)
            && in_array($pack, [
                self::PACK_STOREFRONT,
                self::PACK_BACKEND,
                self::PACK_TOKENS_POST,
                self::PACK_TOKENS_MINIMAL,
                self::PACK_TOKENS_BACKEND,
            ], true);
    }

    /**
     * Whether STOREFRONT pack absorbed this theme's design overlay CSS (skip skin CSS tags).
     * Ownership follows the active theme; no overlay file ⇒ false (base Theme / no-theme).
     */
    public function themeOverlayAbsorbed(string $area, string $pack): bool
    {
        $area = $this->themeContext->normalizeArea($area);
        if (!$this->isEnabled($area) || $pack !== self::PACK_STOREFRONT) {
            return false;
        }
        $theme = $this->resolveTheme($area);
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return false;
        }

        return $this->collectThemeOverlaySources($area, $theme) !== [];
    }

    private function publishPack(string $area, string $pack, array $options): string
    {
        $theme = $this->resolveTheme($area);
        $sources = $this->collectSources($area, $pack, $theme, $options);
        if ($sources === []) {
            return '';
        }
        // Base Theme shell must never absorb leaves resolved from app/design/* (scope/theme pollution).
        if ($pack === self::PACK_STOREFRONT && $this->isBaseThemeShell($theme)) {
            $filtered = [];
            foreach ($sources as $source) {
                $sourcePath = str_replace('\\', '/', (string)($source['path'] ?? ''));
                if ($sourcePath !== '' && str_contains($sourcePath, '/app/design/')) {
                    continue;
                }
                $filtered[] = $source;
            }
            $sources = $filtered;
            if ($sources === []) {
                return '';
            }
        }
        $minify = !empty($this->resourceConfig->resolve($theme, $area)['css_minify']);
        $themeIdentity = '';
        if ($theme instanceof WelineTheme) {
            try {
                $themeIdentity = (string)$theme->getPath();
            } catch (\Throwable) {
                $themeIdentity = '';
            }
            if ($themeIdentity === '') {
                try {
                    $themeIdentity = (string)$theme->getName();
                } catch (\Throwable) {
                    $themeIdentity = '';
                }
            }
        }
        $scopeFp = $this->resolvePackScopeFingerprint($area);
        $key = hash('sha256', json_encode([
            'theme-head-chrome-v4',
            $area,
            $pack,
            $minify,
            (int)($theme?->getId() ?? 0),
            $themeIdentity,
            $scopeFp,
            array_column($sources, 'hash'),
        ], JSON_THROW_ON_ERROR));
        // Force the same theme object used for sources — never re-resolve to another namespace.
        $target = $this->gateway->buildHeadChromeArtifact($key, $area, 'css', $theme);
        if ($target === null) {
            return '';
        }
        if (!$this->artifactPathBelongsToTheme($target['path'], $theme)) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Theme head chrome CSS refused: artifact namespace mismatch theme='
                    . $themeIdentity . ' path=' . $target['path']);
            }

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
     * Application scope fingerprint so packs never cross website/store/channel pollution.
     *
     * @return array<string, scalar>
     */
    private function resolvePackScopeFingerprint(string $area): array
    {
        try {
            $app = ThemeApplicationContext::current($area);
            if ($app instanceof ThemeApplicationContext) {
                return [
                    'scope_key' => $app->scopeKey,
                    'store_mode' => $app->storeMode,
                    'theme_id' => $app->themeId,
                    'provider' => $app->provider,
                    'version_owner_scope' => $app->versionOwnerScope,
                    'theme_version_id' => $app->themeVersionId,
                    'content_revision' => $app->contentRevision,
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'scope_key' => 'unbound',
            'store_mode' => '',
            'theme_id' => 0,
            'provider' => '',
            'version_owner_scope' => '',
            'theme_version_id' => 0,
            'content_revision' => 0,
        ];
    }

    private function isBaseThemeShell(?WelineTheme $theme): bool
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return true;
        }
        try {
            $path = str_replace('\\', '/', (string)$theme->getPath());
        } catch (\Throwable) {
            $path = '';
        }
        if ($path === '') {
            return true;
        }
        // Design themes live under app/design/{Vendor}/{name}/ — base module shell under Theme/view/theme.
        if (str_contains($path, '/app/design/')) {
            return false;
        }

        return str_contains($path, '/Weline/Theme/view/theme')
            || str_ends_with(rtrim($path, '/'), '/Theme/view/theme');
    }

    private function artifactPathBelongsToTheme(string $absolutePath, ?WelineTheme $theme): bool
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return false;
        }
        try {
            $ns = trim(str_replace('\\', '/', (string)ObjectManager::getInstance(
                ThemeStaticNamespaceService::class
            )->resolvePublicThemePath($theme)), '/');
        } catch (\Throwable) {
            return false;
        }
        if ($ns === '') {
            return false;
        }
        $norm = str_replace('\\', '/', $absolutePath);

        return str_contains($norm, '/pub/static/' . $ns . '/');
    }

    /**
     * @return list<array{label:string,path:string,url:string,content:string,hash:string}>
     */
    private function collectSources(string $area, string $pack, ?WelineTheme $theme, array $options): array
    {
        $leaves = match ($pack) {
            self::PACK_STOREFRONT => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_spacing.css',
                // Size/misc leaves for widget token consumption (REQ-THEME-0007); must ship with pack.
                'theme/' . $area . '/variables/_auto-literals.css',
                'theme/' . $area . '/variables/_typography.css',
                'statics:ui/weline-foundation.css',
                'statics:ui/weline-frontend.css',
                'theme/' . $area . '/assets/css/theme.css',
                'statics:css/widgets/storefront-shopper-toast-amazon.css',
            ],
            self::PACK_BACKEND => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_auto-literals.css',
                'statics:ui/weline-foundation.css',
                'statics:ui/weline-backend.css',
            ],
            self::PACK_TOKENS_PRE => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
            ],
            self::PACK_TOKENS_POST => [
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_spacing.css',
                'theme/' . $area . '/variables/_auto-literals.css',
                'theme/' . $area . '/variables/_typography.css',
            ],
            self::PACK_TOKENS_MINIMAL => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_ink.css',
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_auto-literals.css',
                'theme/' . $area . '/variables/_typography.css',
            ],
            self::PACK_TOKENS_BACKEND => [
                'theme/' . $area . '/colors/_light.css',
                'theme/' . $area . '/colors/_default.css',
                'theme/' . $area . '/colors/_dark.css',
                'theme/' . $area . '/variables/_auto-literals.css',
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
            && in_array($pack, [
                self::PACK_STOREFRONT,
                self::PACK_BACKEND,
                self::PACK_TOKENS_POST,
                self::PACK_TOKENS_MINIMAL,
                self::PACK_TOKENS_BACKEND,
            ], true);
        if ($includeDisk) {
            $disk = $this->resolveDiskOverrideSource($area, $theme);
            if ($disk !== null) {
                // Insert disk after token/variable leaves, before foundation/UI for storefront/backend shells.
                $insertAt = $this->diskInsertIndex($pack, $sources);
                array_splice($sources, $insertAt, 0, [$disk]);
            }
        }
        // Theme-owned brand/shell CSS after core stack; artifact path already under this theme namespace.
        if ($pack === self::PACK_STOREFRONT && ($options['include_theme_overlay'] ?? true)) {
            foreach ($this->collectThemeOverlaySources($area, $theme) as $overlay) {
                $sources[] = $overlay;
            }
        }

        return $sources;
    }

    /**
     * @return list<array{label:string,path:string,url:string,content:string,hash:string}>
     */
    private function collectThemeOverlaySources(string $area, ?WelineTheme $theme): array
    {
        if (!$theme instanceof WelineTheme || (int)$theme->getId() < 1) {
            return [];
        }
        $leaves = $this->loadThemeOverlayLeafList($area, $theme);
        if ($leaves === []) {
            return [];
        }
        $sources = [];
        foreach ($leaves as $leaf) {
            $resolved = $this->resolveThemeLeaf($leaf, $theme);
            if ($resolved !== null) {
                $sources[] = $resolved;
            }
        }

        return $sources;
    }

    /**
     * @return list<string> theme-relative CSS paths (e.g. theme/frontend/assets/css/hanfu-ink.css)
     */
    private function loadThemeOverlayLeafList(string $area, WelineTheme $theme): array
    {
        $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
        if (!is_array($module)) {
            return [];
        }
        $modulePath = rtrim((string)$module['base_path'], '/\\')
            . '/view/theme/' . $area . '/' . self::OVERLAY_LIST_RELATIVE;
        $absolute = $this->pathResolver->resolveThemeFile($modulePath, $theme);
        if (!is_string($absolute) || $absolute === '' || !is_file($absolute)) {
            return [];
        }
        // Only accept design/theme override — never the missing base stub as brand list.
        $normalizedAbs = str_replace('\\', '/', $absolute);
        $normalizedMod = str_replace('\\', '/', $modulePath);
        if ($normalizedAbs === $normalizedMod) {
            return [];
        }
        try {
            /** @var mixed $list */
            $list = include $absolute;
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = ltrim(str_replace('\\', '/', trim($item)), '/');
            if ($item === '' || str_contains($item, '..')) {
                continue;
            }
            if (!str_starts_with($item, 'theme/')) {
                $item = 'theme/' . $item;
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<array{label:string,path:string,url:string,content:string,hash:string}> $sources
     */
    private function diskInsertIndex(string $pack, array $sources): int
    {
        if (!in_array($pack, [self::PACK_STOREFRONT, self::PACK_BACKEND], true)) {
            return count($sources);
        }
        foreach ($sources as $i => $source) {
            if (str_contains($source['label'], 'weline-foundation.css')) {
                return $i;
            }
        }

        return count($sources);
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
        // Disk bundle is scope-bound — never hardcode default when application scope is known.
        $scope = 'default';
        try {
            $app = ThemeApplicationContext::current($area);
            if ($app instanceof ThemeApplicationContext && $app->scopeKey !== '') {
                $scope = ThemeDiskKeys::normalizeScope($app->scopeKey);
            }
        } catch (\Throwable) {
            $scope = 'default';
        }
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
