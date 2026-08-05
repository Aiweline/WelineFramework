<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Console\Setup\Di;

use Weline\Framework\Compilation\FrameworkCompiler;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Compile extends \Weline\Framework\Console\CommandAbstract
{
    public function __construct(
        private readonly FrameworkCompiler $frameworkCompiler,
        private readonly Printing $printing,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $result = $this->frameworkCompiler->compile(
            BP . 'app' . DS . 'code' . DS . 'Weline',
            BP . 'generated' . DS . 'framework',
        );

        # 分配编译事件
        /**@var EventsManager $evenManager */
        $evenManager = ObjectManager::getInstance(EventsManager::class);
        $evenManager->dispatch('Weline_Framework_Console::compile');

        $this->printing->success(__(
            'DI 与框架编译完成：%{1} 个模块，%{2} 个 QueryProvider，%{3} 个延迟 Provider。',
            [
                count($result['modules']['modules'] ?? []),
                count($result['query_providers']['providers'] ?? []),
                count($result['query_providers']['deferred'] ?? []),
            ],
        ));
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
