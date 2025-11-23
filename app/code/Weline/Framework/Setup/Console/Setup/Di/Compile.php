<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Console\Setup\Di;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class Compile extends \Weline\Framework\Console\CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 分配编译事件
        /**@var EventsManager $evenManager */
        $evenManager = ObjectManager::getInstance(EventsManager::class);
        $evenManager->dispatch('Weline_Framework_Console::compile');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'DI依赖编译';
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
