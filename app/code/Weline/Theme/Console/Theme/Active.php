<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Exception\Core;

class Active extends AbstractConsole
{
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
                if ($theme->isActive()) {
                    $this->printing->error(__('无需再次激活'));
                } else {
                    $this->printing->note(__('正在激活...'));
                }
                if (!$theme->isActive()) {
                    $theme->setIsActive(true);
                    try {
                        $theme->save();
                        $this->printing->success(__('已成功激活主题：') . $theme_name);
                    } catch (\ReflectionException $e) {
                        throw $e;
                    } catch (Core $e) {
                        throw $e;
                    }
                }
            } else {
                $this->printing->error(__('当前主题未安装：激活失败！'), __('主题'));
            }
        } else {
            $theme = $this->welineTheme->getActiveTheme();
            if ($theme) {
                $this->printing->success(__('当前主题：%1', [$theme->getName()]));
                $this->printing->success(__('路径：%1', [$theme->getPath()]));
            } else {
                $this->printing->warning(__('主题'), __('系统未安装任何主题！'));
            }
        }
    }

    public function tip(): string
    {
        return '查看当前主题或者激活特定主题';
    }
}
