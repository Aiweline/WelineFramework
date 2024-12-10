<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Console\Event;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Event\Cache\EventCache;
use Weline\Framework\Output\Cli\Printing;

class Cache implements CommandInterface
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
        Printing   $printing,
        EventCache $eventCache
    )
    {
        $this->printing   = $printing;
        $this->eventCache = $eventCache->create();
    }

    public function execute(array $args = [], array $data = [])
    {
        if (!isset($args[1])) {
            $this->printing->error(__('错误的缓存处理参数！-c：清除缓存，-f：刷新缓存！'));
            exit(0);
        }
        $argv = isset($args[1]);
        switch ($argv) {
            case '-c':
                $this->eventCache->clear();
                $this->printing->success(__('缓存已清除！'));

                break;
            case '-f':
                $this->eventCache->flush();
                $this->printing->success(__('缓存已刷新！'));

                break;
            default:
                $this->printing->error(__('未知的参数：%1', [$argv]));
        }
    }

    public function tip(): string
    {
        return '事件缓存管理！-c：清除缓存；-f：刷新缓存。';
    }
}
