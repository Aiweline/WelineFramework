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

class Upgrade implements \Weline\Framework\Console\CommandInterface
{
    private WelineTheme $welineTheme;
    private Scan $scan;
    private System $system;
    private Printing $printing;

    public function __construct(
        WelineTheme $welineTheme,
        Printing    $printing,
        System      $system,
        Scan        $scan
    )
    {
        $this->welineTheme = $welineTheme;
        $this->scan        = $scan;
        $this->system      = $system;
        $this->printing    = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);

        $theme_name = '';
        # 分离参数
        foreach ($args as $key => $arg) {
            switch ($arg) {
                case '-t':
                    if (!$args[$key + 1]) {
                        throw new ConsoleException(__('设置了 -t 参数，但却没有-t参数值！'));
                    }
                    $theme_name = $args[$key + 1];
                    unset($args[$key]);
                    unset($args[$key + 1]);
                    break;
            }
        }

        // 读取激活的模块
        if ($theme_name) {
            $theme = $this->welineTheme->load(WelineTheme::filed_NAME, $theme_name);
        } else {
            $theme = $this->welineTheme->getActiveTheme();
        }

        # 如果命令指定了特定模块 纳入特定模块的迁移数组
        $this->printing->warning(__('收集') . $theme->getName() . __('主题文件...'));
        $modules           = $args;
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
            $this->system->exec("cp -rf {$origin_themes_file} {$themes_files}");
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('更新主题文件！');
    }

    public function fetchThemeFiles($theme, $path): array
    {
        $themes_files_data  = [];
        $theme_extend_files = $this->scan->scanDirTree($path);
        foreach ($theme_extend_files as $theme_extend_file) {
            /**@var \Weline\Framework\System\File\Data\File $file */
            foreach ($theme_extend_file as $file) {
                $file_path = $file->getOrigin();
                if (!strpos($file_path, 'templates') && !strpos($file_path, 'register.php')) {
                    $new_file_path                 = str_replace($theme->getPath(), APP_STATIC_PATH . $theme->getOriginPath() . DS, $file_path);
                    $themes_files_data[$file_path] = dirname($new_file_path) . DS;
                }
            }
        }
        return $themes_files_data;
    }
}
