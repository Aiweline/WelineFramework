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
        $themes = $this->welineTheme->select()->fetch()->getItems();
        if (empty($themes)) {
            $this->printing->warning(__('系统未安装任何主题！'));
            return;
        }
        foreach ($themes as $theme) {
            $name = is_object($theme) ? $theme->getName() : ($theme['name'] ?? 'Unknown');
            $id = is_object($theme) ? $theme->getId() : ($theme['theme_id'] ?? 0);
            $isActive = is_object($theme) ? $theme->getIsActive() : ($theme['is_active'] ?? false);
            $activeStr = $isActive ? __('【已激活】') : '';
            $this->printing->note("ID: {$id} - {$name} {$activeStr}");
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('查看主题列表');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:listing',
            '查看系统中已安装的所有主题',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '查看主题列表' => 'php bin/w theme:listing',
            ]
        );
    }
}
