<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Flush implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Scanner
     */
    private Scanner $scanner;

    /**
     * @var Printing
     */
    private Printing $printing;

    public function __construct(
        Scanner  $scanner,
        Printing $printing
    )
    {
        $this->scanner  = $scanner;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $caches = $this->scanner->getCaches();
        foreach ($caches as $form => $cache) {
            switch ($form) {
                case 'app_caches':
                    $this->printing->note(__('模块缓存刷新中...'));
                    foreach ($cache as $app_cache) {
                        $this->printing->printing(__($app_cache['class'] . '...'));
                        ObjectManager::getInstance($app_cache['class'] . 'Factory')->flush();
                    }

                    break;
                case 'framework_caches':
                    $this->printing->note(__('框架缓存刷新中...'));
                    foreach ($cache as $framework_cache) {
                        $this->printing->printing(__($framework_cache['class'] . '...'));
                        ObjectManager::getInstance($framework_cache['class'] . 'Factory')->flush();
                    }

                    break;
                default:
                    $this->printing->error(__('没有任何类型的缓存需要刷新！'));
            }
        }
        $this->printing->success(__('缓存已刷新！'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '缓存刷新。';
    }
}
