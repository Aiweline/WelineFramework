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
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Scan;
use Weline\Theme\Model\WelineTheme;
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

    public function __construct(
        WelineTheme $welineTheme,
        Printing $printing,
        System $system,
        Scan $scan,
        ThemeStaticNamespaceService $themeStaticNamespaceService,
        ThemeResourceGateway $themeResourceGateway,
    ) {
        $this->welineTheme = $welineTheme;
        $this->scan = $scan;
        $this->system = $system;
        $this->printing = $printing;
        $this->themeStaticNamespaceService = $themeStaticNamespaceService;
        $this->themeResourceGateway = $themeResourceGateway;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        [$theme_name, $modules] = self::parseArguments($args);

        if ($theme_name !== '') {
            $theme = $this->welineTheme->clear()->load(WelineTheme::schema_fields_NAME, $theme_name);
            if (!$theme->getId()) {
                throw new ConsoleException(__('主题不存在：') . $theme_name);
            }
        } else {
            $theme = $this->welineTheme->getActiveTheme();
            if (!$theme || !$theme->getId()) {
                throw new ConsoleException(__('未找到激活主题，请用 -t/--theme 指定主题名。'));
            }
        }

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
    }

    /**
     * @return array{0:string,1:list<string>}
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

        return [$themeName, array_values(array_unique($modules))];
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
        return __('更新主题静态资源（设计覆盖 + 模块 view/theme 继承发布到 /static/{主题}/...）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:upgrade',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-t, --theme <name>' => '指定主题名（如 daocharms / hanfu）；省略则用当前激活主题',
            ],
            [
                'module...' => '可选：只处理设计主题下指定模块目录（如 Weline_Theme）',
            ],
            [
                '指定主题全量发布' => 'php bin/w theme:upgrade -t daocharms',
                '激活主题全量发布' => 'php bin/w theme:upgrade',
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
                if (!str_contains($file_path, DS . 'templates' . DS) && !str_ends_with($file_path, DS . 'register.php')) {
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
        }

        return $themes_files_data;
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
