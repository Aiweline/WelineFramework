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
        
        // 清理全部缓存（优先执行，确保菜单变更后立即生效）
        $this->printing->note(__('清理全部缓存...'));
        try {
            /** @var \Weline\Framework\Cache\Console\Cache\Clear $cacheClear */
            $cacheClear = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Clear::class);
            $cacheClear->execute(['-f']); // 强制清理所有缓存
            $this->printing->success(__('全部缓存清理完成！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        // 清理模板缓存（菜单变更会影响模板渲染）
        $this->printing->note(__('清理模板缓存...'));
        try {
            /** @var \Weline\Framework\Cache\Console\Template\Clear $templateCacheClear */
            $templateCacheClear = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Template\Clear::class);
            $templateCacheClear->execute();
            $this->printing->success(__('模板缓存清理完成！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('模板缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        // 强制重新加载模块列表（清除内存缓存）
        $this->printing->note(__('刷新模块列表缓存...'));
        try {
            // 使用单例方法获取Env实例
            $env = \Weline\Framework\App\Env::getInstance();
            // 强制重新获取模块列表，清除内存缓存
            $env->getModuleList(true);
            $env->getActiveModules(true);
            $this->printing->success(__('模块列表缓存已刷新！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('模块列表缓存刷新失败：%{1}', [$e->getMessage()]));
        }
        
        // 清理菜单相关缓存
        $this->printing->note(__('清理菜单相关缓存...'));
        try {
            // 清理菜单URL验证器缓存
            if (class_exists(\Weline\Admin\Helper\MenuUrlValidator::class)) {
                \Weline\Admin\Helper\MenuUrlValidator::clearCache();
            }
            
            $this->printing->success(__('菜单相关缓存已清理！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('菜单缓存清理失败：%{1}', [$e->getMessage()]));
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