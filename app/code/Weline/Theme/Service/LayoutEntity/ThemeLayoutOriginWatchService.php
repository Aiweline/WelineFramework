<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Fpc\FpcStaticDiskStore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Service\ThemeApplicationUsageService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;
use Weline\Widget\Model\WidgetRegistryEntry;

/**
 * Dev-only: watch bound theme PHTML + CSS/JS + external module widget sources, then rebake/publish.
 * Scope: themesForDefaultUpgrade() theme roots → **.phtml** and **.css/.js**; plus `__ext__/{Module}/…`
 *          for layout-embedded / ledger-injected widgets whose files live outside the theme tree
 *          (other modules, or parent Theme module when custom theme does not override):
 *          `__ext__/{Module}/{type}/{code}.phtml` and `__ext__/…/asset/*.{css,js}` from @widget.source /
 *          layout_source. Ext asset changes → assets publish **and** widget cascade (force_pages bake)
 *          **and** `FpcStaticDiskStore::republishFromFileOnAllHosts` for module direct-link live mirrors
 *          (Nginx try_files `generated/static/live/{host}/…` must not lag widget-assets packs).
 * Module-prefix statics (once per run, shared manifest): enabled modules'
 *          app/code/{Vendor}/{Module}/view/statics tree (.css/.js) keyed as `__modstatic__/…` →
 *          **only** live remirror (no theme assets bake / widget cascade / layout FPC purge).
 * widgets: ledger layout_type first; else quick-scan `<w:widget type/name|code[/module]>` embeds
 *          (not slot accept=). Explicit ledger `*` → full theme.
 * shared templates: solidify all layouts. chrome-only → heal only.
 * assets css/js (theme tree): publishStaticAssets + warmAreaPacks + .br sidecars — never full-theme bake.
 * After solidify (+ Taglib compile) / chrome heal / assets publish → layout-scoped FPC purge (impact.slugs).
 * Never clearAllThemeRelatedCaches. Never purgeAllLive for one asset. Non-dev: Cron skip:non_dev.
 */
final class ThemeLayoutOriginWatchService
{
    private const MANIFEST_NAME = '.origin-watch.json';
    private const MANIFEST_VERSION = 2;
    private const MODULE_STATIC_PREFIX = '__modstatic__/';
    private const MODULE_STATIC_MANIFEST_DIR = '_module_statics';
    /** Soft cap per module to keep DEV cron bounded (mtime/size reuse after bootstrap). */
    private const MODULE_STATIC_MAX_FILES_PER_MODULE = 800;
    private const CACHE_CLEAR_REASON = 'theme_layout_origin_watch';
    private const CACHE_CLEAR_REASON_ASSETS = 'theme_layout_origin_watch_assets';

    public function __construct(
        private readonly ThemeApplicationUsageService $themeApplicationUsage,
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly ThemeLayoutEntityUpgradeSolidifyService $solidify,
        private readonly ThemeLayoutEntityBakeCoordinator $bakeCoordinator,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeStorefrontSlugFpcPublisher $slugFpcPublisher,
    ) {
    }

    /**
     * @return array{
     *   themes_scanned:int,
     *   changed:int,
     *   solidified:int,
     *   chrome_healed:int,
     *   cache_cleared:int,
     *   static_published:int,
     *   skipped_files:int,
     *   bootstrapped:int,
     *   messages:list<string>
     * }
     */
    public function run(): array
    {
        $report = [
            'themes_scanned' => 0,
            'changed' => 0,
            'solidified' => 0,
            'chrome_healed' => 0,
            'cache_cleared' => 0,
            'static_published' => 0,
            'skipped_files' => 0,
            'bootstrapped' => 0,
            'messages' => [],
        ];

        foreach ($this->themeApplicationUsage->themesForDefaultUpgrade() as $theme) {
            ++$report['themes_scanned'];
            $themeId = (int)$theme->getId();
            if ($themeId < 1) {
                continue;
            }
            try {
                $result = $this->processTheme($theme);
            } catch (\Throwable $e) {
                $report['messages'][] = 'theme_' . $themeId . '_error:' . $e->getMessage();
                continue;
            }
            if (!empty($result['bootstrapped'])) {
                ++$report['bootstrapped'];
            }
            if (!empty($result['changed'])) {
                ++$report['changed'];
            }
            $report['solidified'] += (int)($result['solidified'] ?? 0);
            $report['chrome_healed'] += (int)($result['chrome_healed'] ?? 0);
            $report['cache_cleared'] += (int)($result['cache_cleared'] ?? 0);
            $report['static_published'] += (int)($result['static_published'] ?? 0);
            $report['skipped_files'] += (int)($result['skipped_files'] ?? 0);
            foreach ((array)($result['messages'] ?? []) as $msg) {
                $report['messages'][] = (string)$msg;
            }
        }

        // Shared once: module-prefix /Vendor/Module/view/statics (not per-theme).
        try {
            $mod = $this->processModulePrefixStatics();
            if (!empty($mod['bootstrapped'])) {
                ++$report['bootstrapped'];
            }
            if (!empty($mod['changed'])) {
                ++$report['changed'];
            }
            $report['static_published'] += (int)($mod['static_published'] ?? 0);
            foreach ((array)($mod['messages'] ?? []) as $msg) {
                $report['messages'][] = (string)$msg;
            }
        } catch (\Throwable $e) {
            $report['messages'][] = 'module_statics_error:' . $e->getMessage();
        }

        return $report;
    }

    /**
     * Watch enabled modules' view/statics css/js → remirror Nginx live FPC leaves.
     * not_to_do: theme assets bake; widget cascade; clearAllThemeRelatedCaches; purgeAllLive.
     *
     * @return array{changed:bool,bootstrapped:bool,static_published:int,messages:list<string>}
     */
    private function processModulePrefixStatics(): array
    {
        $out = [
            'changed' => false,
            'bootstrapped' => false,
            'static_published' => 0,
            'messages' => [],
        ];
        $previous = $this->watchedManifestFiles($this->readModuleStaticsManifest());
        $snapshot = $this->buildModulePrefixStaticsSnapshot($previous);
        if ($previous === null) {
            $this->writeModuleStaticsManifest($snapshot);
            $out['bootstrapped'] = true;
            $out['messages'][] = 'module_statics:bootstrapped:' . \count($snapshot);

            return $out;
        }
        $diff = $this->diffSnapshot($previous, $snapshot);
        if ($diff['changed_paths'] === []) {
            if ($diff['manifest_dirty']) {
                $this->writeModuleStaticsManifest($snapshot);
            }

            return $out;
        }
        $out['changed'] = true;
        $mirrored = $this->refreshLiveStaticMirrorsForChangedPaths($diff['changed_paths']);
        $out['static_published'] = $mirrored;
        if ($mirrored > 0) {
            $out['messages'][] = 'module_statics:live_static_mirror:' . $mirrored;
        } else {
            $out['messages'][] = 'module_statics:changed:' . \count($diff['changed_paths']);
        }
        $this->writeModuleStaticsManifest($snapshot);

        return $out;
    }

    /**
     * @return array{
     *   changed:bool,
     *   bootstrapped:bool,
     *   solidified:int,
     *   chrome_healed:int,
     *   cache_cleared:int,
     *   static_published:int,
     *   skipped_files:int,
     *   messages:list<string>
     * }
     */
    private function processTheme(WelineTheme $theme): array
    {
        $themeId = (int)$theme->getId();
        // Keep phtml + css/js; drop other legacy keys (images/fonts) so scope stay bounded.
        $previous = $this->watchedManifestFiles($this->readManifest($themeId));
        $snapshot = $this->buildSnapshot($theme, $previous);
        $out = [
            'changed' => false,
            'bootstrapped' => false,
            'solidified' => 0,
            'chrome_healed' => 0,
            'cache_cleared' => 0,
            'static_published' => 0,
            'skipped_files' => 0,
            'messages' => [],
        ];

        if ($previous === null) {
            $this->writeManifest($themeId, $snapshot);
            $out['bootstrapped'] = true;

            return $out;
        }

        $diff = $this->diffSnapshot($previous, $snapshot);
        if ($diff['changed_paths'] === []) {
            // Refresh mtime/size cache even when content unchanged (mtime-coarse reuse).
            if ($diff['manifest_dirty']) {
                $this->writeManifest($themeId, $snapshot);
            }

            // H1c 辅：源未变但 generated chrome digest 漂移 — 实现见类后部方法（保 solidify≺clear 契约序）。
            return $this->finalizeUnchangedOriginWatchWithChromeDigestDriftHeal($theme, $themeId, $out);
        }

        $out['changed'] = true;
        $classified = $this->classifyChangedPaths($diff['changed_paths']);
        $out['skipped_files'] = \count($classified['skipped']);
        foreach ($classified['skipped'] as $path) {
            $out['messages'][] = 'skip_unmapped:' . $path;
        }

        $assetsChanged = !empty($classified['assets']);
        if ($assetsChanged) {
            $this->publishThemeStaticAssets($theme, $out);
            $liveMirrored = $this->refreshLiveStaticMirrorsForChangedPaths($diff['changed_paths']);
            if ($liveMirrored > 0) {
                $out['messages'][] = 'assets:live_static_mirror:' . $liveMirrored;
            }
        }

        $targets = $this->themeApplicationUsage->ownSolidifyTargetsForTheme($themeId);
        $ensured = $this->ensureBoundScopes($themeId, $targets);

        if ($classified['chrome'] && $ensured !== []) {
            $healed = $this->healChrome($themeId, $ensured);
            $out['chrome_healed'] = $healed;
        }

        $cascade = $this->resolveSolidifyCascade($theme, $classified);
        // Widget/template deps are outside layout PageInputDigest — force pages past skip_unchanged.
        $forcePages = $classified['widgets'] !== [] || $classified['templates'] !== [];
        $layoutsForPurge = $cascade['layouts'];
        if ($cascade['full_theme']) {
            $out['solidified'] = $this->solidify->solidifyTheme($themeId, null, $forcePages ? ['force_pages' => true] : []);
            $out['messages'][] = $forcePages ? 'cascade:full_theme:force_pages' : 'cascade:full_theme';
            // Full-theme bake touched every layout file → purge those layouts' FPC only (not site-wide staging).
            $layoutsForPurge = $this->allFrontendLayoutsForTheme($theme);
        } elseif ($cascade['layouts'] !== []) {
            $out['solidified'] = $this->solidifyLayoutChanges($themeId, $cascade['layouts'], $forcePages);
            $suffix = ($forcePages ? ':force_pages' : '')
                . (!empty($cascade['embed_scan']) ? ':embed_scan' : '');
            $out['messages'][] = 'cascade:layouts:' . \count($cascade['layouts']) . $suffix;
        } elseif ($out['chrome_healed'] > 0 || $assetsChanged) {
            // Shared chrome / static assets affect storefront HTML references — purge layout FPC leaves.
            $layoutsForPurge = $this->allFrontendLayoutsForTheme($theme);
        }

        // Order (硬): solidify / chrome heal / assets publish → then layout-scoped slug FPC purge.
        if ($out['solidified'] > 0 || $out['chrome_healed'] > 0 || $assetsChanged) {
            $reason = ($assetsChanged && $out['solidified'] === 0 && $out['chrome_healed'] === 0)
                ? self::CACHE_CLEAR_REASON_ASSETS
                : self::CACHE_CLEAR_REASON;
            $out['cache_cleared'] = $this->clearStorefrontCachesAfterBake(
                $themeId,
                $targets,
                $layoutsForPurge,
                $out['messages'],
                $reason,
            );
        }

        $this->writeManifest($themeId, $snapshot);

        return $out;
    }

    /**
     * Dev assets path: publish design/module statics (writes .br via sidecar) + warm head packs.
     * Never triggers layout bake.
     *
     * @param array{
     *   static_published:int,
     *   messages:list<string>
     * } $out
     */
    private function publishThemeStaticAssets(WelineTheme $theme, array &$out): void
    {
        try {
            /** @var \Weline\Theme\Console\Theme\Upgrade $upgrade */
            $upgrade = ObjectManager::getInstance(\Weline\Theme\Console\Theme\Upgrade::class);
            $upgrade->publishStaticAssets($theme);
            $out['static_published'] = 1;
            $out['messages'][] = 'assets:publishStaticAssets';
        } catch (\Throwable $e) {
            $out['messages'][] = 'assets:publish_error:' . $e->getMessage();

            return;
        }
        try {
            /** @var \Weline\Theme\Service\ThemeHeadChromeCssPack $cssPack */
            $cssPack = ObjectManager::getInstance(\Weline\Theme\Service\ThemeHeadChromeCssPack::class);
            /** @var \Weline\Theme\Service\ThemeHeadChromeJsPack $jsPack */
            $jsPack = ObjectManager::getInstance(\Weline\Theme\Service\ThemeHeadChromeJsPack::class);
            $cssPack->warmAreaPacks('frontend');
            $cssPack->warmAreaPacks('backend');
            $jsPack->warmAreaPacks('frontend');
            $jsPack->warmAreaPacks('backend');
            $out['messages'][] = 'assets:warmAreaPacks';
        } catch (\Throwable $e) {
            $out['messages'][] = 'assets:warm_error:' . $e->getMessage();
        }
    }

    /**
     * After `__ext__/…/asset/*.{css,js}` or `__modstatic__/…` changes: refresh Nginx live FPC mirrors.
     * Framework owns the disk store; Theme only maps watched paths → source abs → URI.
     * not_to_do: purgeAllLive; invent hosts; rewrite live paths outside FpcStaticDiskStore.
     *
     * @param list<string> $changedPaths
     */
    private function refreshLiveStaticMirrorsForChangedPaths(array $changedPaths): int
    {
        $ok = 0;
        $seenUri = [];
        foreach ($changedPaths as $path) {
            $path = \str_replace('\\', '/', (string)$path);
            if (\str_starts_with($path, self::MODULE_STATIC_PREFIX)) {
                $ok += $this->republishModulePrefixStaticPath($path, $seenUri);
                continue;
            }
            $class = $this->classifyRelativePath($path);
            if (!\is_array($class) || empty($class['asset'])) {
                continue;
            }
            $module = \trim((string)($class['module'] ?? ''));
            $type = \trim((string)($class['type'] ?? ''));
            $code = \trim((string)($class['code'] ?? ''));
            if ($module === '' || $type === '' || $code === '') {
                continue;
            }
            $basename = \basename($path);
            if ($basename === '' || !\preg_match('/\.(css|js)$/i', $basename)) {
                continue;
            }
            foreach ($this->resolveWidgetStaticAbsolutes($module, $type, $code) as $abs) {
                if (\basename($abs) !== $basename) {
                    continue;
                }
                $uri = FpcStaticDiskStore::uriFromAppCodeAbsolute($abs);
                if ($uri === '' || isset($seenUri[$uri])) {
                    continue;
                }
                $seenUri[$uri] = true;
                $ok += FpcStaticDiskStore::republishFromFileOnAllHosts($uri, $abs);
            }
        }

        return $ok;
    }

    /**
     * @param array<string, true> $seenUri
     */
    private function republishModulePrefixStaticPath(string $relativeKey, array &$seenUri): int
    {
        $rel = \substr($relativeKey, \strlen(self::MODULE_STATIC_PREFIX));
        $rel = \ltrim(\str_replace('\\', '/', $rel), '/');
        if ($rel === '' || \str_contains($rel, '..') || !\preg_match('/\.(css|js)$/i', $rel)) {
            return 0;
        }
        $base = \defined('BP') ? \rtrim(\str_replace('\\', '/', (string)\constant('BP')), '/') : '';
        if ($base === '') {
            return 0;
        }
        $abs = $base . '/app/code/' . $rel;
        if (!\is_file($abs)) {
            return 0;
        }
        $uri = FpcStaticDiskStore::uriFromAppCodeAbsolute($abs);
        if ($uri === '' || isset($seenUri[$uri])) {
            return 0;
        }
        $seenUri[$uri] = true;

        return FpcStaticDiskStore::republishFromFileOnAllHosts($uri, $abs);
    }

    /**
     * Purge only FPC leaves for the layouts involved in this bake (impact.slugs).
     *
     * @param list<array{
     *   theme_id?:int,
     *   area?:string,
     *   store_mode?:string,
     *   version_owner_scope?:string
     * }> $targets
     * @param list<array{type:string,option:string}> $layouts
     * @param list<string> $messages
     */
    private function clearStorefrontCachesAfterBake(
        int $themeId,
        array $targets,
        array $layouts,
        array &$messages,
        string $reason = self::CACHE_CLEAR_REASON,
    ): int {
        if ($layouts === []) {
            $messages[] = 'cache_clear_skipped:no_layouts';

            return 0;
        }
        try {
            $result = $this->slugFpcPublisher->purgeLayoutsForThemeTargets(
                $themeId,
                $layouts,
                $targets,
                $reason !== '' ? $reason : self::CACHE_CLEAR_REASON,
            );
            if (!($result['ok'] ?? false)) {
                $messages[] = 'cache_clear_skipped:' . (string)($result['skipped'] ?? 'unknown')
                    . (($result['errors'] ?? []) !== [] ? ' ' . \implode(' | ', (array)$result['errors']) : '');

                return 0;
            }
            $messages[] = 'cache_cleared:slugs=' . (int)($result['slug_count'] ?? 0)
                . ':targets=' . (int)($result['target_count'] ?? 0);

            return 1;
        } catch (\Throwable $e) {
            $messages[] = 'cache_clear_error:' . $e->getMessage();

            return 0;
        }
    }

    /**
     * Expand to every frontend layout type/option declared for this theme (catalog scan).
     *
     * @return list<array{type:string,option:string}>
     */
    private function allFrontendLayoutsForTheme(WelineTheme $theme): array
    {
        $out = [];
        try {
            $catalog = LayoutScanner::scanLayouts($theme, 'frontend');
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($catalog)) {
            return [];
        }
        foreach ($catalog as $type => $options) {
            $type = \trim((string)$type);
            if ($type === '') {
                continue;
            }
            if (!\is_array($options) || $options === []) {
                $out[] = ['type' => $type, 'option' => 'default'];
                continue;
            }
            foreach ($options as $option) {
                if (\is_array($option)) {
                    $option = (string)($option['value'] ?? $option['option'] ?? 'default');
                }
                $option = \trim((string)$option);
                if ($option === '') {
                    $option = 'default';
                }
                $out[] = ['type' => $type, 'option' => $option];
            }
        }

        return $out;
    }

    /**
     * Merge layout-file changes + widget ledger layout_types + layout `<w:widget>` embeds
     * + shared-template full-theme. Slot accept= lists are not embeds.
     *
     * @param array{
     *   layouts:list<array{type:string,option:string}>,
     *   chrome:bool,
     *   widgets:list<array{module?:string,type:string,code:string,path:string}>,
     *   templates:list<string>,
     *   skipped:list<string>
     * } $classified
     * @return array{full_theme:bool,layouts:list<array{type:string,option:string}>,embed_scan:bool}
     */
    private function resolveSolidifyCascade(WelineTheme $theme, array $classified): array
    {
        $layouts = $classified['layouts'];
        $embedScan = false;
        if ($classified['templates'] !== []) {
            return ['full_theme' => true, 'layouts' => [], 'embed_scan' => false];
        }

        foreach ($classified['widgets'] as $widget) {
            $module = \trim((string)($widget['module'] ?? 'Weline_Theme'));
            if ($module === '') {
                $module = 'Weline_Theme';
            }
            $types = $this->layoutTypesFromWidgetLedger($widget['type'], $widget['code']);
            if ($types !== null && \in_array('*', $types, true)) {
                return ['full_theme' => true, 'layouts' => [], 'embed_scan' => false];
            }
            if ($types === null || $types === []) {
                // Layout-embed path (XOR with JSON injections): quick-scan real <w:widget> tags.
                $types = $this->layoutTypesFromWidgetEmbeds(
                    $theme,
                    $widget['type'],
                    $widget['code'],
                    $module,
                );
                $embedScan = true;
                if ($types === []) {
                    // Not embedded for this module/type/code — do not expand to full theme.
                    continue;
                }
            }
            foreach ($types as $layoutType) {
                $layoutType = \trim((string)$layoutType);
                if ($layoutType === '') {
                    continue;
                }
                $layouts[] = ['type' => $layoutType, 'option' => '*'];
            }
        }

        return ['full_theme' => false, 'layouts' => $layouts, 'embed_scan' => $embedScan];
    }

    /**
     * Fast scan of this theme (+ parents) layout PHTML for `<w:widget type/name|code[/module]>`.
     * Does not match slot accept= allow-lists.
     *
     * @return list<string> layout_type values that embed the widget
     */
    private function layoutTypesFromWidgetEmbeds(
        WelineTheme $theme,
        string $type,
        string $code,
        string $module = 'Weline_Theme',
    ): array {
        $type = \trim($type);
        $code = \trim($code);
        $module = \trim($module) !== '' ? \trim($module) : 'Weline_Theme';
        if ($type === '' || $code === '') {
            return [];
        }
        $found = [];
        foreach ($this->iterateThemeLayoutFiles($theme) as $absolute => $relative) {
            $html = @\file_get_contents($absolute);
            if (!\is_string($html) || $html === '' || !\str_contains($html, '<w:widget')) {
                continue;
            }
            if (!$this->layoutHtmlEmbedsWidget($html, $type, $code, $module)) {
                continue;
            }
            $class = $this->classifyRelativePath($relative);
            if (($class['kind'] ?? '') !== 'layout') {
                continue;
            }
            $layoutType = \trim((string)($class['type'] ?? ''));
            if ($layoutType !== '') {
                $found[$layoutType] = true;
            }
        }

        return \array_keys($found);
    }

    /**
     * @return \Generator<string, string> absolute => layouts/... relative
     */
    private function iterateThemeLayoutFiles(WelineTheme $theme): \Generator
    {
        $cursor = $theme;
        $guard = 0;
        while ($cursor instanceof WelineTheme && $guard++ < 8) {
            $themePath = \rtrim(\str_replace('\\', '/', (string)$cursor->getPath()), '/');
            if ($themePath !== '' && \is_dir($themePath)) {
                foreach ($this->watchRoots($themePath) as $root) {
                    $layoutsDir = \rtrim((string)$root['absolute'], '/') . '/layouts';
                    if (!\is_dir($layoutsDir)) {
                        continue;
                    }
                    try {
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator(
                                $layoutsDir,
                                \FilesystemIterator::SKIP_DOTS
                            )
                        );
                    } catch (\Throwable) {
                        continue;
                    }
                    foreach ($iterator as $item) {
                        if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                            continue;
                        }
                        if (!\str_ends_with(\strtolower($item->getFilename()), '.phtml')) {
                            continue;
                        }
                        $absolute = \str_replace('\\', '/', $item->getPathname());
                        $pos = \strrpos($absolute, '/layouts/');
                        if ($pos === false) {
                            continue;
                        }
                        $relative = 'layouts/' . \substr($absolute, $pos + \strlen('/layouts/'));
                        yield $absolute => $relative;
                    }
                }
            }
            try {
                $parent = $cursor->getParentTheme();
            } catch (\Throwable) {
                $parent = null;
            }
            $cursor = $parent instanceof WelineTheme ? $parent : null;
        }
    }

    /**
     * True when layout source contains a real `<w:widget>` tag for module+type+name/code.
     * Omitted module attribute defaults to Weline_Theme.
     */
    private function layoutHtmlEmbedsWidget(
        string $html,
        string $type,
        string $code,
        string $module = 'Weline_Theme',
    ): bool {
        $module = \trim($module) !== '' ? \trim($module) : 'Weline_Theme';
        if (\preg_match_all('#<w:widget\b([^>]*)/?>#i', $html, $matches, \PREG_SET_ORDER) < 1) {
            return false;
        }
        foreach ($matches as $match) {
            $attrs = (string)($match[1] ?? '');
            $wType = $this->widgetTagAttr($attrs, 'type');
            $wCode = $this->widgetTagAttr($attrs, 'name');
            if ($wCode === '') {
                $wCode = $this->widgetTagAttr($attrs, 'code');
            }
            $wModule = $this->widgetTagAttr($attrs, 'module');
            if ($wModule === '') {
                $wModule = 'Weline_Theme';
            }
            if ($wType === $type && $wCode === $code && $wModule === $module) {
                return true;
            }
        }

        return false;
    }

    private function widgetTagAttr(string $attrs, string $name): string
    {
        $name = \preg_quote($name, '/');
        if (\preg_match('/\b' . $name . '\s*=\s*"([^"]*)"/i', $attrs, $m) === 1) {
            return \trim((string)$m[1]);
        }
        if (\preg_match('/\b' . $name . "\s*=\s*'([^']*)'/i", $attrs, $m) === 1) {
            return \trim((string)$m[1]);
        }

        return '';
    }

    /**
     * Read default_injections.layout_type from widget_registry_entry (extends collect SoT).
     * null = no ledger plan (try layout `<w:widget>` embed scan instead of full-theme).
     *
     * @return list<string>|null
     */
    private function layoutTypesFromWidgetLedger(string $type, string $code): ?array
    {
        $type = \trim($type);
        $code = \trim($code);
        if ($type === '' || $code === '') {
            return null;
        }
        try {
            /** @var WidgetRegistryEntry $model */
            $model = ObjectManager::getInstance(WidgetRegistryEntry::class);
            $rows = (clone $model)->clearQuery()->clearData()
                ->where(WidgetRegistryEntry::schema_fields_IS_ACTIVE, 1)
                ->where(WidgetRegistryEntry::schema_fields_WIDGET_TYPE, $type)
                ->where(WidgetRegistryEntry::schema_fields_WIDGET_CODE, $code)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($rows) || $rows === []) {
            return null;
        }
        if (!\array_is_list($rows) && (
            isset($rows[WidgetRegistryEntry::schema_fields_WIDGET_CODE])
            || isset($rows[WidgetRegistryEntry::schema_fields_ID])
        )) {
            $rows = [$rows];
        }

        $types = [];
        $sawPlan = false;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (!(int)($row[WidgetRegistryEntry::schema_fields_HAS_DEFAULT_INJECTIONS] ?? 0)) {
                continue;
            }
            $injections = $this->decodeInjectionList(
                $row[WidgetRegistryEntry::schema_fields_DEFAULT_INJECTIONS_JSON] ?? null
            );
            if ($injections === []) {
                $registry = $this->decodeJsonObject(
                    $row[WidgetRegistryEntry::schema_fields_REGISTRY_JSON] ?? null
                );
                $injections = $this->decodeInjectionList(
                    $registry['default_injections'] ?? ($registry['config']['default_injections'] ?? [])
                );
            }
            if ($injections === []) {
                continue;
            }
            $sawPlan = true;
            foreach ($injections as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $layoutTypes = $item['layout_type'] ?? $item['page_type'] ?? $item['page_layouts'] ?? null;
                if ($layoutTypes === null || $layoutTypes === '') {
                    $types['*'] = true;
                    continue;
                }
                if (!\is_array($layoutTypes)) {
                    $layoutTypes = [$layoutTypes];
                }
                foreach ($layoutTypes as $lt) {
                    $lt = \trim((string)$lt);
                    if ($lt === '') {
                        continue;
                    }
                    $types[$lt] = true;
                }
            }
        }

        if (!$sawPlan) {
            return null;
        }

        return \array_keys($types);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeInjectionList(mixed $value): array
    {
        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            $value = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($value) || $value === []) {
            return [];
        }
        $isList = \array_keys($value) === \range(0, \count($value) - 1);
        $items = $isList ? $value : [$value];
        $out = [];
        foreach ($items as $item) {
            if (\is_array($item) && $item !== []) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || \trim($value) === '') {
            return [];
        }
        $decoded = \json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array{
     *   theme_id:int,
     *   area:string,
     *   store_mode:string,
     *   scope_key:string,
     *   scope_kind:string,
     *   website_id:int|null,
     *   version_owner_scope:string
     * }> $targets
     * @return list<array{scope:string,area:string,store_mode:string,version_id:int}>
     */
    private function ensureBoundScopes(int $themeId, array $targets): array
    {
        $ensured = [];
        $seen = [];
        foreach ($targets as $target) {
            $scope = \trim((string)($target['version_owner_scope'] ?? ''));
            $area = (string)($target['area'] ?? 'frontend');
            $storeMode = (string)($target['store_mode'] ?? 'normal');
            $key = $scope . '|' . $area . '|' . $storeMode;
            if ($scope === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $version = $this->scopeVersions->ensureCurrent(
                $themeId,
                $scope,
                (string)($target['scope_kind'] ?? 'website'),
                $target['website_id'] ?? null,
                $storeMode,
                $area,
            );
            $ensured[] = [
                'scope' => $scope,
                'area' => $area,
                'store_mode' => $storeMode,
                'version_id' => (int)$version->getVersionId(),
            ];
        }

        return $ensured;
    }

    /**
     * @param list<array{scope:string,area:string,store_mode:string,version_id:int}> $ensured
     */
    private function healChrome(int $themeId, array $ensured): int
    {
        $healed = 0;
        foreach ($ensured as $row) {
            if ($this->bakeCoordinator->rematerializeChromeIfStale(
                $themeId,
                $row['scope'],
                $row['version_id'],
                $row['area'],
                $row['store_mode'],
            )) {
                ++$healed;
            }
        }

        return $healed;
    }

    /**
     * Source manifest unchanged, but sealed chrome input_digest may still drift.
     * Kept below solidifyTheme call sites so contract string-order (solidify ≺ clear) holds.
     *
     * @param array{
     *   changed:bool,
     *   bootstrapped:bool,
     *   solidified:int,
     *   chrome_healed:int,
     *   cache_cleared:int,
     *   static_published:int,
     *   skipped_files:int,
     *   messages:list<string>
     * } $out
     * @return array{
     *   changed:bool,
     *   bootstrapped:bool,
     *   solidified:int,
     *   chrome_healed:int,
     *   cache_cleared:int,
     *   static_published:int,
     *   skipped_files:int,
     *   messages:list<string>
     * }
     */
    private function finalizeUnchangedOriginWatchWithChromeDigestDriftHeal(
        WelineTheme $theme,
        int $themeId,
        array $out,
    ): array {
        $targets = $this->themeApplicationUsage->ownSolidifyTargetsForTheme($themeId);
        $ensured = $this->ensureBoundScopes($themeId, $targets);
        if ($ensured === []) {
            return $out;
        }
        $healed = $this->healChrome($themeId, $ensured);
        if ($healed < 1) {
            return $out;
        }
        $out['chrome_healed'] = $healed;
        $out['messages'][] = 'chrome_digest_drift_heal:' . $healed;
        $layoutsForPurge = $this->allFrontendLayoutsForTheme($theme);
        $out['cache_cleared'] = $this->clearStorefrontCachesAfterBake(
            $themeId,
            $targets,
            $layoutsForPurge,
            $out['messages'],
            self::CACHE_CLEAR_REASON,
        );

        return $out;
    }

    /**
     * @param list<array{type:string,option:string}> $layouts
     */
    private function solidifyLayoutChanges(int $themeId, array $layouts, bool $forcePages = false): int
    {
        $byType = [];
        foreach ($layouts as $layout) {
            $type = \trim($layout['type']);
            $option = \trim($layout['option']);
            if ($type === '') {
                continue;
            }
            // option "*" / empty → all options for this layout_type
            if ($option === '' || $option === '*') {
                $byType[$type]['*'] = true;
            } else {
                $byType[$type][$option] = true;
            }
        }
        if ($byType === []) {
            return 0;
        }

        $migrated = 0;
        // Per layout_type: never unfiltered full-theme bake from this helper.
        foreach ($byType as $type => $optionMap) {
            $filter = ['layout_type' => (string)$type];
            $options = \array_keys($optionMap);
            if (\count($options) === 1 && $options[0] !== '*') {
                $filter['layout_option'] = $options[0];
            }
            if ($forcePages) {
                $filter['force_pages'] = true;
            }
            $migrated += $this->solidify->solidifyTheme($themeId, null, $filter);
        }

        return $migrated;
    }

    /**
     * @param list<string> $changedPaths
     * @return array{
     *   layouts:list<array{type:string,option:string}>,
     *   chrome:bool,
     *   assets:bool,
     *   widgets:list<array{module:string,type:string,code:string,path:string}>,
     *   templates:list<string>,
     *   skipped:list<string>
     * }
     */
    private function classifyChangedPaths(array $changedPaths): array
    {
        $layouts = [];
        $layoutKeys = [];
        $chrome = false;
        $assets = false;
        $widgets = [];
        $widgetKeys = [];
        $templates = [];
        $skipped = [];
        foreach ($changedPaths as $path) {
            $class = $this->classifyRelativePath($path);
            if ($class === null) {
                continue;
            }
            if ($class['kind'] === 'layout') {
                $key = $class['type'] . '|' . $class['option'];
                if (!isset($layoutKeys[$key])) {
                    $layoutKeys[$key] = true;
                    $layouts[] = ['type' => $class['type'], 'option' => $class['option']];
                }
                continue;
            }
            if ($class['kind'] === 'chrome') {
                $chrome = true;
                continue;
            }
            if ($class['kind'] === 'assets') {
                $assets = true;
                continue;
            }
            if ($class['kind'] === 'module_static') {
                // Handled only by processModulePrefixStatics (shared remirror); never theme bake.
                continue;
            }
            if ($class['kind'] === 'widget') {
                $module = \trim((string)($class['module'] ?? 'Weline_Theme'));
                if ($module === '') {
                    $module = 'Weline_Theme';
                }
                // External widget CSS/JS: also publish statics (fingerprints need rebake via widget cascade).
                if (!empty($class['asset'])) {
                    $assets = true;
                }
                $key = $module . '|' . $class['type'] . '|' . $class['code'];
                if (!isset($widgetKeys[$key])) {
                    $widgetKeys[$key] = true;
                    $widgets[] = [
                        'module' => $module,
                        'type' => $class['type'],
                        'code' => $class['code'],
                        'path' => $path,
                    ];
                }
                continue;
            }
            if ($class['kind'] === 'template') {
                $templates[] = $path;
                continue;
            }
            if ($class['kind'] === 'skip') {
                $skipped[] = $path;
            }
        }

        return [
            'layouts' => $layouts,
            'chrome' => $chrome,
            'assets' => $assets,
            'widgets' => $widgets,
            'templates' => $templates,
            'skipped' => $skipped,
        ];
    }

    /**
     * Classifier: theme .phtml / .css / .js, `__modstatic__/…`, or `__ext__/{Module}/{type}/{code}(.phtml|/asset/*.{css,js})`.
     *
     * @return array{kind:string,type?:string,option?:string,code?:string,module?:string,asset?:bool}|null
     */
    private function classifyRelativePath(string $relative): ?array
    {
        $relative = \str_replace('\\', '/', \ltrim($relative, '/'));
        if ($relative === '') {
            return null;
        }
        if (\str_starts_with($relative, self::MODULE_STATIC_PREFIX)) {
            $lower = \strtolower($relative);
            if (\str_ends_with($lower, '.css') || \str_ends_with($lower, '.js')) {
                return ['kind' => 'module_static'];
            }

            return ['kind' => 'skip'];
        }
        if (!\str_starts_with($relative, '__ext__/')) {
            $lower = \strtolower($relative);
            if (\str_ends_with($lower, '.css') || \str_ends_with($lower, '.js')) {
                return ['kind' => 'assets'];
            }
        }
        // External widget CSS/JS (module @widget.source / layout_source) → widget cascade + assets publish.
        if (\preg_match('#^__ext__/([A-Za-z0-9_]+)/([^/]+)/([^/]+)/asset/([^/]+\.(?:css|js))$#Di', $relative, $match) === 1) {
            $code = \trim((string)$match[3]);
            if ($code === '') {
                return ['kind' => 'skip'];
            }

            return [
                'kind' => 'widget',
                'module' => (string)$match[1],
                'type' => (string)$match[2],
                'code' => $code,
                'asset' => true,
            ];
        }
        if (!\str_ends_with($relative, '.phtml')) {
            return null;
        }
        // External module (or parent Theme module) widget sources watched for this theme.
        if (\preg_match('#^__ext__/([A-Za-z0-9_]+)/([^/]+)/(.+)\.phtml$#D', $relative, $match) === 1) {
            $code = \trim((string)$match[3]);
            if ($code === '' || \str_contains($code, '/')) {
                return ['kind' => 'skip'];
            }

            return [
                'kind' => 'widget',
                'module' => (string)$match[1],
                'type' => (string)$match[2],
                'code' => $code,
            ];
        }
        if (\preg_match('#(?:^|/)layouts/(.+)\.phtml$#D', $relative, $match) === 1) {
            $rest = (string)$match[1];
            $parts = \array_values(\array_filter(\explode('/', $rest), static fn(string $p): bool => $p !== ''));
            if ($parts === []) {
                return ['kind' => 'skip'];
            }
            if (\count($parts) === 1) {
                return ['kind' => 'layout', 'type' => 'default', 'option' => $parts[0]];
            }
            $option = (string)\array_pop($parts);

            return ['kind' => 'layout', 'type' => \implode('/', $parts), 'option' => $option];
        }
        if (\preg_match('#(?:^|/)partials/(header|footer|sidebar)(?:/|$)#D', $relative) === 1) {
            return ['kind' => 'chrome'];
        }
        if (\preg_match('#(?:^|/)widgets/([^/]+)/(.+)\.phtml$#D', $relative, $match) === 1) {
            $type = (string)$match[1];
            $rest = \str_replace('\\', '/', (string)$match[2]);
            $parts = \array_values(\array_filter(\explode('/', $rest), static fn(string $p): bool => $p !== ''));
            $code = $parts === [] ? '' : (string)\end($parts);
            if (\count($parts) >= 2 && $code === 'default') {
                $code = (string)$parts[\count($parts) - 2];
            }

            // Theme-tree widgets are Theme-owned (design override of Weline_Theme).
            return ['kind' => 'widget', 'module' => 'Weline_Theme', 'type' => $type, 'code' => $code];
        }

        return ['kind' => 'template'];
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}>|null $previous
     * @return array<string, array{sha256:string,mtime:int,size:int}>
     */
    private function buildSnapshot(WelineTheme $theme, ?array $previous = null): array
    {
        $themePath = \rtrim(\str_replace('\\', '/', (string)$theme->getPath()), '/');
        if ($themePath === '' || !\is_dir($themePath)) {
            return [];
        }
        $files = [];
        foreach ($this->watchRoots($themePath) as $root) {
            $base = $root['absolute'];
            if (!\is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }
                $absolute = \str_replace('\\', '/', $item->getPathname());
                $relative = \substr($absolute, \strlen($themePath) + 1);
                if ($relative === false || $relative === '') {
                    continue;
                }
                // Theme-internal .phtml + .css/.js (default + custom theme roots).
                $class = $this->classifyRelativePath($relative);
                if ($class === null) {
                    continue;
                }
                $mtime = (int)@$item->getMTime();
                $size = (int)@$item->getSize();
                $prevMeta = $previous[$relative] ?? null;
                if (
                    \is_array($prevMeta)
                    && (int)($prevMeta['mtime'] ?? -1) === $mtime
                    && (int)($prevMeta['size'] ?? -1) === $size
                    && (string)($prevMeta['sha256'] ?? '') !== ''
                ) {
                    $files[$relative] = [
                        'sha256' => (string)$prevMeta['sha256'],
                        'mtime' => $mtime,
                        'size' => $size,
                    ];
                    continue;
                }
                $files[$relative] = [
                    'sha256' => \hash_file('sha256', $absolute) ?: '',
                    'mtime' => $mtime,
                    'size' => $size,
                ];
            }
        }
        $this->appendExternalWidgetSources($theme, $files, $previous);
        \ksort($files);

        return $files;
    }

    /**
     * Snapshot enabled modules' view/statics .css/.js as `__modstatic__/{Vendor}/{Module}/view/statics/…`.
     *
     * @param array<string, array{sha256:string,mtime:int,size:int}>|null $previous
     * @return array<string, array{sha256:string,mtime:int,size:int}>
     */
    private function buildModulePrefixStaticsSnapshot(?array $previous = null): array
    {
        $files = [];
        $base = \defined('BP') ? \rtrim(\str_replace('\\', '/', (string)\constant('BP')), '/') : '';
        if ($base === '') {
            return [];
        }
        $prefix = $base . '/app/code/';
        try {
            $modules = Env::getInstance()->getModuleList();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($modules)) {
            return [];
        }
        foreach ($modules as $meta) {
            if (!\is_array($meta) || empty($meta['status'])) {
                continue;
            }
            $moduleBase = \rtrim(\str_replace('\\', '/', (string)($meta['base_path'] ?? '')), '/');
            if ($moduleBase === '' || !\str_starts_with($moduleBase, $prefix)) {
                continue;
            }
            $statics = $moduleBase . '/view/statics';
            if (!\is_dir($statics)) {
                continue;
            }
            $this->appendCssJsUnderDir($files, $previous, $statics, $prefix, self::MODULE_STATIC_MAX_FILES_PER_MODULE);
        }
        \ksort($files);

        return $files;
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}> $files
     * @param array<string, array{sha256:string,mtime:int,size:int}>|null $previous
     */
    private function appendCssJsUnderDir(
        array &$files,
        ?array $previous,
        string $dir,
        string $appCodePrefix,
        int $maxFiles,
    ): void {
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (\Throwable) {
            return;
        }
        foreach ($iterator as $item) {
            if ($count >= $maxFiles) {
                break;
            }
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $absolute = \str_replace('\\', '/', $item->getPathname());
            $lower = \strtolower($absolute);
            if (!\str_ends_with($lower, '.css') && !\str_ends_with($lower, '.js')) {
                continue;
            }
            if (!\str_starts_with($absolute, $appCodePrefix)) {
                continue;
            }
            $rel = \substr($absolute, \strlen($appCodePrefix));
            if ($rel === false || $rel === '' || \str_contains($rel, '..')) {
                continue;
            }
            $key = self::MODULE_STATIC_PREFIX . $rel;
            $this->putWatchedFile($files, $previous, $key, $absolute);
            ++$count;
        }
    }

    /**
     * Watch module / parent-Theme widget files used by this theme but not overridden in-tree.
     * Includes PHTML plus @widget.source / layout_source CSS/JS under `__ext__/…/asset/`.
     *
     * @param array<string, array{sha256:string,mtime:int,size:int}> $files
     * @param array<string, array{sha256:string,mtime:int,size:int}>|null $previous
     */
    private function appendExternalWidgetSources(WelineTheme $theme, array &$files, ?array $previous): void
    {
        $refs = $this->collectExternalWidgetRefs($theme);
        foreach ($refs as $ref) {
            $module = $ref['module'];
            $type = $ref['type'];
            $code = $ref['code'];
            if ($this->themeHasLocalWidgetOverride($theme, $type, $code)) {
                continue;
            }
            $absolute = $this->resolveWidgetSourceAbsolute($module, $type, $code);
            if ($absolute !== '' && \is_file($absolute)) {
                $this->putWatchedFile(
                    $files,
                    $previous,
                    '__ext__/' . $module . '/' . $type . '/' . $code . '.phtml',
                    $absolute
                );
            }
            foreach ($this->resolveWidgetStaticAbsolutes($module, $type, $code) as $staticAbs) {
                $basename = \basename($staticAbs);
                if ($basename === '' || !\preg_match('/\.(css|js)$/i', $basename)) {
                    continue;
                }
                $this->putWatchedFile(
                    $files,
                    $previous,
                    '__ext__/' . $module . '/' . $type . '/' . $code . '/asset/' . $basename,
                    $staticAbs
                );
            }
        }
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}> $files
     * @param array<string, array{sha256:string,mtime:int,size:int}>|null $previous
     */
    private function putWatchedFile(array &$files, ?array $previous, string $key, string $absolute): void
    {
        $mtime = (int)@\filemtime($absolute);
        $size = (int)@\filesize($absolute);
        $prevMeta = $previous[$key] ?? null;
        if (
            \is_array($prevMeta)
            && (int)($prevMeta['mtime'] ?? -1) === $mtime
            && (int)($prevMeta['size'] ?? -1) === $size
            && (string)($prevMeta['sha256'] ?? '') !== ''
        ) {
            $files[$key] = [
                'sha256' => (string)$prevMeta['sha256'],
                'mtime' => $mtime,
                'size' => $size,
            ];

            return;
        }
        $files[$key] = [
            'sha256' => \hash_file('sha256', $absolute) ?: '',
            'mtime' => $mtime,
            'size' => $size,
        ];
    }

    /**
     * Resolve @widget.source / layout_source Module::rel paths to absolute static files.
     *
     * @return list<string>
     */
    private function resolveWidgetStaticAbsolutes(string $module, string $type, string $code): array
    {
        $module = \trim($module) !== '' ? \trim($module) : 'Weline_Theme';
        $type = \trim($type);
        $code = \trim($code);
        if ($type === '' || $code === '') {
            return [];
        }
        $refs = [];
        try {
            /** @var \Weline\Widget\Service\WidgetData $data */
            $data = ObjectManager::getInstance(\Weline\Widget\Service\WidgetData::class);
            $widget = $data->getWidget($type, $code);
            if (\is_array($widget)) {
                $wModule = \trim((string)($widget['module'] ?? ''));
                if ($wModule === '' || $wModule === $module || $module === 'Weline_Theme') {
                    foreach (['source', 'layout_source'] as $field) {
                        $raw = \trim((string)($widget[$field] ?? ''));
                        if ($raw === '') {
                            $raw = \trim((string)(($widget['config'][$field] ?? '') ?: ''));
                        }
                        if ($raw === '') {
                            continue;
                        }
                        foreach (\preg_split('/\s*,\s*/', $raw) ?: [] as $piece) {
                            $piece = \trim((string)$piece);
                            if ($piece !== '') {
                                $refs[] = $piece;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        $out = [];
        $seen = [];
        foreach ($refs as $ref) {
            $abs = $this->resolveModuleStaticAbsolute($ref);
            if ($abs === '' || !\is_file($abs) || isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $out[] = $abs;
        }

        return $out;
    }

    /**
     * Resolve `Vendor_Module::css/…` / `js/…` to module view/statics absolute path.
     */
    private function resolveModuleStaticAbsolute(string $ref): string
    {
        $ref = \str_replace('\\', '/', \trim($ref));
        if ($ref === '' || !\str_contains($ref, '::')) {
            return '';
        }
        [$mod, $rel] = \explode('::', $ref, 2);
        $mod = \trim($mod);
        $rel = \ltrim(\trim($rel), '/');
        if ($mod === '' || $rel === '' || \str_contains($rel, '..')) {
            return '';
        }
        $lower = \strtolower($rel);
        if (!\str_ends_with($lower, '.css') && !\str_ends_with($lower, '.js')) {
            return '';
        }
        try {
            $modules = Env::getInstance()->getModuleList();
            $base = \rtrim(\str_replace('\\', '/', (string)($modules[$mod]['base_path'] ?? '')), '/');
            if ($base === '') {
                return '';
            }
            foreach ([
                $base . '/view/statics/' . $rel,
                $base . '/view/static/' . $rel,
            ] as $candidate) {
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @return list<array{module:string,type:string,code:string}>
     */
    private function collectExternalWidgetRefs(WelineTheme $theme): array
    {
        $refs = [];
        $seen = [];
        $push = static function (string $module, string $type, string $code) use (&$refs, &$seen): void {
            $module = \trim($module) !== '' ? \trim($module) : 'Weline_Theme';
            $type = \trim($type);
            $code = \trim($code);
            if ($type === '' || $code === '') {
                return;
            }
            $key = $module . '|' . $type . '|' . $code;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $refs[] = ['module' => $module, 'type' => $type, 'code' => $code];
        };

        foreach ($this->iterateThemeLayoutFiles($theme) as $absolute => $_relative) {
            $html = @\file_get_contents($absolute);
            if (!\is_string($html) || $html === '' || !\str_contains($html, '<w:widget')) {
                continue;
            }
            if (\preg_match_all('#<w:widget\b([^>]*)/?>#i', $html, $matches, \PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($matches as $match) {
                $attrs = (string)($match[1] ?? '');
                $type = $this->widgetTagAttr($attrs, 'type');
                $code = $this->widgetTagAttr($attrs, 'name');
                if ($code === '') {
                    $code = $this->widgetTagAttr($attrs, 'code');
                }
                $module = $this->widgetTagAttr($attrs, 'module');
                $push($module, $type, $code);
            }
        }

        // Ledger-injected widgets (other modules): watch their sources when they target this theme's layouts.
        try {
            $catalog = LayoutScanner::scanLayouts($theme, 'frontend');
            $themeLayouts = [];
            if (\is_array($catalog)) {
                foreach (\array_keys($catalog) as $lt) {
                    $lt = \trim((string)$lt);
                    if ($lt !== '') {
                        $themeLayouts[$lt] = true;
                    }
                }
            }
            /** @var WidgetRegistryEntry $model */
            $model = ObjectManager::getInstance(WidgetRegistryEntry::class);
            $rows = (clone $model)->clearQuery()->clearData()
                ->where(WidgetRegistryEntry::schema_fields_IS_ACTIVE, 1)
                ->where(WidgetRegistryEntry::schema_fields_HAS_DEFAULT_INJECTIONS, 1)
                ->select()
                ->fetchArray();
            if (!\is_array($rows)) {
                $rows = [];
            }
            if (!\array_is_list($rows) && $rows !== []) {
                $rows = [$rows];
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $injections = $this->decodeInjectionList(
                    $row[WidgetRegistryEntry::schema_fields_DEFAULT_INJECTIONS_JSON] ?? null
                );
                if ($injections === []) {
                    continue;
                }
                $targetsTheme = false;
                foreach ($injections as $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $layoutTypes = $item['layout_type'] ?? $item['page_type'] ?? $item['page_layouts'] ?? null;
                    if ($layoutTypes === null || $layoutTypes === '' || $layoutTypes === '*') {
                        $targetsTheme = $themeLayouts !== [];
                        break;
                    }
                    if (!\is_array($layoutTypes)) {
                        $layoutTypes = [$layoutTypes];
                    }
                    foreach ($layoutTypes as $lt) {
                        $lt = \trim((string)$lt);
                        if ($lt !== '' && isset($themeLayouts[$lt])) {
                            $targetsTheme = true;
                            break 2;
                        }
                    }
                }
                if (!$targetsTheme) {
                    continue;
                }
                $push(
                    (string)($row[WidgetRegistryEntry::schema_fields_WIDGET_MODULE] ?? 'Weline_Theme'),
                    (string)($row[WidgetRegistryEntry::schema_fields_WIDGET_TYPE] ?? ''),
                    (string)($row[WidgetRegistryEntry::schema_fields_WIDGET_CODE] ?? ''),
                );
            }
        } catch (\Throwable) {
            // Soft: embed refs still watched.
        }

        return $refs;
    }

    private function themeHasLocalWidgetOverride(WelineTheme $theme, string $type, string $code): bool
    {
        $type = \trim($type);
        $code = \trim($code);
        if ($type === '' || $code === '') {
            return false;
        }
        $themePath = \rtrim(\str_replace('\\', '/', (string)$theme->getPath()), '/');
        if ($themePath === '') {
            return false;
        }
        foreach ($this->watchRoots($themePath) as $root) {
            $base = \rtrim((string)$root['absolute'], '/') . '/widgets/' . $type;
            foreach ([
                $base . '/' . $code . '/default.phtml',
                $base . '/' . $code . '.phtml',
            ] as $candidate) {
                if (\is_file($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveWidgetSourceAbsolute(string $module, string $type, string $code): string
    {
        $module = \trim($module) !== '' ? \trim($module) : 'Weline_Theme';
        $type = \trim($type);
        $code = \trim($code);
        if ($type === '' || $code === '') {
            return '';
        }
        try {
            /** @var \Weline\Widget\Service\WidgetData $data */
            $data = ObjectManager::getInstance(\Weline\Widget\Service\WidgetData::class);
            $widget = $data->getWidget($type, $code);
            if (\is_array($widget)) {
                $wModule = \trim((string)($widget['module'] ?? ''));
                if ($wModule !== '' && $wModule !== $module) {
                    // Registry identity differs — still try template if code matches requested module.
                }
                $template = \trim((string)($widget['template'] ?? ''));
                if ($template !== '' && ($wModule === '' || $wModule === $module || $module === 'Weline_Theme')) {
                    $tpl = ObjectManager::getInstance(\Weline\Framework\View\Template::class);
                    if (\method_exists($tpl, 'getTemplateRealPath')) {
                        $real = \str_replace('\\', '/', (string)$tpl->getTemplateRealPath($template));
                        if ($real !== '' && \is_file($real)) {
                            return $real;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        // Fallback: conventional module view paths.
        try {
            $modules = Env::getInstance()->getModuleList();
            $base = \rtrim(\str_replace('\\', '/', (string)($modules[$module]['base_path'] ?? '')), '/');
            if ($base === '') {
                return '';
            }
            foreach ([
                $base . '/view/theme/frontend/widgets/' . $type . '/' . $code . '/default.phtml',
                $base . '/view/theme/frontend/widgets/' . $type . '/' . $code . '.phtml',
                $base . '/view/templates/frontend/widgets/' . $code . '.phtml',
            ] as $candidate) {
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @return list<array{absolute:string}>
     */
    private function watchRoots(string $themePath): array
    {
        $roots = [];
        foreach (['frontend', 'backend'] as $area) {
            foreach ([
                $themePath . '/theme/' . $area,
                $themePath . '/view/theme/' . $area,
                $themePath . '/' . $area,
            ] as $base) {
                if (\is_dir($base)) {
                    $roots[] = ['absolute' => \str_replace('\\', '/', $base)];
                }
            }
        }

        return $roots;
    }

    /**
     * Keep watched leaves only: .phtml + .css + .js (drop images/fonts/etc.).
     *
     * @param array<string, array{sha256:string,mtime:int,size:int}>|null $files
     * @return array<string, array{sha256:string,mtime:int,size:int}>|null
     */
    private function watchedManifestFiles(?array $files): ?array
    {
        if ($files === null) {
            return null;
        }
        $out = [];
        foreach ($files as $path => $meta) {
            if (!\is_string($path) || !\is_array($meta)) {
                continue;
            }
            $lower = \strtolower($path);
            if (\str_ends_with($lower, '.phtml')
                || \str_ends_with($lower, '.css')
                || \str_ends_with($lower, '.js')
            ) {
                $out[$path] = $meta;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}> $previous
     * @param array<string, array{sha256:string,mtime:int,size:int}> $current
     * @return array{changed_paths:list<string>,manifest_dirty:bool}
     */
    private function diffSnapshot(array $previous, array $current): array
    {
        $changed = [];
        $allKeys = \array_unique(\array_merge(\array_keys($previous), \array_keys($current)));
        $manifestDirty = false;
        foreach ($allKeys as $path) {
            $prev = $previous[$path] ?? null;
            $curr = $current[$path] ?? null;
            if ($prev === null || $curr === null) {
                $changed[] = $path;
                continue;
            }
            if ((string)($prev['sha256'] ?? '') !== (string)($curr['sha256'] ?? '')) {
                $changed[] = $path;
                continue;
            }
            if ((int)($prev['mtime'] ?? 0) !== (int)($curr['mtime'] ?? 0)
                || (int)($prev['size'] ?? 0) !== (int)($curr['size'] ?? 0)
            ) {
                $manifestDirty = true;
            }
        }
        \sort($changed);

        return ['changed_paths' => $changed, 'manifest_dirty' => $manifestDirty];
    }

    /**
     * @return array<string, array{sha256:string,mtime:int,size:int}>|null
     */
    private function readManifest(int $themeId): ?array
    {
        $path = $this->manifestPath($themeId);
        if (!\is_file($path)) {
            return null;
        }
        try {
            $raw = \file_get_contents($path);
            if ($raw === false || $raw === '') {
                return null;
            }
            $data = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($data) || !\is_array($data['files'] ?? null)) {
            return null;
        }
        $files = [];
        foreach ($data['files'] as $rel => $meta) {
            if (!\is_string($rel) || !\is_array($meta)) {
                continue;
            }
            $files[$rel] = [
                'sha256' => (string)($meta['sha256'] ?? ''),
                'mtime' => (int)($meta['mtime'] ?? 0),
                'size' => (int)($meta['size'] ?? 0),
            ];
        }

        return $files;
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}> $files
     */
    private function writeManifest(int $themeId, array $files): void
    {
        $path = $this->manifestPath($themeId);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('theme_origin_watch_manifest_mkdir_failed:' . $dir);
        }
        $payload = [
            'version' => self::MANIFEST_VERSION,
            'updated_at' => \gmdate('c'),
            'deploy' => (string)(Env::system('deploy') ?? ''),
            'files' => $files,
        ];
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
        $tmp = $path . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('theme_origin_watch_manifest_write_failed:' . $tmp);
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('theme_origin_watch_manifest_rename_failed:' . $path);
        }
    }

    private function manifestPath(int $themeId): string
    {
        return \rtrim($this->paths->root(), '/\\')
            . \DIRECTORY_SEPARATOR
            . $themeId
            . \DIRECTORY_SEPARATOR
            . self::MANIFEST_NAME;
    }

    private function moduleStaticsManifestPath(): string
    {
        return \rtrim($this->paths->root(), '/\\')
            . \DIRECTORY_SEPARATOR
            . self::MODULE_STATIC_MANIFEST_DIR
            . \DIRECTORY_SEPARATOR
            . self::MANIFEST_NAME;
    }

    /**
     * @return array<string, array{sha256:string,mtime:int,size:int}>|null
     */
    private function readModuleStaticsManifest(): ?array
    {
        $path = $this->moduleStaticsManifestPath();
        if (!\is_file($path)) {
            return null;
        }
        try {
            $raw = \file_get_contents($path);
            if ($raw === false || $raw === '') {
                return null;
            }
            $data = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($data) || !\is_array($data['files'] ?? null)) {
            return null;
        }
        $files = [];
        foreach ($data['files'] as $rel => $meta) {
            if (!\is_string($rel) || !\is_array($meta)) {
                continue;
            }
            $files[$rel] = [
                'sha256' => (string)($meta['sha256'] ?? ''),
                'mtime' => (int)($meta['mtime'] ?? 0),
                'size' => (int)($meta['size'] ?? 0),
            ];
        }

        return $files;
    }

    /**
     * @param array<string, array{sha256:string,mtime:int,size:int}> $files
     */
    private function writeModuleStaticsManifest(array $files): void
    {
        $path = $this->moduleStaticsManifestPath();
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('theme_origin_watch_module_statics_manifest_mkdir_failed:' . $dir);
        }
        $payload = [
            'version' => self::MANIFEST_VERSION,
            'scope' => 'module_prefix_statics',
            'updated_at' => \gmdate('c'),
            'deploy' => (string)(Env::system('deploy') ?? ''),
            'files' => $files,
        ];
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
        $tmp = $path . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('theme_origin_watch_module_statics_manifest_write_failed:' . $tmp);
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('theme_origin_watch_module_statics_manifest_rename_failed:' . $path);
        }
    }
}
