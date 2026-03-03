<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Console\Event\Cache;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Flush implements CommandInterface
{
    private Printing $printing;
    private CachePoolInterface $eventCache;

    public function __construct(
        CacheManager $cacheManager,
        Printing     $printing
    )
    {
        $this->printing   = $printing;
        $this->eventCache = $cacheManager->pool('event');
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->eventCache->clear();

        return $this->printing->success(__('清理完毕！'), '系统事件缓存');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('刷新系统事件缓存！');
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
