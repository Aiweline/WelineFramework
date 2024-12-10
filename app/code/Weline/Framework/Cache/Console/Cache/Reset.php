<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\App\System;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Output\Cli\Printing;

class Reset implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Scanner
     */
    private Scanner $scanner;

    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * @var System
     */
    private System $system;

    /**
     * Flush 初始函数...
     *
     * @param Scanner  $scanner
     * @param Printing $printing
     * @param System   $system
     */
    public function __construct(
        Scanner  $scanner,
        Printing $printing,
        System   $system
    )
    {
        $this->scanner  = $scanner;
        $this->printing = $printing;
        $this->system   = $system;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // FIXME 准备缓存拓展,允许支持第三方自定义缓存插件
//        /**@var EventsManager $eventsManager */
//        $eventsManager = ObjectManager::getInstance(EventsManager::class);
//        $eventsManager->dispatch('WelineFrame');
        $system_cache_dir = BP . 'var' . DS . 'cache';
        $var_dirs         = $this->scanner->scanDir($system_cache_dir);
        foreach ($var_dirs as $var_dir) {
            $cache_dir = $system_cache_dir . DS . $var_dir;
            $this->system->exec('rm -rf ' . $cache_dir);
            $this->printing->note($cache_dir);
        }
        $this->printing->success(__('缓存已清理！'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '重置缓存。（删除缓存：手动删除缓存file缓存请删除./var/cache）';
    }
}
