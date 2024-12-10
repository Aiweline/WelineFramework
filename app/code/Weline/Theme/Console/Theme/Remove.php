<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/13
 * 时间：17:09
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Manager\ObjectManager;

class Remove extends AbstractConsole
{
    private System $system;

    public function __init()
    {
        $this->system = ObjectManager::getInstance(System::class);
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $theme_name = isset($args[1]) ? $args[1] : '';
        if ($theme_name) {
            $theme = $this->welineTheme->load('name', $theme_name);
            if ($theme->getId()) {
                $status = $theme->isActive() ? __('已激活') : __('未激活');
                $this->printing->note(__('当前主题:') . $theme_name);
                $this->printing->note(__('安装状态:已安装！'));
                $this->printing->note(__('激活状态:') . $status);
                $this->printing->setup(__('正在卸载主题...'));
//                $theme->delete();
                // 压缩主题包
                $this->printing->note(__('正在压缩备份文件...'));
                /**@var \Weline\Framework\System\File\Compress $compress */
                $compress = ObjectManager::getInstance(\Weline\Framework\System\File\Compress::class);
                $res      = $compress->compression(Env::path_THEME_DESIGN_DIR . $theme->getPath(), Env::path_THEME_DESIGN_DIR, Env::path_THEME_DESIGN_DIR);
                p($res);
            } else {
                $this->printing->error(__('当前主题未安装：卸载失败！'), __('主题'));
            }
        } else {
            $this->printing->error(__('请指定要删除的主题，示例：php bin/m theme:remove Weline_Default'), __('主题'));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('卸载主题');
    }
}
