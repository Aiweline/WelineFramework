<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Scan;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeStaticNamespaceService;

class Upgrade implements \Weline\Framework\Console\CommandInterface
{
    private WelineTheme $welineTheme;
    private Scan $scan;
    private System $system;
    private Printing $printing;
    private ThemeStaticNamespaceService $themeStaticNamespaceService;

    public function __construct(
        WelineTheme $welineTheme,
        Printing    $printing,
        System      $system,
        Scan        $scan,
        ThemeStaticNamespaceService $themeStaticNamespaceService,
    )
    {
        $this->welineTheme = $welineTheme;
        $this->scan        = $scan;
        $this->system      = $system;
        $this->printing    = $printing;
        $this->themeStaticNamespaceService = $themeStaticNamespaceService;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        [$theme_name, $modules] = self::parseArguments($args);

        // 读取激活的模块
        if ($theme_name) {
            $theme = $this->welineTheme->load(WelineTheme::schema_fields_NAME, $theme_name);
        } else {
            $theme = $this->welineTheme->getActiveTheme();
        }

        # 如果命令指定了特定模块 纳入特定模块的迁移数组
        $this->printing->warning(__('收集') . $theme->getName() . __('主题文件...'));
        $themes_files_data = [];
        if ($modules) {
            foreach ($modules as $module) {
                $this->printing->note($module);
                $module_path       = str_replace('_', DS, $module);
                $themes_files_data = array_merge($themes_files_data, $this->fetchThemeFiles($theme, $theme->getPath() . $module_path));
            }
        } else {
            # 未指定特定模块 全部纳入迁移数组
            $themes_files_data = $this->fetchThemeFiles($theme, $theme->getPath());
        }
        # 开始搬迁文件
        $this->printing->warning(__('开始搬迁文件...'));
        foreach ($themes_files_data as $origin_themes_file => $themes_files) {
            $this->printing->note($origin_themes_file . ' => ') . $this->printing->success($themes_files);
            $this->copyThemeFile($origin_themes_file, $themes_files);
        }
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
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('更新主题文件！');
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }

    public function fetchThemeFiles($theme, $path): array
    {
        $themes_files_data  = [];
        $theme_extend_files = $this->scan->scanDirTree($path);
        $publicThemePath = $this->themeStaticNamespaceService->resolvePublicThemePath($theme);
        if ($publicThemePath === '') {
            throw new ConsoleException(__('无法解析主题静态资源命名空间。'));
        }
        foreach ($theme_extend_files as $theme_extend_file) {
            /**@var \Weline\Framework\System\File\Data\File $file */
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
