<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Console\Plugin\Cache;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Plugin\Cache\PluginCache;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var CachePoolInterface|null 插件缓存池，Di 编译阶段可能尚未就绪而为 null
     */
    private ?CachePoolInterface $pluginCache;

    /**
     * @var Printing
     */
    private Printing $printing;

    public function __construct(
        ?PluginCache $pluginCache,
        Printing     $printing
    )
    {
        $this->pluginCache = $pluginCache !== null ? $pluginCache->create() : null;
        $this->printing    = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        if ($this->pluginCache !== null) {
            $this->pluginCache->clear();
            $this->printing->success(__('拦截器缓存清理成功！'), '系统');
        } else {
            $this->printing->note(__('插件缓存未就绪，跳过清理。'));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('插件缓存清理！');
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
