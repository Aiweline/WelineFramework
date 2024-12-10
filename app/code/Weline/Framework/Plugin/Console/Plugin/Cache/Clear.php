<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Console\Plugin\Cache;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Plugin\Cache\PluginCache;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var CacheInterface
     */
    private CacheInterface $pluginCache;

    /**
     * @var Printing
     */
    private Printing $printing;

    public function __construct(
        PluginCache $pluginCache,
        Printing    $printing
    )
    {
        $this->pluginCache = $pluginCache->create();
        $this->printing    = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->pluginCache->clear();
        $this->printing->success(__('拦截器缓存清理成功！'), '系统');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('插件缓存清理！');
    }
}
