<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Template;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\TemplateCacheManager;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 编译产物（模块 view/tpl、generated/complicate/<Vendor>/<Module>/view）不归缓存管理：
        // 本命令只失效模板缓存袋与 taglib 注册表，不删除任何编译产物。
        $silent = $data['silent'] ?? false;

        if (!$silent) {
            $this->printing->note(__('开始清理模板缓存：'));
        }

        $this->clearViewCaches();

        if (!$silent) {
            $this->printing->note(__('模板缓存清理完成（编译产物未删除）。'));
        }
    }

    /**
     * 清理视图/模板增强缓存（taglib 注册表 + 模板缓存袋），不触碰编译产物。
     */
    private function clearViewCaches(): void
    {
        try {
            $taglib = ObjectManager::getInstance(Taglib::class);
            if ($taglib instanceof Taglib) {
                $taglib->clearCache();
            }
        } catch (\Throwable) {
            // View may be unavailable in stripped CLI contexts.
        }

        try {
            TemplateCacheManager::getInstance()->clearAll();
        } catch (\Throwable) {
            // Enhanced template cache clear is best-effort during template cache clear.
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '清理模板缓存（不删除编译产物）！';
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
