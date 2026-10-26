<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Deploy\StaticAssetMode;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Deploy\StaticPublishExclusion;
use Weline\Framework\Http\Fpc\StaticAssetBrotliSidecar;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Scan;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService;
use Weline\Theme\Service\ThemeApplicationUsageService;
use Weline\Theme\Service\ThemeDesignImageStaticUrl;
use Weline\Theme\Service\ThemeResourceGateway;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Theme\Service\ThemeStaticNamespaceService;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;

class Upgrade implements \Weline\Framework\Console\CommandInterface
{
    /** @var list<string> */
    private const THEME_ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif',
        'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt',
        'xml', 'webmanifest', 'wasm', 'mp4', 'webm', 'mp3', 'ogg', 'wav',
    ];

    private WelineTheme $welineTheme;
    private Scan $scan;
    private System $system;
    private Printing $printing;
    private ThemeStaticNamespaceService $themeStaticNamespaceService;
    private ThemeResourceGateway $themeResourceGateway;
    private ThemeLayoutEntityUpgradeSolidifyService $layoutSolidifyService;

    public function __construct(
        WelineTheme $welineTheme,
        Printing $printing,
        System $system,
        Scan $scan,
        ThemeStaticNamespaceService $themeStaticNamespaceService,
        ThemeResourceGateway $themeResourceGateway,
        ThemeLayoutEntityUpgradeSolidifyService $layoutSolidifyService,
    ) {
        $this->welineTheme = $welineTheme;
        $this->scan = $scan;
        $this->system = $system;
        $this->printing = $printing;
        $this->themeStaticNamespaceService = $themeStaticNamespaceService;
        $this->themeResourceGateway = $themeResourceGateway;
        $this->layoutSolidifyService = $layoutSolidifyService;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        [
            $theme_name,
            $modules,
            $allVersions,
            $scopeFilter,
            $layoutType,
            $layoutOption,
            $flushStorefrontFpc,
        ] = self::parseArguments($args);
        $themes = $this->resolveThemesToUpgrade($theme_name);
        if ($themes === []) {
            throw new ConsoleException(__('未找到范围已绑定主题，请用 -t/--theme 指定主题名。'));
        }

        // 主题更新代次（原子切换）：构建进新代次目录 → 校验 → 只翻指针。不 purge、不开维护模式。
        if ($this->isBatchMode($args)) {
            $this->runBatchUpgrade($themes, $layoutType, $layoutOption);
            return;
        }

        /** @var StaticAssetMode $staticAssetMode */
        $staticAssetMode = ObjectManager::getInstance(StaticAssetMode::class);
        // Registry may be stale until the next framework compile; keep the direct path.
        if ($staticAssetMode->publishForThemeUpgrade($themes, $modules) < 1) {
            foreach ($themes as $theme) {
                $this->upgradeOneTheme($theme, $modules);
            }
        }

        // C-RP-07：-t 指定主题；无 -t → 仅范围已绑定主题；默认正式+草稿；--all 全历史版本
        // 布局级联：无 -l → 全部布局；-l 无 -o → 该布局下全部选项；-l -o → 仅该选项
        $solidifyOptions = [];
        if ($allVersions) {
            $solidifyOptions['all_versions'] = true;
        }
        if ($scopeFilter !== '') {
            $solidifyOptions['scope'] = $scopeFilter;
        }
        if ($layoutType !== '') {
            $solidifyOptions['layout_type'] = $layoutType;
        }
        if ($layoutOption !== '') {
            $solidifyOptions['layout_option'] = $layoutOption;
        }
        // Never let the solidify cutover do a site-wide clear here. A theme
        // upgrade invalidates only the layouts it re-bakes; -f is the explicit
        // full-clear escape hatch and runs after the cutover.
        $solidifyOptions['cache_invalidation'] = 'none';
        $this->printing->warning(__('开始 purge 旧布局固化物并重固（generated/theme-layout-entities）…'));
        if ($allVersions) {
            $this->printing->note(__('版本范围：全部历史版本（--all）'));
        } else {
            $this->printing->note(__('版本范围：当前正式版 + 当前草稿'));
        }
        if ($scopeFilter !== '') {
            $this->printing->note(__('范围过滤：') . $scopeFilter);
        }
        if ($layoutType !== '') {
            $this->printing->note(
                $layoutOption !== ''
                    ? __('布局过滤：') . $layoutType . '/' . $layoutOption
                    : __('布局过滤：') . $layoutType . __('（该布局下全部选项）')
            );
        }
        $cutover = $this->layoutSolidifyService->cutoverFromThemeCommand(
            $theme_name !== '' ? ($themes[0] ?? null) : null,
            $solidifyOptions,
        );
        $this->printing->success(__(
            '布局模板切流：purge %{purged}，legacy %{legacy}，固化 %{count}',
            [
                'purged' => (int)($cutover['purged'] ?? 0),
                'legacy' => (int)($cutover['purged_legacy'] ?? 0),
                'count' => (int)($cutover['solidified'] ?? 0),
            ]
        ));

        // 默认只按本次涉及的主题/布局清店面 FPC 叶子与 CDN URL；
        // -f 才是显式的全量 FPC/Shared/CDN 清理（故障恢复用，不是日常发版路径）。
        if ($flushStorefrontFpc) {
            $this->flushStorefrontFpcAfterUpgrade(
                $theme_name !== '' ? ($themes[0] ?? null) : null,
            );
        } else {
            $this->flushScopedStorefrontCaches($themes, $scopeFilter, $layoutType, $layoutOption);
        }
    }

    /**
     * 主题发版的默认失效面：本次升级主题的全部（或 -l/-o 过滤后的）前端布局。
     * `ThemeStorefrontSlugFpcPublisher` 产出 impact.urls/slugs，Changed 管道只清
     * 这些 URL 的 FPC + CDN，并只 bump 对应 scope 的 Theme 世代；不触碰全站 CDN。
     *
     * @param list<WelineTheme> $themes
     */
    private function flushScopedStorefrontCaches(
        array $themes,
        string $scopeFilter,
        string $layoutType,
        string $layoutOption,
    ): void {
        /** @var ThemeStorefrontSlugFpcPublisher $publisher */
        $publisher = ObjectManager::getInstance(ThemeStorefrontSlugFpcPublisher::class);
        /** @var ThemeApplicationUsageService $usage */
        $usage = ObjectManager::getInstance(ThemeApplicationUsageService::class);

        $emitted = 0;
        $failedThemes = [];
        foreach ($themes as $theme) {
            if (!$theme instanceof WelineTheme) {
                continue;
            }
            $themeId = (int)$theme->getId();
            if ($themeId < 1) {
                continue;
            }
            $layouts = $this->resolveScopedLayouts($theme, $layoutType, $layoutOption);
            if ($layouts === []) {
                $this->printing->warning(__('主题 #%{id} 无可解析前端布局，跳过 scoped FPC/CDN 清理。', [
                    'id' => $themeId,
                ]));
                continue;
            }
            $targets = $usage->ownSolidifyTargetsForTheme($themeId);
            if ($scopeFilter !== '') {
                $targets = \array_values(\array_filter(
                    $targets,
                    static fn(array $target): bool => \strcasecmp(
                        \trim((string)($target['version_owner_scope'] ?? '')),
                        $scopeFilter,
                    ) === 0,
                ));
            }
            if ($targets === []) {
                $this->printing->warning(__('主题 #%{id} 无前端站点绑定，跳过 scoped FPC/CDN 清理。', [
                    'id' => $themeId,
                ]));
                continue;
            }
            $result = $publisher->purgeLayoutsForThemeTargets(
                $themeId,
                $layouts,
                $targets,
                'theme_upgrade_scoped',
            );
            $emitted += (int)($result['target_count'] ?? 0);
            if (($result['errors'] ?? []) !== []) {
                foreach ((array)$result['errors'] as $error) {
                    $this->printing->error(__('主题 #%{id} scoped FPC/CDN 清理失败：%{error}', [
                        'id' => $themeId,
                        'error' => (string)$error,
                    ]));
                }
                $failedThemes[$themeId] = $themeId;
                continue;
            }
            if (!($result['ok'] ?? false)) {
                $this->printing->warning(__('主题 #%{id} scoped FPC/CDN 清理跳过：%{reason}', [
                    'id' => $themeId,
                    'reason' => (string)($result['skipped'] ?? 'unknown'),
                ]));
            }
        }

        if ($failedThemes !== []) {
            throw new ConsoleException(__(
                '主题 %{ids} 的店面 FPC/CDN 清理失败；旧页面可能仍在缓存中，请按上方原因修复后重跑。',
                ['ids' => '#' . \implode(', #', $failedThemes)]
            ));
        }
        if ($emitted < 1) {
            $this->printing->warning(__(
                '未清理任何边缘 HTML；旧页面会在边缘 TTL（默认 300 秒）内自然过期，'
                . '或检查主题是否绑定到前端站点。'
            ));
            return;
        }
        $this->printing->success(__('按布局清理店面 FPC/CDN URL 完成：%{count} 个 scope。', [
            'count' => $emitted,
        ]));
    }

    /**
     * @return list<array{type:string,option:string}>
     */
    private function resolveScopedLayouts(
        WelineTheme $theme,
        string $layoutType,
        string $layoutOption,
    ): array {
        if ($layoutType !== '' && $layoutOption !== '') {
            return [['type' => $layoutType, 'option' => $layoutOption]];
        }
        try {
            $catalog = LayoutScanner::scanLayouts($theme, 'frontend');
        } catch (\Throwable) {
            $catalog = [];
        }
        $out = [];
        foreach ($catalog as $type => $options) {
            $type = \trim((string)$type);
            if ($type === '' || ($layoutType !== '' && $type !== $layoutType)) {
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
        if ($out === [] && $layoutType !== '') {
            $out[] = ['type' => $layoutType, 'option' => 'default'];
        }
        $seen = [];
        $unique = [];
        foreach ($out as $layout) {
            $key = $layout['type'] . '/' . $layout['option'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $layout;
        }

        return $unique;
    }

    /**
     * Force-clear storefront FPC pools, WLS Shared FPC, CDN full-page cache,
     * then staging→promote. Storefront warm is left to the cron warmer, which
     * reacts to the theme generation bump (no direct warm here).
     */
    private function flushStorefrontFpcAfterUpgrade(?WelineTheme $theme): void
    {
        $themeId = ($theme instanceof WelineTheme && (int)$theme->getId() > 0)
            ? (int)$theme->getId()
            : null;
        $this->printing->warning(__(
            '强制清理店面 FPC / Shared FPC / CDN 全页缓存（-f）…'
        ));
        if ($themeId !== null) {
            $this->printing->note(__('主题 ID：') . (string)$themeId);
        } else {
            $this->printing->note(__('范围：全部主题命名空间（未指定 -t）'));
        }

        /** @var ThemeRuntimeCacheCleaner $cleaner */
        $cleaner = ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class);
        $result = $cleaner->clearAllThemeRelatedCaches($themeId, 'theme_upgrade_force_fpc');
        $steps = \is_array($result['steps'] ?? null) ? $result['steps'] : [];
        $failures = \is_array($result['failures'] ?? null) ? $result['failures'] : [];
        $ok = 0;
        $fail = 0;
        foreach ($steps as $step => $passed) {
            if ($passed) {
                $ok++;
                continue;
            }
            $fail++;
            $detail = \trim((string)($failures[(string)$step] ?? ''));
            $this->printing->warning(
                __('步骤失败：') . (string)$step . ($detail !== '' ? ' — ' . $detail : '')
            );
        }
        if (isset($failures['cdn_full_page_purge'])) {
            // `-f` means "I need the edge invalidated now". A warning plus
            // exit 0 here is the exact false-success that made operators
            // believe Cloudflare had been cleared while it kept serving old
            // HTML. Promoted artifacts are intentionally not rolled back;
            // only the command result turns red.
            throw new ConsoleException(__(
                'CDN 全页缓存清理失败，边缘可能继续返回旧 HTML。已 promote 的产物不会回滚；'
                . '请配置 CDN API 令牌后重试，或等待边缘 HTML TTL（默认 300 秒）自然过期。详情：%{detail}',
                ['detail' => \trim((string)$failures['cdn_full_page_purge'])]
            ));
        }
        $this->printing->success(__(
            '店面 FPC/CDN 清理完成：成功 %{ok}，失败 %{fail}',
            ['ok' => $ok, 'fail' => $fail]
        ));
    }

    /**
     * Resolve themes to publish.
     *
     * Named `-t` → that theme only.
     * No `-t` → unique themes from websites_theme_application (frontend own)
     * + backend_theme_application + registered Default（范围已绑定；未绑定主题跳过）.
     *
     * @return list<WelineTheme>
     */
    public function resolveThemesToUpgrade(string $themeName): array
    {
        if ($themeName !== '') {
            $theme = ObjectManager::create(WelineTheme::class, [], false);
            $theme->clear()->clearQuery()->load(WelineTheme::schema_fields_NAME, $themeName);
            if (!$theme->getId()) {
                throw new ConsoleException(__('主题不存在：') . $themeName);
            }

            return [$theme];
        }

        /** @var \Weline\Theme\Service\ThemeApplicationUsageService $usage */
        $usage = ObjectManager::getInstance(\Weline\Theme\Service\ThemeApplicationUsageService::class);

        return $usage->themesForDefaultUpgrade();
    }

    /**
     * Publish static assets for one theme (design overlay + module theme assets).
     * Used by theme:upgrade and by ThemeLayoutOriginWatchService (dev Cron).
     *
     * @param list<string> $modules
     */
    public function publishStaticAssets(WelineTheme $theme, array $modules = []): void
    {
        $this->upgradeOneTheme($theme, $modules);
    }

    /**
     * @param list<string> $modules
     */
    private function upgradeOneTheme(WelineTheme $theme, array $modules): void
    {
        $publicThemePath = $this->themeStaticNamespaceService->resolvePublicThemePath($theme);
        if ($publicThemePath === '') {
            throw new ConsoleException(__('无法解析主题静态资源命名空间。'));
        }

        $this->printing->warning(__('收集') . $theme->getName() . __('主题文件...'));
        $this->printing->note(__('静态命名空间：') . $publicThemePath);

        $themes_files_data = [];
        if ($modules) {
            foreach ($modules as $module) {
                $this->printing->note($module);
                $module_path = str_replace('_', DS, $module);
                $themes_files_data = array_merge(
                    $themes_files_data,
                    $this->fetchThemeFiles($theme, $theme->getPath() . $module_path)
                );
            }
        } else {
            $themes_files_data = $this->fetchThemeFiles($theme, $theme->getPath());
        }

        $this->printing->warning(__('开始搬迁设计主题覆盖文件...'));
        $copied = 0;
        foreach ($themes_files_data as $origin_themes_file => $themes_files) {
            $this->printing->note($origin_themes_file . ' => ') . $this->printing->success($themes_files);
            $this->copyThemeFile($origin_themes_file, $themes_files);
            $copied++;
        }
        $this->printing->success(__('设计主题覆盖：') . $copied . __(' 个文件'));

        $this->printing->warning(__('开始发布模块 view/theme 资源到主题命名空间（含继承回退）...'));
        $published = $this->publishInheritedModuleThemeAssets($theme, $publicThemePath, $modules);
        $this->printing->success(
            __('模块主题资源：') . $published . __(' 个文件已发布到 /static/') . $publicThemePath . '/...'
        );

        // 设计主题根下 {area}/assets/... 会被搬到 /static/{ns}/{area}/...，但模板
        // <theme:js>Weline_Theme::theme/{area}/...</theme:js> 生成的 URL 是
        // /static/{ns}/Weline/Theme/view/theme/{area}/...。PROD / NG / WLS 快路径
        // 缺文件直接 404，不会走 ThemeResourceGateway 延迟补发。CSS/JS/字体须双写到
        // Theme 模块路径；图片不双写——继承换背景图即可，CSS url 重写指向 area 原地址。
        $this->printing->warning(__('开始将设计主题 area 非图片资源双写到 Weline/Theme/view/theme URL 路径...'));
        $mirrored = $this->publishDesignAreaAssetsIntoThemeModuleNamespace($theme, $publicThemePath, $modules);
        $this->printing->success(
            __('设计 area→Theme 模块路径（跳过图片）：') . $mirrored . __(' 个文件')
        );
    }

    /**
     * @return array{0:string,1:list<string>,2:bool,3:string,4:string,5:string,6:bool}
     *         themeName, modules, allVersions, scopeFilter, layoutType, layoutOption, flushStorefrontFpc
     */
    public static function parseArguments(array $args): array
    {
        $positionals = [];
        foreach ($args as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $positionals[] = $value;
            }
        }

        if (isset($positionals[0]) && in_array($positionals[0], ['theme:upgrade', 'theme:up'], true)) {
            array_shift($positionals);
        }

        $themeName = '';
        $modules = [];
        $allVersions = false;
        $scopeFilter = '';
        $layoutType = '';
        $layoutOption = '';
        $flushStorefrontFpc = false;
        for ($index = 0, $count = count($positionals); $index < $count; $index++) {
            $argument = $positionals[$index];
            if ($argument === '-t' || $argument === '--theme') {
                $themeName = trim((string)($positionals[$index + 1] ?? ''));
                if ($themeName === '') {
                    throw new ConsoleException(__('设置了 -t 参数，但却没有-t参数值！'));
                }
                $index++;
                continue;
            }
            if ($argument === '-s' || $argument === '--scope') {
                $scopeFilter = trim((string)($positionals[$index + 1] ?? ''));
                if ($scopeFilter === '') {
                    throw new ConsoleException(__('设置了 -s/--scope 参数，但却没有范围值！'));
                }
                $index++;
                continue;
            }
            if ($argument === '-l' || $argument === '--layout') {
                $layoutType = trim((string)($positionals[$index + 1] ?? ''));
                if ($layoutType === '') {
                    throw new ConsoleException(__('设置了 -l/--layout 参数，但却没有布局类型值！'));
                }
                $index++;
                continue;
            }
            if ($argument === '-o' || $argument === '--option' || $argument === '--layout-option') {
                $layoutOption = trim((string)($positionals[$index + 1] ?? ''));
                if ($layoutOption === '') {
                    throw new ConsoleException(__('设置了 -o/--option 参数，但却没有布局选项值！'));
                }
                $index++;
                continue;
            }
            if ($argument === '-a' || $argument === '--all') {
                $allVersions = true;
                continue;
            }
            if ($argument === '-f' || $argument === '--force') {
                $flushStorefrontFpc = true;
                continue;
            }
            if (!str_starts_with($argument, '-')) {
                $modules[] = $argument;
            }
        }

        if ($themeName === '') {
            $named = trim((string)($args['t'] ?? $args['theme'] ?? ''));
            if ($named !== '') {
                $themeName = $named;
            }
        }
        if ($scopeFilter === '') {
            $namedScope = trim((string)($args['s'] ?? $args['scope'] ?? ''));
            if ($namedScope !== '') {
                $scopeFilter = $namedScope;
            }
        }
        if ($layoutType === '') {
            $namedLayout = trim((string)($args['l'] ?? $args['layout'] ?? ''));
            if ($namedLayout !== '') {
                $layoutType = $namedLayout;
            }
        }
        if ($layoutOption === '') {
            $namedOption = trim((string)($args['o'] ?? $args['option'] ?? $args['layout-option'] ?? ''));
            if ($namedOption !== '') {
                $layoutOption = $namedOption;
            }
        }
        if (!$allVersions) {
            $allFlag = $args['a'] ?? $args['all'] ?? null;
            if ($allFlag === true || $allFlag === 1 || $allFlag === '1' || $allFlag === '') {
                $allVersions = true;
            }
        }
        if (!$flushStorefrontFpc) {
            $forceFlag = $args['f'] ?? $args['force'] ?? null;
            if ($forceFlag === true || $forceFlag === 1 || $forceFlag === '1' || $forceFlag === '') {
                $flushStorefrontFpc = true;
            }
        }
        if ($layoutOption !== '' && $layoutType === '') {
            throw new ConsoleException(__('指定了布局选项（-o/--option）时必须同时指定布局类型（-l/--layout）。'));
        }

        return [
            $themeName,
            array_values(array_unique($modules)),
            $allVersions,
            $scopeFilter,
            $layoutType,
            $layoutOption,
            $flushStorefrontFpc,
        ];
    }

    private function copyThemeFile(string $source, string $destinationDirectory): void
    {
        if (!is_dir($destinationDirectory)
            && !mkdir($destinationDirectory, 0755, true)
            && !is_dir($destinationDirectory)
        ) {
            throw new ConsoleException(__('无法创建主题静态资源目录：') . $destinationDirectory);
        }

        $destination = rtrim($destinationDirectory, '/\\') . DIRECTORY_SEPARATOR . basename($source);
        if (!copy($source, $destination)) {
            throw new ConsoleException(__('无法发布主题静态资源：') . $source);
        }
        StaticAssetBrotliSidecar::publish($destination);
    }

    /**
     * Mirror design-theme `{area}/**` non-image runtime assets into the URL
     * namespace used by `<theme:*>` / `Weline_Theme::theme/{area}/...` tags:
     * `/static/{ns}/Weline/Theme/view/theme/{area}/...`.
     *
     * Design overlay copy already writes `/static/{ns}/{area}/...` (relative to
     * theme root). CSS/JS/fonts still need this second publish for theme-tag URLs
     * (PROD WLS fastpath 404s otherwise). Images stay on the area path only —
     * themes inherit widgets and swap backgrounds without duplicating bitmaps.
     *
     * @param list<string> $moduleFilter when non-empty and excludes Weline_Theme, skip
     */
    private function publishDesignAreaAssetsIntoThemeModuleNamespace(
        WelineTheme $theme,
        string $publicThemePath,
        array $moduleFilter,
    ): int {
        if ($moduleFilter !== [] && !in_array('Weline_Theme', $moduleFilter, true)) {
            return 0;
        }

        $themeRoot = rtrim(str_replace('\\', '/', (string)$theme->getPath()), '/');
        if ($themeRoot === '' || !is_dir($themeRoot)) {
            return 0;
        }

        $extensionSet = array_fill_keys(self::THEME_ASSET_EXTENSIONS, true);
        $published = 0;

        foreach (['frontend', 'backend'] as $area) {
            $areaRoot = $themeRoot . '/' . $area;
            if (!is_dir($areaRoot)) {
                continue;
            }

            foreach ($this->listThemeAssetRelativePaths($areaRoot, $extensionSet) as $relativePath) {
                if (self::isExcludedPublishPath($themeRoot, $areaRoot . '/' . $relativePath)) {
                    continue;
                }
                $extension = strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));
                if (ThemeDesignImageStaticUrl::isImageExtension($extension)) {
                    continue;
                }

                $requestPath = self::buildNamespacedModuleThemeRequestPath(
                    $publicThemePath,
                    'Weline',
                    'Theme',
                    $area,
                    $relativePath,
                );
                $publicPath = $this->themeResourceGateway->publishForRequestPath($requestPath, $theme);
                if (is_string($publicPath) && $publicPath !== '') {
                    $published++;
                }
            }
        }

        return $published;
    }

    /**
     * Publish each active module's view/theme assets into /static/{themeNamespace}/...
     * Resolution walks theme inheritance (design override → parent → native).
     *
     * @param list<string> $moduleFilter module names like Weline_Theme; empty = all active
     */
    private function publishInheritedModuleThemeAssets(
        WelineTheme $theme,
        string $publicThemePath,
        array $moduleFilter,
    ): int {
        $modules = Env::getInstance()->getActiveModules();
        $extensionSet = array_fill_keys(self::THEME_ASSET_EXTENSIONS, true);
        $published = 0;

        foreach ($modules as $module) {
            $moduleName = (string)($module['name'] ?? '');
            if ($moduleName === '' || !str_contains($moduleName, '_')) {
                continue;
            }
            if ($moduleFilter !== [] && !in_array($moduleName, $moduleFilter, true)) {
                continue;
            }

            [$vendor, $short] = explode('_', $moduleName, 2);
            $basePath = rtrim((string)($module['base_path'] ?? ''), '/\\');
            if ($basePath === '') {
                continue;
            }

            $themeSource = $basePath . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme';
            if (!is_dir($themeSource)) {
                continue;
            }

            foreach (['frontend', 'backend'] as $area) {
                $areaRoot = $themeSource . DIRECTORY_SEPARATOR . $area;
                if (!is_dir($areaRoot)) {
                    continue;
                }

                foreach ($this->listThemeAssetRelativePaths($areaRoot, $extensionSet) as $relativePath) {
                    $requestPath = self::buildNamespacedModuleThemeRequestPath(
                        $publicThemePath,
                        $vendor,
                        $short,
                        $area,
                        $relativePath,
                    );
                    $publicPath = $this->themeResourceGateway->publishForRequestPath($requestPath, $theme);
                    if (is_string($publicPath) && $publicPath !== '') {
                        $published++;
                    }
                }
            }
        }

        return $published;
    }

    /**
     * @param array<string,bool> $extensionSet
     * @return list<string> POSIX-relative paths under area root
     */
    private function listThemeAssetRelativePaths(string $areaRoot, array $extensionSet): array
    {
        $areaRoot = rtrim(str_replace('\\', '/', $areaRoot), '/');
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($areaRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $ext = strtolower((string)$fileInfo->getExtension());
            if (!isset($extensionSet[$ext])) {
                continue;
            }
            $absolute = str_replace('\\', '/', $fileInfo->getPathname());
            if (!str_starts_with($absolute, $areaRoot . '/')) {
                continue;
            }
            $relative = ltrim(substr($absolute, strlen($areaRoot)), '/');
            if ($relative === '' || str_contains('/' . $relative . '/', '/../')) {
                continue;
            }
            $paths[] = $relative;
        }
        sort($paths);

        return $paths;
    }

    public static function buildNamespacedModuleThemeRequestPath(
        string $publicThemePath,
        string $vendor,
        string $module,
        string $area,
        string $relativePath,
    ): string {
        $publicThemePath = trim(str_replace('\\', '/', $publicThemePath), '/');
        $vendor = trim(str_replace(['\\', '/'], '', $vendor));
        $module = trim(str_replace(['\\', '/'], '', $module));
        $area = $area === 'backend' ? 'backend' : 'frontend';
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        return '/static/'
            . $publicThemePath
            . '/'
            . $vendor
            . '/'
            . $module
            . '/view/theme/'
            . $area
            . '/'
            . $relativePath;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('更新主题静态资源（设计覆盖 + 模块 view/theme；省略 -t 时仅范围已绑定主题；默认正式+草稿；默认只按布局清 FPC/CDN URL，-f 才全量清）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:upgrade',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-t, --theme <name>' => '指定主题名（如 daocharms / hanfu）；省略则仅处理范围已绑定主题（未绑定跳过）',
                '-s, --scope <canonical>' => '仅处理指定规范范围（如 default.default.default）',
                '-l, --layout <type>' => '仅固化指定布局类型（如 homepage / checkout）；省略则处理全部布局',
                '-o, --option <name>' => '仅固化该布局下指定选项（如 default）；须同时提供 -l；省略 -o 则处理该布局下全部选项',
                '-a, --all' => '部署并编译该主题（或全部站绑主题）下全部历史版本；默认仅当前正式版+草稿',
                '-f, --force' => '显式全量清理店面 FPC / Shared FPC / CDN 全页缓存并 staging→promote（仅故障恢复；不直接预热，预热由定时任务按世代变化触发；日常发版默认只按布局清 FPC/CDN URL）',
                '--inplace' => '旧流程：就地 purge+固化（需维护模式；仅应急/兼容）。默认走原子代次通道',
                '--batch, --atomic' => '（默认）原子代次：构建进新代次目录→校验→只翻指针；不 purge、不需要维护模式',
            ],
            [
                'module...' => '可选：只处理设计主题下指定模块目录（如 Weline_Theme）',
            ],
            [
                '范围已绑定主题（正式+草稿）' => 'php bin/w theme:upgrade',
                '指定主题' => 'php bin/w theme:upgrade -t daocharms',
                '指定范围' => 'php bin/w theme:upgrade -t hanfu --scope default.default.default',
                '指定布局全部选项' => 'php bin/w theme:upgrade -t hanfu -l homepage',
                '指定布局+选项' => 'php bin/w theme:upgrade -t hanfu -l homepage -o default',
                '全部历史版本' => 'php bin/w theme:upgrade --all -t hanfu',
                '指定主题+模块过滤' => 'php bin/w theme:upgrade -t daocharms Weline_Theme',
                '主题发版（按布局清 FPC/CDN URL）' => 'php bin/w theme:upgrade -t hanfu',
                '原子代次更新（推荐）' => 'php bin/w theme:upgrade -t hanfu --batch',
                '显式全量清（故障恢复）' => 'php bin/w theme:upgrade -t hanfu -f',
            ]
        );
    }

    public function fetchThemeFiles($theme, $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $themes_files_data = [];
        $theme_extend_files = $this->scan->scanDirTree($path);
        $publicThemePath = $this->themeStaticNamespaceService->resolvePublicThemePath($theme);
        if ($publicThemePath === '') {
            throw new ConsoleException(__('无法解析主题静态资源命名空间。'));
        }
        foreach ($theme_extend_files as $theme_extend_file) {
            /** @var \Weline\Framework\System\File\Data\File $file */
            foreach ($theme_extend_file as $file) {
                $file_path = $file->getOrigin();
                // Server templates (design {Module}/templates|email) are resolved from source —
                // never publish into pub/static. Path-segment skip aligns with StaticPublishExclusion
                // (.phtml) as defense-in-depth for empty dirs / non-phtml leftovers.
                if (str_contains($file_path, DS . 'templates' . DS)
                    || str_contains($file_path, DS . 'email' . DS)
                    || str_ends_with($file_path, DS . 'register.php')
                    || self::isExcludedPublishPath($theme->getPath(), $file_path)
                ) {
                    continue;
                }

                $destinationDirectory = self::buildDestinationDirectory(
                    $theme->getPath(),
                    $file_path,
                    $publicThemePath,
                    DeployStagingSession::staticRoot(),
                );
                if ($destinationDirectory === null) {
                    // Fail-soft: one out-of-root scan entry must not abort bare theme:upgrade.
                    $this->printing->warning(__('跳过主题外文件（不在允许目录内）：') . $file_path);
                    continue;
                }
                $themes_files_data[$file_path] = $destinationDirectory . DS;
            }
        }

        return $themes_files_data;
    }

    /**
     * 设计目录同样铺进 pub/static（Web 根），故文档与测试必须排除。
     *
     * 真实事故：app/design/{theme}/doc 与 /test 下的内部资料、`*.py` 与
     * `*.candidate.php` 被搬到 pub/static 后可从浏览器直接读取。
     */
    private static function isExcludedPublishPath(string $themeRoot, string $sourceFile): bool
    {
        $root = rtrim(str_replace('\\', '/', $themeRoot), '/');
        $source = str_replace('\\', '/', $sourceFile);
        if ($root === '' || !str_starts_with($source, $root . '/')) {
            return false;
        }

        return StaticPublishExclusion::isExcluded(ltrim(substr($source, strlen($root)), '/'));
    }

    public static function buildDestinationDirectory(
        string $themeRoot,
        string $sourceFile,
        string $publicThemePath,
        string $staticRoot,
    ): ?string {
        $themeRoot = rtrim(str_replace('\\', '/', $themeRoot), '/');
        $sourceFile = str_replace('\\', '/', $sourceFile);
        $publicThemePath = trim(str_replace('\\', '/', $publicThemePath), '/');
        $staticRoot = rtrim(str_replace('\\', '/', $staticRoot), '/');

        if ($themeRoot === '' || $sourceFile === '' || $publicThemePath === '' || $staticRoot === '') {
            return null;
        }
        if ($sourceFile !== $themeRoot && !str_starts_with($sourceFile, $themeRoot . '/')) {
            return null;
        }

        $relativePath = ltrim(substr($sourceFile, strlen($themeRoot)), '/');
        if ($relativePath === '' || str_contains('/' . $relativePath . '/', '/../')) {
            return null;
        }

        $destinationFile = $staticRoot . '/' . $publicThemePath . '/' . $relativePath;

        return str_replace('/', DS, dirname($destinationFile));
    }

    /**
     * 是否走「主题更新代次」通道。
     *
     * 默认走代次（原子切换、不 purge、不需要维护模式）；
     * `--inplace` 或 `--maintenance` 保留旧的就地 purge+固化流程（仅应急/兼容）。
     */
    private function isBatchMode(array $args): bool
    {
        $inplace = false;
        foreach ($args as $key => $value) {
            if (\is_int($key)) {
                $arg = (string)$value;
                if (\in_array($arg, ['--inplace', '--maintenance'], true)) {
                    $inplace = true;
                }
                if (\in_array($arg, ['--batch', '--atomic'], true)) {
                    return true;
                }
                continue;
            }
            if (\in_array((string)$key, ['inplace', 'maintenance'], true) && $value) {
                $inplace = true;
            }
            if (\in_array((string)$key, ['batch', 'atomic'], true) && $value) {
                return true;
            }
        }

        return !$inplace;
    }

    /**
     * 构建新代次并原子切换（不 purge、不需要维护模式）。
     *
     * @param list<WelineTheme> $themes
     */
    private function runBatchUpgrade(array $themes, string $layoutType, string $layoutOption): void
    {
        $buildOptions = [];
        if ($layoutType !== '') {
            $buildOptions['layouts'] = [$layoutType];
            if ($layoutOption !== '') {
                $buildOptions['options'] = [$layoutOption];
            }
        }

        /** @var \Weline\Theme\Service\ThemeUpdate\ThemeUpdateBuildService $builder */
        $builder = ObjectManager::getInstance(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateBuildService::class);
        /** @var \Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService $batches */
        $batches = ObjectManager::getInstance(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService::class);
        /** @var \Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlightRunner $flights */
        $flights = ObjectManager::getInstance(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlightRunner::class);

        $failedThemes = [];
        foreach ($themes as $theme) {
            if (!$theme instanceof WelineTheme) {
                continue;
            }
            $themeId = (int)$theme->getId();
            if ($themeId < 1) {
                continue;
            }
            $this->printing->note(__('主题 #%{id}：构建更新代次（不影响线上）…', ['id' => $themeId]));
            $scope = \Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlight::scopeForTheme($themeId);
            $result = $flights->execute(
                $scope,
                function (string $batchId) use ($builder, $batches, $flights, $themeId, $buildOptions, $scope): array {
                    $buildOptions['batch_id'] = $batchId;
                    $built = $builder->build($themeId, $buildOptions);
                    $manifest = $built['manifest'] ?? [];
                    if (($manifest['verify']['ok'] ?? false) !== true) {
                        return $built;
                    }
                    $state = $batches->promote($batchId);
                    $flights->finishProcessing($scope);

                    return $built + ['state' => $state];
                }
            );
            if (($result['skipped'] ?? '') === 'coalesced') {
                $this->printing->note(__('主题 #%{id} 已有更新在进行，本次已并入最后一次。', ['id' => $themeId]));
                continue;
            }
            $manifest = $result['manifest'] ?? [];
            if (($manifest['verify']['ok'] ?? false) !== true) {
                $this->printing->error(__('主题 #%{id} 构建校验未通过，未切换：%{batch}', [
                    'id' => $themeId,
                    'batch' => $result['batch_id'],
                ]));
                continue;
            }
            $state = (array)($result['state'] ?? []);
            $this->printing->success(__('主题 #%{id} 已切到代次 %{batch}（epoch=%{epoch}，previous=%{prev}）', [
                'id' => $themeId,
                'batch' => $state['current'],
                'epoch' => $state['epoch'],
                'prev' => $state['previous'] !== '' ? $state['previous'] : '-',
            ]));
            foreach ((array)($state['post_switch_errors'] ?? []) as $error) {
                $this->printing->error(__('主题 #%{id} 切换后清理失败：%{error}', [
                    'id' => $themeId,
                    'error' => (string)$error,
                ]));
                $failedThemes[$themeId] = $themeId;
            }
        }
        if ($failedThemes !== []) {
            throw new ConsoleException(__(
                '主题 %{ids} 已切换代次，但缓存/边缘清理失败；旧页面可能仍在缓存中，请按上方原因修复后重跑。',
                ['ids' => '#' . \implode(', #', $failedThemes)]
            ));
        }
    }
}
