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

                $out = $this->system->exec("cp -rf $origin_view_dir $pub_view_dir");
                if ($out) {
                    $this->printer->warning(implode('', $out['output']));
                }
            }
        }
        $this->printer->success('静态文件部署完毕！');
    }

    public function tip(): string
    {
        return '静态资源同步更新。';
    }
}
