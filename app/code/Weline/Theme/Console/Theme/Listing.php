<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Theme;

class Listing extends AbstractConsole
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 读取主题列表
        $themes = $this->welineTheme->select()->fetch();
        foreach ($themes as $theme) {
            $this->printing->note($theme['name']);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('查看主题列表');
    }
}
