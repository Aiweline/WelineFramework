<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Deploy;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Deploy\FlatStaticRuntimeFilesProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;

class Upgrade extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;

    public function __construct(
        System $system,
        private readonly ServiceProviderRegistry $providerRegistry,
    ) {
        $this->system  = $system;
    }

    public function execute(array $args = [], array $data = [])
    {
        $modules    = Env::getInstance()->getActiveModules();
        $theme      = Env::getInstance()->getConfig('theme', Env::default_theme_DATA);
        $staticRoot = PUB . 'static';

        if (!is_dir($staticRoot) && !mkdir($staticRoot, 0775, true) && !is_dir($staticRoot)) {
            throw new \RuntimeException('Unable to create static deployment directory: ' . $staticRoot);
        }

        $staticOwner = @fileowner($staticRoot);
        $staticGroup = @filegroup($staticRoot);
        $applyPermissions = static function (string $path) use ($staticOwner, $staticGroup): void {
            if (is_link($path) || (!file_exists($path) && !is_dir($path))) {
                return;
            }
            @chmod($path, is_dir($path) ? 0775 : 0664);
            if ($staticOwner !== false && function_exists('chown')) {
                @chown($path, $staticOwner);
            }
            if ($staticGroup !== false && function_exists('chgrp')) {
                @chgrp($path, $staticGroup);
            }
        };
        $normalizePermissions = static function (string $path) use ($applyPermissions): void {
            if (!is_dir($path)) {
                $applyPermissions($path);
                return;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                if (!$item->isLink()) {
                    $applyPermissions($item->getPathname());
                }
            }
            $applyPermissions($path);
        };

        $themeAssetExtensions = [
            'css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif',
            'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt',
            'xml', 'webmanifest', 'wasm', 'mp4', 'webm', 'mp3', 'ogg', 'wav',
        ];
        $publishThemeAssets = static function (string $source, string $target) use ($themeAssetExtensions): int {
            if (!is_dir($source)) {
                return 0;
            }

            $published = 0;
            $iterator  = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || !$item->isFile()) {
                    continue;
                }
                $extension = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
                if (!in_array($extension, $themeAssetExtensions, true)) {
                    continue;
                }
                $sourcePath = $item->getPathname();
                $relative   = ltrim(substr($sourcePath, strlen($source)), '/\\');
                $targetPath = $target . DS . str_replace(['/', '\\'], DS, $relative);
                $targetDir  = dirname($targetPath);
                if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                    throw new \RuntimeException('Unable to create theme asset directory: ' . $targetDir);
                }
                if (!@copy($sourcePath, $targetPath)) {
                    throw new \RuntimeException('Unable to publish theme asset: ' . $sourcePath);
                }
                $published++;
            }

            return $published;
        };

        foreach ($modules as $module) {
            $name          = $module['name'];
            $moduleViewDir = (DEV ? $module['path'] : str_replace('_', DS, $name) . DS) . DataInterface::dir;
            $staticSource  = $module['base_path'] . DataInterface::dir . DS . DataInterface::dir_type_STATICS;
            $themeSource   = $module['base_path'] . DataInterface::dir . DS . 'theme';

            if (is_dir($staticSource) || is_dir($themeSource)) {
                $this->printer->note($name . '...');
            }

            if (is_dir($staticSource)) {
                $staticTarget = $staticRoot . DS . $theme['path'] . DS . $moduleViewDir
                    . DS . DataInterface::dir_type_STATICS;
                if (!is_dir($staticTarget) && !mkdir($staticTarget, 0775, true) && !is_dir($staticTarget)) {
                    throw new \RuntimeException('Unable to create module static directory: ' . $staticTarget);
                }
                $this->recursiveCopy($staticSource, $staticTarget);
            }

            if (is_dir($themeSource)) {
                $themeTarget = $staticRoot . DS . $theme['path'] . DS . $moduleViewDir . DS . 'theme';
                $publishThemeAssets($themeSource, $themeTarget);
            }
        }

        $this->publishFlatStaticRuntimeFiles($modules);
        $normalizePermissions($staticRoot);
        $this->printer->success('静态文件部署完毕！');
    }

    private function publishFlatStaticRuntimeFiles(array $modules): void
    {
        foreach ($this->flatStaticRuntimeFiles() as $moduleName => $relativeFiles) {
            if (!isset($modules[$moduleName]) || empty($modules[$moduleName]['base_path'])) {
                continue;
            }

            foreach ($relativeFiles as $relativeFile) {
                $this->copyFlatStaticRuntimeFile($moduleName, (string)$modules[$moduleName]['base_path'], $relativeFile);
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function flatStaticRuntimeFiles(): array
    {
        $files = [];
        foreach ($this->providerRegistry->implementationsWithPrefix('deploy.flat_static.') as $implementation) {
            try {
                $provider = ObjectManager::getInstance($implementation);
            } catch (\Throwable) {
                continue;
            }
            if (!$provider instanceof FlatStaticRuntimeFilesProviderInterface) {
                continue;
            }

            $moduleName = \trim($provider->moduleName());
            if ($moduleName === '') {
                continue;
            }
            foreach ($provider->relativeFiles() as $relativeFile) {
                $relativeFile = \trim((string)$relativeFile);
                if ($relativeFile !== '') {
                    $files[$moduleName][$relativeFile] = $relativeFile;
                }
            }
        }

        return \array_map('array_values', $files);
    }

    private function copyFlatStaticRuntimeFile(string $moduleName, string $moduleBasePath, string $relativeFile): void
    {
        $sourceFile = rtrim($moduleBasePath, '\\/') . DS
            . DataInterface::dir . DS
            . DataInterface::dir_type_STATICS . DS
            . str_replace(['/', '\\'], DS, $relativeFile);
        if (!is_file($sourceFile)) {
            return;
        }

        $moduleParts = explode('_', $moduleName, 2);
        if (count($moduleParts) !== 2 || $moduleParts[0] === '' || $moduleParts[1] === '') {
            return;
        }

        $targetFile = PUB . 'static' . DS
            . $moduleParts[0] . DS
            . $moduleParts[1] . DS
            . str_replace(['/', '\\'], DS, $relativeFile);
        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return;
        }

        copy($sourceFile, $targetFile);
    }

    /**
     * 递归复制目录（跨平台兼容）
     *
     * @param string $source 源目录
     * @param string $dest 目标目录
     * @return void
     */
    private function recursiveCopy(string $source, string $dest): void
    {
        // 确保源目录存在
        if (!is_dir($source)) {
            return;
        }

        // 创建目标目录的父目录
        $parent_dest = dirname($dest);
        if (!is_dir($parent_dest)) {
            mkdir($parent_dest, 0775, true);
        }

        // 遍历源目录
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            // 计算相对路径
            $relativePath = substr($item->getPathname(), strlen($source));
            $destPath = $dest . $relativePath;

            if ($item->isDir()) {
                // 创建目录
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0775, true);
                }
            } else {
                // 复制文件
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0775, true);
                }
                copy($item->getPathname(), $destPath);
            }
        }
    }

    public function tip(): string
    {
        return '静态资源同步更新。';
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
}
