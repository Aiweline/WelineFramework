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
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Deploy\StaticPublishExclusion;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Scan;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService;
use Weline\Theme\Service\ThemeResourceGateway;
use Weline\Theme\Service\ThemeStaticNamespaceService;

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
        [$theme_name, $modules, $allVersions, $scopeFilter] = self::parseArguments($args);
        $themes = $this->resolveThemesToUpgrade($theme_name);
        if ($themes === []) {
            throw new ConsoleException(__('未找到范围已绑定主题，请用 -t/--theme 指定主题名。'));
        }

        foreach ($themes as $theme) {
            $this->upgradeOneTheme($theme, $modules);
        }

        // C-RP-07：-t 指定主题；无 -t → 仅范围已绑定主题；默认正式+草稿；--all 全历史版本
        $solidifyOptions = [];
        if ($allVersions) {
            $solidifyOptions['all_versions'] = true;
        }
        if ($scopeFilter !== '') {
            $solidifyOptions['scope'] = $scopeFilter;
        }
        $this->printing->warning(__('开始 purge 旧布局固化物并重固（generated/theme-layout-entities）…'));
        if ($allVersions) {
            $this->printing->note(__('版本范围：全部历史版本（--all）'));
        } else {
            $this->printing->note(__('版本范围：当前正式版 + 当前草稿'));
        }
        if ($scopeFilter !== '') {
            $this->printing->note(__('范围过滤：') . $scopeFilter);
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
        // 缺文件直接 404，不会走 ThemeResourceGateway 延迟补发。须双写到 Theme 模块路径。
        $this->printing->warning(__('开始将设计主题 area 资源双写到 Weline/Theme/view/theme URL 路径...'));
        $mirrored = $this->publishDesignAreaAssetsIntoThemeModuleNamespace($theme, $publicThemePath, $modules);
        $this->printing->success(
            __('设计 area→Theme 模块路径：') . $mirrored . __(' 个文件')
        );
    }

    /**
     * @return array{0:string,1:list<string>,2:bool,3:string} themeName, modules, allVersions, scopeFilter
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
            if ($argument === '-a' || $argument === '--all') {
                $allVersions = true;
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
        if (!$allVersions) {
            $allFlag = $args['a'] ?? $args['all'] ?? null;
            if ($allFlag === true || $allFlag === 1 || $allFlag === '1' || $allFlag === '') {
                $allVersions = true;
            }
        }

        return [$themeName, array_values(array_unique($modules)), $allVersions, $scopeFilter];
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
    }

    /**
     * Mirror design-theme `{area}/**` runtime assets into the URL namespace used by
     * `<theme:*>` / `Weline_Theme::theme/{area}/...` tags:
     * `/static/{ns}/Weline/Theme/view/theme/{area}/...`.
     *
     * Design overlay copy already writes `/static/{ns}/{area}/...` (relative to
     * theme root). That path is NOT what theme tags emit, so PROD WLS fastpath
     * 404s unless this second publish runs (or a request-time gateway publish
     * happens — which NG/WLS missing-file fastpath does not).
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
        return __('更新主题静态资源（设计覆盖 + 模块 view/theme；省略 -t 时仅范围已绑定主题；默认正式+草稿）');
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
                '-a, --all' => '部署并编译该主题（或全部站绑主题）下全部历史版本；默认仅当前正式版+草稿',
            ],
            [
                'module...' => '可选：只处理设计主题下指定模块目录（如 Weline_Theme）',
            ],
            [
                '范围已绑定主题（正式+草稿）' => 'php bin/w theme:upgrade',
                '指定主题' => 'php bin/w theme:upgrade -t daocharms',
                '指定范围' => 'php bin/w theme:upgrade -t hanfu --scope default.default.default',
                '全部历史版本' => 'php bin/w theme:upgrade --all -t hanfu',
                '指定主题+模块过滤' => 'php bin/w theme:upgrade -t daocharms Weline_Theme',
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
                if (str_contains($file_path, DS . 'templates' . DS)
                    || str_ends_with($file_path, DS . 'register.php')
                    || self::isExcludedPublishPath($theme->getPath(), $file_path)
                ) {
                    continue;
                }

                $destinationDirectory = self::buildDestinationDirectory(
                    $theme->getPath(),
                    $file_path,
                    $publicThemePath,
                    APP_STATIC_PATH,
                );
                if ($destinationDirectory === null) {
                    throw new ConsoleException(__('主题文件不在允许的主题目录内：') . $file_path);
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
}
