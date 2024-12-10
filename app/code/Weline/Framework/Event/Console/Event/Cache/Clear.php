<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Console\Event\Cache;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Event\Cache\EventCache;
use Weline\Framework\Output\Cli\Printing;

class Clear implements CommandInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * @var CacheInterface
     */
    private CacheInterface $eventCache;

    public function __construct(
        EventCache $eventCache,
        Printing   $printing
    )
    {
        $this->printing   = $printing;
        $this->eventCache = $eventCache->create();
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
        return __('清除系统事件缓存！');
    }
}
