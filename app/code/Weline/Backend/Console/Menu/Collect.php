<?php

namespace Weline\Backend\Console\Menu;

use Weline\Backend\Observer\UpgradeMenu;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Collect implements CommandInterface
{
    function __construct(
        private UpgradeMenu $upgradeMenu,
        private Printing $printing
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 收集菜单
        $this->printing->note(__('开始收集菜单...'));
        $this->upgradeMenu->collectMenus();
        $this->printing->success(__('菜单收集完成！'));
        
        // 清理事件缓存（菜单更新可能触发事件，需要清理事件缓存）
        $this->printing->note(__('清理事件缓存...'));
        try {
            /** @var \Weline\Framework\Event\Console\Event\Cache\Clear $eventCacheClear */
            $eventCacheClear = ObjectManager::getInstance(\Weline\Framework\Event\Console\Event\Cache\Clear::class);
            $eventCacheClear->execute();
        } catch (\Throwable $e) {
            $this->printing->warning(__('事件缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        // 编译插件/DI（确保依赖注入容器更新）
        $this->printing->note(__('编译插件/DI...'));
        try {
            /** @var \Weline\Framework\Plugin\Console\Plugin\Di\Compile $diCompile */
            $diCompile = ObjectManager::getInstance(\Weline\Framework\Plugin\Console\Plugin\Di\Compile::class);
            $diCompile->execute();
        } catch (\Throwable $e) {
            $this->printing->warning(__('DI编译失败：%{1}', [$e->getMessage()]));
        }
        
        // 清理缓存
        $this->printing->note(__('清理系统缓存...'));
        try {
            /** @var \Weline\Framework\Cache\Console\Cache\Clear $cacheClear */
            $cacheClear = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Clear::class);
            $cacheClear->execute(['-f']); // 强制清理
            $this->printing->success(__('缓存清理完成！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        $this->printing->success(__('菜单收集和系统更新完成！菜单已生效。'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '收集菜单';
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