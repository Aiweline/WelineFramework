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
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\View\Data\DataInterface;

class Upgrade extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;

    public function __construct(
        System     $system
    ) {
        $this->system  = $system;
    }

    public function execute(array $args = [], array $data = [])
    {
        // 活跃模块
        $modules = Env::getInstance()->getActiveModules();
        // 注册模块
        foreach ($modules as $module) {
            $name                   = $module['name'];
            $module_view_static_dir = $module['base_path'] . DataInterface::dir . DS . DataInterface::dir_type_STATICS;
            $module_view_dir        = (DEV?$module['path']:str_replace('_', DS, $module['name']).DS) . DataInterface::dir;
            // windows的文件复制兼容
            if (IS_WIN) {
                $module_view_dir .= DS.DataInterface::dir_type_STATICS . DS;
            }
            $origin_view_dir = $module_view_static_dir;
            if (is_dir($origin_view_dir)) {
                $this->printer->note($name . '...');
                // 主题配置
                $theme = Env::getInstance()->getConfig('theme', Env::default_theme_DATA);

                # 主题目录
                $pub_view_dir =  PUB . 'static' . DS . $theme['path'] . DS . $module_view_dir;

                if (!is_dir($pub_view_dir)) {
                    mkdir($pub_view_dir, 0775, true);
                }

                // 使用跨平台的文件复制方法
                if (IS_WIN) {
                    // Windows系统：使用PHP递归复制
                    $this->recursiveCopy($origin_view_dir, $pub_view_dir);
                } else {
                    // Linux/Unix系统：使用系统命令
                    $out = $this->system->exec("cp -rf $origin_view_dir $pub_view_dir");
                    if ($out) {
                        $this->printer->warning(implode('', $out['output']));
                    }
                }
            }
        }
        $this->printer->success('静态文件部署完毕！');
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
