<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\State;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\View\Template;
use Weline\Framework\View\TemplateCompileService;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Theme\LayoutCriticalCssMaterializer;
use Weline\Theme\Service\ThemeHeadChromeCssPack;
use Weline\Theme\Service\ThemeHeadChromeJsPack;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Theme\Service\ThemeApplicationUsageService;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Widget\Api\WidgetRegistryInterface;

/**
 * Formal Theme layout publish gate: Taglib-compile entity sources → com_*.phtml.
 * This is Template/Taglib compilation (per locale cache files), not layout/widget
 * relationship solidify and not i18n dictionary compile. Failures throw (caller
 * rolls back entity bytes). Draft identities are skipped.
 *
 * Before compile, pins Website/RequestContext from the identity storage scope so
 * literal @url / <url> bake carries the publish-site mount (not default-site).
 *
 * After Taglib + theme-head warm, invalidates storefront FPC via scoped
 * {@see ThemeStorefrontSlugFpcPublisher::purgeLayoutsForThemeTargets} by default
 * (`cache_invalidation=scoped`). Full {@see ThemeRuntimeCacheCleaner::clearAllThemeRelatedCaches}
 * only when options explicitly set `cache_invalidation=all` (cutover). Drain / single-page
 * rematerialize must never pass `all`.
 */
final class ThemeLayoutEntityFormalLocaleCompileService
{
    /** @var array<string, true> Process-local debounce keys: "all:{themeId}" or "scoped:{themeId}:{fp}". */
    private static array $postWarmClearedKeys = [];

    /** Cross-process coalesce window so pipeline workers do not N× FPC promote. */
    private const POST_WARM_CLEAR_COALESCE_SECONDS = 20;

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly TemplateCompileService $compiler,
        private readonly Printing $printing,
        private readonly ThemeHeadChromeCssPack $headCssPack,
        private readonly ThemeHeadChromeJsPack $headJsPack,
        private readonly LayoutCriticalCssMaterializer $layoutCriticalMaterializer,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly ThemeStorefrontSlugFpcPublisher $slugFpcPublisher,
        private readonly ThemeApplicationUsageService $themeApplicationUsage,
    ) {
    }

    /**
     * @param array<string,?string> $candidates completed publish candidates
     * @param array{
     *   nested?:bool,
     *   locale_concurrency?:int,
     *   on_progress?:callable,
     *   cache_invalidation?:'scoped'|'all'
     * } $options
     *        nested=true → force locale concurrency 1 (pipeline compile worker; no nested process pool).
     *        cache_invalidation default scoped → purgeLayoutsForThemeTargets; all → clearAllThemeRelatedCaches.
     */
    public function compileAfterPromote(ThemeVersionIdentity $identity, array $candidates, array $options = []): void
    {
        if ($identity->area !== 'frontend' || $identity->mode !== ThemeVersionIdentity::MODE_FORMAL) {
            return;
        }
        $streamPaths = is_array($options['stream_candidate_paths'] ?? null)
            ? array_values(array_filter($options['stream_candidate_paths'], 'is_string'))
            : null;
        $streamPages = is_array($options['stream_pages'] ?? null) ? $options['stream_pages'] : null;
        if (is_array($streamPages) && $streamPages !== []) {
            $pages = $streamPages;
        } else {
            $pages = $this->extractPublishedPageLayouts($candidates);
        }
        if ($pages === []) {
            return;
        }
        $locales = $this->resolveDefaultWebsiteLocales();
        $currency = State::resolveWebsiteDefaultCurrency();
        // Progress + diagnostics must follow the publish identity theme — never the
        // process-global frontend Default (multi-bound-theme upgrade would all look like Default).
        $theme = $this->loadThemeForIdentity($identity);
        if (!$theme instanceof WelineTheme) {
            throw new \RuntimeException(
                'theme_layout_formal_compile_theme_missing:theme_id=' . $identity->themeId
            );
        }

        $nested = !empty($options['nested']) || $this->isNestedCompileEnv();
        $localeConcurrency = $nested
            ? 1
            : (isset($options['locale_concurrency'])
                ? $this->compiler->resolveConcurrency($options['locale_concurrency'])
                : $this->compiler->resolveConcurrency());
        $onProgress = is_callable($options['on_progress'] ?? null) ? $options['on_progress'] : null;
        // Bind a local map for the pinned closure (must be in use()-list; never pass null).
        $candidateMap = $candidates;
        $themeLabel = $this->formatThemeProgressLabel($theme, $identity);
        $pageTotal = count($pages);
        $localeTotal = count($locales);
        $localeOrdinal = [];
        foreach (array_values($locales) as $idx => $code) {
            $localeOrdinal[(string)$code] = $idx + 1;
        }

        // Pin ThemeData for the whole Formal compile+warm so theme-head / widget-assets
        // never land under process-global Default (or a sibling theme) during multi-theme upgrade.
        $invalidation = strtolower(trim((string)($options['cache_invalidation'] ?? 'scoped')));
        if ($invalidation !== 'all') {
            $invalidation = 'scoped';
        }
        $previousTheme = ThemeData::getCurrentTheme();
        $previousArea = ThemeData::getCurrentArea();
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($identity->area);
        try {
        $this->runPinnedToIdentityWebsite($identity, function () use (
            $identity,
            $candidateMap,
            $pages,
            $locales,
            $currency,
            $localeConcurrency,
            $nested,
            $onProgress,
            $themeLabel,
            $theme,
            $pageTotal,
            $localeTotal,
            $localeOrdinal,
            $invalidation,
            $streamPaths,
        ): void {
            $template = Template::getInstance();
            if (!$nested) {
                // Put theme + stage first so CLI truncation keeps the readable prefix.
                $this->printing->note(sprintf(
                    '%s · 主题=%s · 范围=%s · 区域=%s · 阶段1/2 Taglib编译 · 布局%d页 · 语种%d%s',
                    (string)__('主题布局发布'),
                    $themeLabel,
                    $identity->canonicalScope,
                    $identity->area,
                    $pageTotal,
                    $localeTotal,
                    (!$nested && $localeConcurrency > 1 && $localeTotal > 1)
                        ? sprintf(' · %s=%d', (string)__('进程池'), $localeConcurrency)
                        : '',
                ));
            }
            $compiledSourcePaths = [];
            foreach ($pages as $pageIndex => $page) {
                $pageNo = $pageIndex + 1;
                $snapshot = null;
                $pinned = [];
                $widgets = [];
                $pageSource = null;
                // Nested pipeline: one page's bytes at a time. Sync publish keeps the full map.
                $pageCandidates = $streamPaths !== null
                    ? $this->loadPageCandidateSubset($streamPaths, $page)
                    : $candidateMap;
                try {
                // Prefer promote-candidate bytes first (no OwnerLock). Pipeline compile
                // otherwise contends with same-owner solidify WRITE via capture LOCK_SH.
                $snapshot = $this->snapshotFromPublishedCandidates(
                    $identity,
                    $pageCandidates,
                    $page['layout_type'],
                    $page['layout_option'],
                    $page['target_type'],
                    $page['target_id'],
                );
                $pagePath = $snapshot?->pagePath();
                if ($pagePath === null) {
                    $snapshot = ThemeLayoutSourceSnapshot::capture(
                        $this->paths,
                        $identity,
                        $page['layout_type'],
                        $page['layout_option'],
                        $page['target_type'],
                        $page['target_id'],
                    );
                    $pagePath = $snapshot->pagePath();
                }
                if ($pagePath === null || $snapshot === null) {
                    throw new \RuntimeException(
                        'theme_layout_formal_compile_page_missing:' . $page['layout_type'] . '/' . $page['layout_option']
                    );
                }
                $snapshot->install($template);
                $pinned = $this->buildPinnedCompileSet($snapshot, $pagePath, $identity, $compiledSourcePaths);
                $pageSource = $snapshot->source($pagePath);
                $widgets = $pageSource !== null
                    ? $this->resolveInlineWidgetTemplates((string)$pageSource['bytes'], $identity->area)
                    : [];
                    $this->compiler->compilePinnedSources(
                        $pinned,
                        $locales,
                        $currency,
                        $widgets,
                        function (int $done, int $total, string $locale, string $label) use (
                            $page,
                            $onProgress,
                            $nested,
                            $themeLabel,
                            $pageNo,
                            $pageTotal,
                            $localeTotal,
                            $localeOrdinal,
                        ): void {
                            if ($onProgress !== null) {
                                $onProgress($done, $total, $locale, $label, $page);
                                return;
                            }
                            // Nested pipeline workers: keep stderr free of progress bars (parent owns CLI).
                            if ($nested) {
                                return;
                            }
                            $layoutKey = $page['layout_type'] . '/' . $page['layout_option'];
                            if ($label === 'pool-done') {
                                $detail = (string)__('整页完成');
                            } elseif ($label === 'locale-done') {
                                $ord = (int)($localeOrdinal[$locale] ?? 0);
                                $detail = sprintf(
                                    '%s %s %d/%d',
                                    (string)__('语种完成'),
                                    $locale !== '' ? $locale : '?',
                                    max(1, $ord),
                                    max(1, $localeTotal),
                                );
                            } else {
                                $ord = (int)($localeOrdinal[$locale] ?? 0);
                                $detail = sprintf(
                                    '%s=%s %d/%d · %s',
                                    (string)__('语种'),
                                    $locale !== '' ? $locale : '?',
                                    max(1, $ord),
                                    max(1, $localeTotal),
                                    $label,
                                );
                            }
                            // Critical fields first: theme + page survive truncateCliText.
                            $this->printing->progressBar(
                                $done,
                                max(1, $total),
                                sprintf(
                                    'Taglib · 主题=%s · 页%d/%d %s · %s',
                                    $themeLabel,
                                    $pageNo,
                                    max(1, $pageTotal),
                                    $layoutKey,
                                    $detail,
                                ),
                                24,
                            );
                        },
                        ['concurrency' => $localeConcurrency],
                    );
                } catch (\Throwable $error) {
                    $this->printing->finishProgressLine();
                    $this->printing->error(sprintf(
                        'Taglib · 主题=%s · 页%d/%d %s/%s · %s: %s',
                        $themeLabel,
                        $pageNo,
                        max(1, $pageTotal),
                        $page['layout_type'],
                        $page['layout_option'],
                        (string)__('编译失败'),
                        $error->getMessage(),
                    ));
                    throw $error;
                } finally {
                    if ($nested) {
                        if ($snapshot instanceof ThemeLayoutSourceSnapshot) {
                            $snapshot->uninstall($template);
                        }
                        unset($snapshot, $pinned, $widgets, $pageSource, $pageCandidates);
                        Parser::clearWorkerCaches();
                        Parser::clearUsedWords();
                        gc_collect_cycles();
                    }
                }
            }
            // Post-com warm: bake theme-head packs so first request consumes artifacts (not hot-path merge).
            $this->warmPublishedResourcePacks($identity->area, $theme, $themeLabel, $identity->canonicalScope);
            // Default scoped invalidation (drain/single-page); cutover may pass cache_invalidation=all.
            $this->clearCachesAfterCompileWarm($theme, $identity, $pages, $invalidation);
        });
        } finally {
            ThemeData::setCurrentTheme($previousTheme);
            ThemeData::setCurrentArea($previousArea);
        }
    }

    /**
     * Rebuild candidate map from on-disk promoted paths (pipeline compile worker).
     *
     * @param list<string> $candidatePaths
     * @param array{nested?:bool} $options
     */
    public function compileAfterPromoteFromPaths(
        ThemeVersionIdentity $identity,
        array $candidatePaths,
        array $options = [],
    ): void {
        $paths = [];
        foreach ($candidatePaths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $paths[] = $path;
        }
        $pages = $this->indexPageLayoutsFromPaths($paths);
        // Nested worker: index metadata only, then load one page subset inside compileAfterPromote.
        $this->compileAfterPromote($identity, [], $options + [
            'nested' => true,
            'stream_candidate_paths' => $paths,
            'stream_pages' => $pages,
        ]);
    }

    /**
     * Page layouts from promote paths without holding every file's bytes.
     *
     * @param list<string> $paths
     * @return list<array{layout_type:string,layout_option:string,target_type:string,target_id:int}>
     */
    private function indexPageLayoutsFromPaths(array $paths): array
    {
        $pages = [];
        $seen = [];
        foreach ($paths as $path) {
            $normalized = str_replace('\\', '/', $path);
            if (!str_contains($normalized, '/pages/layouts/') || str_contains($normalized, '/sources/')) {
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            $head = $this->readSourceHead($path);
            if ($head === null) {
                throw new \RuntimeException('theme_layout_formal_compile_path_unreadable:' . $path);
            }
            $metadata = ThemeLayoutSourceSnapshot::metadata($head);
            if (!is_array($metadata)) {
                continue;
            }
            $layoutType = trim((string)($metadata['layout_type'] ?? ''));
            if ($layoutType === '') {
                continue;
            }
            $entry = [
                'layout_type' => $layoutType,
                'layout_option' => (string)($metadata['layout_option'] ?? 'default'),
                'target_type' => (string)($metadata['target_type'] ?? 'global'),
                'target_id' => max(0, (int)($metadata['target_id'] ?? 0)),
            ];
            $key = implode('|', $entry);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pages[] = $entry;
        }

        return $pages;
    }

    /**
     * Bytes for one page plus its sources and shared chrome partials.
     *
     * @param list<string> $paths
     * @param array{layout_type:string,layout_option:string,target_type:string,target_id:int} $page
     * @return array<string,?string>
     */
    private function loadPageCandidateSubset(array $paths, array $page): array
    {
        $layoutType = (string)$page['layout_type'];
        $layoutOption = (string)$page['layout_option'];
        $pageSuffix = '/pages/layouts/' . $layoutType . '/' . $layoutOption . '.phtml';
        $sourceNeedle = '/pages/layouts/' . $layoutType . '/' . $layoutOption . '/sources/';
        $candidates = [];
        foreach ($paths as $path) {
            $normalized = str_replace('\\', '/', $path);
            $keep = str_ends_with($normalized, $pageSuffix)
                || str_contains($normalized, $sourceNeedle)
                || str_contains($normalized, '/partials/');
            if (!$keep) {
                continue;
            }
            if (!is_file($path)) {
                $candidates[$path] = null;
                continue;
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new \RuntimeException('theme_layout_formal_compile_path_unreadable:' . $path);
            }
            $candidates[$path] = $bytes;
        }

        return $candidates;
    }

    private function readSourceHead(string $path): ?string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = fread($handle, 65536);
        fclose($handle);

        return is_string($head) ? $head : null;
    }

    private function isNestedCompileEnv(): bool
    {
        $flag = trim((string)(getenv(TemplateCompileService::ENV_NESTED)
            ?: ($_ENV[TemplateCompileService::ENV_NESTED] ?? $_SERVER[TemplateCompileService::ENV_NESTED] ?? '')));

        return $flag !== '' && $flag !== '0' && strtolower($flag) !== 'false';
    }

    /**
     * Explicit Formal post-com half: publish theme-head CSS/JS packs under identity.themeId.
     * ThemeData must already be pinned to $theme (caller); Gateway resolves namespace from current theme.
     */
    private function warmPublishedResourcePacks(
        string $area,
        WelineTheme $theme,
        string $themeLabel = '',
        string $canonicalScope = '',
    ): void {
        if ((int)$theme->getId() < 1) {
            throw new \RuntimeException('theme_layout_formal_warm_theme_missing');
        }
        // Re-pin inside warm so nested helpers that re-resolve Default cannot drift.
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        if ($themeLabel !== '') {
            $this->printing->note(sprintf(
                '%s · 主题=%s · 范围=%s · 区域=%s · 阶段2/2 资源合包预热',
                (string)__('主题布局发布'),
                $themeLabel,
                $canonicalScope !== '' ? $canonicalScope : '-',
                $area,
            ));
        }
        try {
            $this->layoutCriticalMaterializer->materializeForTheme($theme, $area);
            $this->headCssPack->warmAreaPacks($area);
            $this->headJsPack->warmAreaPacks($area);
            $this->printing->success(sprintf(
                '%s · 主题=%s · 阶段2/2 %s',
                (string)__('主题布局发布'),
                $themeLabel !== '' ? $themeLabel : '-',
                (string)__('资源合包已预热'),
            ));
        } catch (\Throwable $error) {
            $this->printing->error(sprintf(
                '%s · 主题=%s · 阶段2/2 %s: %s',
                (string)__('主题布局发布'),
                $themeLabel !== '' ? $themeLabel : '-',
                (string)__('资源合包预热失败'),
                $error->getMessage(),
            ));
        }
    }

    /**
     * Invalidate storefront FPC after Formal compile+warm.
     * Default scoped (drain / single-page); explicit cache_invalidation=all for cutover only.
     * Soft-fails CDN; never rolls back promoted com_*; never rotates themePublishedVersionId.
     *
     * @param list<array{layout_type:string,layout_option:string,target_type:string,target_id:int}> $pages
     * @param 'scoped'|'all' $invalidation
     */
    private function clearCachesAfterCompileWarm(
        WelineTheme $theme,
        ThemeVersionIdentity $identity,
        array $pages,
        string $invalidation,
    ): void {
        $themeId = (int)$theme->getId();
        if ($themeId < 1) {
            return;
        }
        $layouts = [];
        $layoutKeys = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $type = trim((string)($page['layout_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $option = trim((string)($page['layout_option'] ?? 'default'));
            if ($option === '' || $option === '*') {
                $option = 'default';
            }
            $layouts[] = ['type' => $type, 'option' => $option];
            $layoutKeys[] = $type . '/' . $option;
        }
        $layoutKeys = array_values(array_unique($layoutKeys));
        sort($layoutKeys);
        $fp = $layoutKeys === [] ? 'empty' : hash('sha256', implode('|', $layoutKeys));
        $debounceKey = $invalidation === 'all'
            ? ('all:' . $themeId)
            : ('scoped:' . $themeId . ':' . $fp);
        if (isset(self::$postWarmClearedKeys[$debounceKey])) {
            return;
        }
        if (!$this->acquirePostWarmClearGate($themeId, $invalidation, $fp)) {
            return;
        }
        self::$postWarmClearedKeys[$debounceKey] = true;
        try {
            if ($invalidation === 'all') {
                $result = $this->cacheCleaner->clearAllThemeRelatedCaches(
                    $themeId,
                    'theme_layout_formal_compile_warm',
                );
                foreach ((array)($result['failures'] ?? []) as $step => $message) {
                    $stepKey = (string)$step;
                    $msg = (string)$message;
                    if (str_starts_with($stepKey, 'cdn_') || str_contains($msg, 'cdn_')) {
                        continue;
                    }
                    $this->printing->warning(
                        'theme_layout_formal_compile_cache_clear_partial:' . $stepKey . '=' . $msg
                    );
                }
                $this->printing->note(sprintf(
                    '%s · 主题=#%d · %s',
                    (string)__('主题布局发布'),
                    $themeId,
                    (string)__('编译后缓存已清理'),
                ));

                return;
            }

            if ($layouts === []) {
                $this->printing->note(sprintf(
                    '%s · 主题=#%d · scoped purge skipped:no_layouts',
                    (string)__('主题布局发布'),
                    $themeId,
                ));

                return;
            }
            $targets = $this->themeApplicationUsage->ownSolidifyTargetsForTheme($themeId);
            if ($targets === []) {
                $scope = trim((string)$identity->canonicalScope);
                if ($scope !== '') {
                    $targets[] = [
                        'version_owner_scope' => $scope,
                        'store_mode' => 'normal',
                        'area' => 'frontend',
                    ];
                }
            }
            $result = $this->slugFpcPublisher->purgeLayoutsForThemeTargets(
                $themeId,
                $layouts,
                $targets,
                'theme_layout_formal_compile_scoped',
            );
            if (!($result['ok'] ?? false)) {
                $this->printing->warning(
                    'theme_layout_formal_compile_scoped_purge_skipped:'
                    . (string)($result['skipped'] ?? 'unknown')
                );
            } else {
                $this->printing->note(sprintf(
                    '%s · 主题=#%d · scoped purge slugs=%d targets=%d',
                    (string)__('主题布局发布'),
                    $themeId,
                    (int)($result['slug_count'] ?? 0),
                    (int)($result['target_count'] ?? 0),
                ));
            }
            foreach ($targets as $target) {
                if (!is_array($target)) {
                    continue;
                }
                if (trim((string)($target['area'] ?? 'frontend')) !== 'frontend') {
                    continue;
                }
                $scope = trim((string)($target['version_owner_scope'] ?? ''));
                if ($scope === '') {
                    continue;
                }
                $storeMode = trim((string)($target['store_mode'] ?? 'normal'));
                try {
                    $this->cacheCleaner->clearLayoutEntityCaches($themeId, $scope, $storeMode !== '' ? $storeMode : 'normal');
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $error) {
            $this->printing->warning(
                'theme_layout_formal_compile_cache_clear_failed:' . $error->getMessage()
            );
        }
    }

    /**
     * Cross-process coalesce: all=per theme; scoped=per theme+layout fingerprint
     * so different drain pages are not skipped by a sibling layout's window.
     */
    private function acquirePostWarmClearGate(int $themeId, string $invalidation, string $layoutFp): bool
    {
        $dir = rtrim((string)BP, '/\\') . '/var/locks/theme-formal-compile-cache-clear';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true;
        }
        $suffix = $invalidation === 'all' ? 'all' : ('scoped-' . $layoutFp);
        $stampPath = $dir . '/theme-' . $themeId . '-' . $suffix . '.stamp';
        $lockPath = $dir . '/theme-' . $themeId . '-' . $suffix . '.lock';
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            return true;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            clearstatcache(true, $stampPath);
            if (is_file($stampPath)) {
                $mtime = (int)@filemtime($stampPath);
                if ($mtime > 0 && (time() - $mtime) < self::POST_WARM_CLEAR_COALESCE_SECONDS) {
                    return false;
                }
            }
            @file_put_contents($stampPath, (string)time());

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function loadThemeForIdentity(ThemeVersionIdentity $identity): ?WelineTheme
    {
        $themeId = (int)$identity->themeId;
        if ($themeId < 1) {
            return null;
        }
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load($themeId);
        if ((int)$theme->getId() !== $themeId) {
            return null;
        }

        return $theme;
    }

    private function formatThemeProgressLabel(WelineTheme $theme, ThemeVersionIdentity $identity): string
    {
        $id = (int)$theme->getId();
        $name = trim((string)$theme->getName());
        $path = $this->shortThemePath((string)$theme->getPath());
        $version = (int)$identity->themeVersionId;
        $bits = ['theme#' . max(0, $id)];
        if ($name !== '') {
            $bits[] = $name;
        }
        if ($path !== '') {
            $bits[] = '@' . $path;
        }
        if ($version > 0) {
            $bits[] = 'V' . $version;
        }

        return implode(' ', $bits);
    }

    /** Prefer Vendor/theme relative path so CLI truncation keeps identity, not absolute BP. */
    private function shortThemePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        foreach (['/app/design/', '/app/code/'] as $marker) {
            $pos = strpos($path, $marker);
            if ($pos !== false) {
                return ltrim(substr($path, $pos + strlen($marker)), '/');
            }
        }
        // Already relative (Vendor/Name) or module path fragment.
        if (!str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:/#', $path)) {
            return $path;
        }

        return basename(rtrim($path, '/'));
    }

    /**
     * Pin WebsiteData + RequestContext + Url parser to the publish-site before
     * Taglib bake so literal url mounts match identity scope (not default site).
     */
    private function runPinnedToIdentityWebsite(ThemeVersionIdentity $identity, callable $fn): void
    {
        $website = $this->resolveWebsiteForCanonicalScope($identity->canonicalScope);
        if ($website === null) {
            $fn();

            return;
        }

        $websiteId = (int)$website->getWebsiteId();
        $websiteCode = trim((string)$website->getCode());
        if ($websiteCode === '') {
            $websiteCode = 'default';
        }
        $websiteUrl = trim((string)$website->getUrl());
        $scopeIdentity = $this->buildPinnedScopeIdentity($identity->canonicalScope, $websiteId, $websiteCode);

        $prevParser = [
            'id' => Url::$parserServer['WELINE_WEBSITE_ID'] ?? null,
            'code' => Url::$parserServer['WELINE_WEBSITE_CODE'] ?? null,
            'url' => Url::$parserServer['WELINE_WEBSITE_URL'] ?? null,
            'area' => Url::$parserServer['WELINE_AREA'] ?? null,
        ];

        RequestContext::runWithCompileTimeWebsitePin($scopeIdentity, $websiteUrl, function () use (
            $website,
            $websiteId,
            $websiteCode,
            $websiteUrl,
            $prevParser,
            $fn,
        ): void {
            WebsiteData::setWebsite($website);
            Url::$parserServer['WELINE_WEBSITE_ID'] = (string)$websiteId;
            Url::$parserServer['WELINE_WEBSITE_CODE'] = $websiteCode;
            Url::$parserServer['WELINE_WEBSITE_URL'] = $websiteUrl;
            Url::$parserServer['WELINE_AREA'] = 'frontend';
            try {
                $fn();
            } finally {
                if ($prevParser['id'] === null) {
                    unset(Url::$parserServer['WELINE_WEBSITE_ID']);
                } else {
                    Url::$parserServer['WELINE_WEBSITE_ID'] = $prevParser['id'];
                }
                if ($prevParser['code'] === null) {
                    unset(Url::$parserServer['WELINE_WEBSITE_CODE']);
                } else {
                    Url::$parserServer['WELINE_WEBSITE_CODE'] = $prevParser['code'];
                }
                if ($prevParser['url'] === null) {
                    unset(Url::$parserServer['WELINE_WEBSITE_URL']);
                } else {
                    Url::$parserServer['WELINE_WEBSITE_URL'] = $prevParser['url'];
                }
                if ($prevParser['area'] === null) {
                    unset(Url::$parserServer['WELINE_AREA']);
                } else {
                    Url::$parserServer['WELINE_AREA'] = $prevParser['area'];
                }
            }
        });
    }

    private function resolveWebsiteForCanonicalScope(string $canonicalScope): ?Website
    {
        $code = $this->websiteCodeFromCanonicalScope($canonicalScope);
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website->clear()->clearQuery()->load(Website::schema_fields_CODE, $code, true);
            if ($website->hasData(Website::schema_fields_ID)
                || ($code === Website::CODE_DEFAULT && (int)$website->getWebsiteId() === Website::ID_DEFAULT)
            ) {
                if (!$website->hasData(Website::schema_fields_CODE)) {
                    $website->setCode($code);
                    $website->setData(Website::schema_fields_ID, Website::ID_DEFAULT);
                }

                return $website;
            }
            if ($code === Website::CODE_DEFAULT) {
                $website->clear()->clearQuery()->load(Website::ID_DEFAULT, null, true);
                if (!$website->hasData(Website::schema_fields_CODE)) {
                    $website->setCode(Website::CODE_DEFAULT);
                    $website->setData(Website::schema_fields_ID, Website::ID_DEFAULT);
                }

                return $website;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function websiteCodeFromCanonicalScope(string $canonicalScope): string
    {
        $canonicalScope = strtolower(trim($canonicalScope));
        if ($canonicalScope === '' || $canonicalScope === 'global') {
            return Website::CODE_DEFAULT;
        }
        try {
            $parsed = ObjectManager::getInstance(ScopeHierarchyInterface::class)
                ->fromStorageScope($canonicalScope, true);
            if ($parsed instanceof ScopeIdentity && is_string($parsed->websiteCode) && $parsed->websiteCode !== '') {
                return $parsed->websiteCode;
            }
        } catch (\Throwable) {
        }
        $parts = explode('.', $canonicalScope);

        return trim((string)($parts[0] ?? '')) !== ''
            ? trim((string)$parts[0])
            : Website::CODE_DEFAULT;
    }

    private function buildPinnedScopeIdentity(
        string $canonicalScope,
        int $websiteId,
        string $websiteCode,
    ): ScopeIdentity {
        try {
            $parsed = ObjectManager::getInstance(ScopeHierarchyInterface::class)
                ->fromStorageScope($canonicalScope, true);
            if ($parsed instanceof ScopeIdentity && !$parsed->isGlobal()) {
                return match ($parsed->scopeKind) {
                    ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                        $websiteId,
                        $websiteCode,
                        (string)($parsed->storeCode ?? 'default'),
                        (string)($parsed->channelCode ?? 'default'),
                        (string)($parsed->storeMode ?? ScopeIdentity::MODE_NORMAL),
                    ),
                    ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                        $websiteId,
                        $websiteCode,
                        (string)($parsed->storeCode ?? 'default'),
                        (string)($parsed->storeMode ?? ScopeIdentity::MODE_NORMAL),
                    ),
                    default => ScopeIdentity::website($websiteId, $websiteCode),
                };
            }
        } catch (\Throwable) {
        }

        return ScopeIdentity::website($websiteId, $websiteCode);
    }

    /**
     * Per-source context_key (theme-scoped) so header/footer compile_id stays stable across pages.
     * Whole-snapshot fingerprint caused false com_* rewrites every page in the same identity.
     *
     * @param array<string, true> $compiledSourcePaths paths already compiled in this Formal run
     * @return array<string, array{bytes:string, origin:string, context_key:string, label:string}>
     */
    private function buildPinnedCompileSet(
        ThemeLayoutSourceSnapshot $snapshot,
        string $pagePath,
        ThemeVersionIdentity $identity,
        array &$compiledSourcePaths,
    ): array {
        $pinned = [];
        $pageSource = $snapshot->source($pagePath);
        if ($pageSource === null) {
            throw new \RuntimeException('theme_layout_formal_compile_page_source_missing');
        }
        $pinned[$pagePath] = [
            'bytes' => $pageSource['bytes'],
            'origin' => $pageSource['origin'],
            'context_key' => $this->sourceContextKey($identity, $pageSource),
            'label' => 'page:' . basename($pagePath),
        ];
        $compiledSourcePaths[$pagePath] = true;
        foreach (['header', 'footer'] as $partialType) {
            $partialPath = $snapshot->selectedPartialPath($partialType);
            if ($partialPath === null || isset($compiledSourcePaths[$partialPath])) {
                continue;
            }
            $partialSource = $snapshot->source($partialPath);
            if ($partialSource === null) {
                continue;
            }
            $pinned[$partialPath] = [
                'bytes' => $partialSource['bytes'],
                'origin' => $partialSource['origin'],
                'context_key' => $this->sourceContextKey($identity, $partialSource),
                'label' => 'partial:' . $partialType,
            ];
            $compiledSourcePaths[$partialPath] = true;
        }

        return $pinned;
    }

    /** @param array{bytes:string, origin:string} $source */
    private function sourceContextKey(ThemeVersionIdentity $identity, array $source): string
    {
        return hash(
            'sha256',
            (string)$identity->themeId . "\0"
            . $identity->area . "\0"
            . (string)($source['origin'] ?? '') . "\0"
            . hash('sha256', (string)$source['bytes']),
        );
    }

    /**
     * Rebuild a snapshot from promote candidates when disk capture missed the page
     * (e.g. staging absolute keys vs live Paths root). Remaps the page key to the
     * canonical Paths page path so fromCandidates can bind $page.
     *
     * @param array<string,?string>|null $candidates
     */
    private function snapshotFromPublishedCandidates(
        ThemeVersionIdentity $identity,
        ?array $candidates,
        string $layoutType,
        string $layoutOption,
        string $targetType,
        int $targetId,
    ): ?ThemeLayoutSourceSnapshot {
        $candidates = is_array($candidates) ? $candidates : [];
        if ($candidates === []) {
            return null;
        }
        $expected = $this->paths->pageLayoutPhtml($identity, $layoutType, $layoutOption, $targetType, $targetId);
        $suffix = '/pages/layouts/' . $layoutType . '/' . $layoutOption . '.phtml';
        $pageBytes = null;
        $pageKey = null;
        if (isset($candidates[$expected]) && is_string($candidates[$expected])) {
            $pageBytes = $candidates[$expected];
            $pageKey = $expected;
        } else {
            foreach ($candidates as $path => $bytes) {
                if (!is_string($path) || !is_string($bytes)) {
                    continue;
                }
                $normalized = str_replace('\\', '/', $path);
                if (str_contains($normalized, '/sources/')) {
                    continue;
                }
                if (!str_ends_with($normalized, $suffix)) {
                    continue;
                }
                $pageBytes = $bytes;
                $pageKey = $path;
                break;
            }
        }
        if ($pageBytes === null || $pageKey === null) {
            return null;
        }
        $remapped = $candidates;
        if ($pageKey !== $expected) {
            $remapped[$expected] = $pageBytes;
        }

        return ThemeLayoutSourceSnapshot::fromCandidates($identity, $expected, $remapped);
    }

    /** @param array<string,?string> $candidates
     * @return list<array{layout_type:string,layout_option:string,target_type:string,target_id:int}>
     */
    private function extractPublishedPageLayouts(array $candidates): array
    {
        $pages = [];
        $seen = [];
        foreach ($candidates as $path => $bytes) {
            if (!is_string($path) || !is_string($bytes)) {
                continue;
            }
            $normalized = str_replace('\\', '/', $path);
            if (!str_contains($normalized, '/pages/layouts/') || str_contains($normalized, '/sources/')) {
                continue;
            }
            $metadata = ThemeLayoutSourceSnapshot::metadata($bytes);
            if (!is_array($metadata)) {
                continue;
            }
            $layoutType = trim((string)($metadata['layout_type'] ?? ''));
            if ($layoutType === '') {
                continue;
            }
            $entry = [
                'layout_type' => $layoutType,
                'layout_option' => (string)($metadata['layout_option'] ?? 'default'),
                'target_type' => (string)($metadata['target_type'] ?? 'global'),
                'target_id' => max(0, (int)($metadata['target_id'] ?? 0)),
            ];
            $key = implode('|', $entry);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pages[] = $entry;
        }

        return $pages;
    }

    /** @return list<string> */
    private function resolveDefaultWebsiteLocales(): array
    {
        try {
            $codes = ObjectManager::getInstance(WebsiteLanguage::class)->getWebsiteLanguageCodes(Website::ID_DEFAULT);
        } catch (\Throwable) {
            $codes = [];
        }
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        foreach (['zh_Hans_CN', 'en_US'] as $baseline) {
            if (!in_array($baseline, $codes, true)) {
                $codes[] = $baseline;
            }
        }
        if ($codes === []) {
            throw new \RuntimeException('theme_layout_formal_compile_locales_empty');
        }

        return $codes;
    }

    /** @return list<string> */
    private function resolveInlineWidgetTemplates(string $html, string $area): array
    {
        if (!preg_match_all('~<w:widget\b[^>]*\bcode="([^"]+)"~i', $html, $matches)) {
            return [];
        }
        $registry = ObjectManager::getInstance(WidgetRegistryInterface::class)->getRegistry();
        $templates = [];
        foreach (array_unique($matches[1]) as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            $widget = $this->findWidgetByCode($registry, $code, $area);
            if ($widget === null) {
                continue;
            }
            $templateRef = trim((string)($widget['template'] ?? ''));
            if ($templateRef === '') {
                $module = (string)($widget['module'] ?? 'Weline_Theme');
                $templateRef = $module . '::templates/' . $area . '/widgets/' . $code . '.phtml';
            }
            $templates[] = $templateRef;
        }

        return array_values(array_unique($templates));
    }

    private function findWidgetByCode(array $registry, string $code, string $area): ?array
    {
        foreach ($registry as $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $widgetCode = (string)($widget['code'] ?? '');
                $widgetArea = (string)($widget['area'] ?? $area);
                if ($widgetCode === $code && ($widgetArea === '' || $widgetArea === $area)) {
                    return $widget;
                }
            }
        }

        return null;
    }
}
