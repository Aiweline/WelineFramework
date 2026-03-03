<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Console\Event;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Cache implements CommandInterface
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

    public function execute(array $args = [], array $data = [])
    {
        if (!isset($args[1])) {
            $this->printing->error(__('错误的缓存处理参数！-c：清除缓存，-f：刷新缓存！'));
            exit(0);
        }
        $argv = $args[1];
        switch ($argv) {
            case '-c':
            case '-f':
                $this->eventCache->clear();
                $this->printing->success(__('缓存已清除！'));
                break;
            default:
                $this->printing->error(__('未知的参数：%{1}', [$argv]));
        }
    }

    public function tip(): string
    {
        return '事件缓存管理！-c：清除缓存；-f：刷新缓存。';
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
