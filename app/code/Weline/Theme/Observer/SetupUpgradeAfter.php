<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 系统升级后观察者
 * 监听 Weline_Framework_Setup::upgrade_after 事件：
 *  - 清除主题缓存
 *  - 自动扫描并注册 app/design 下的主题（等价于对每个主题执行一次 register.php）
 */
class SetupUpgradeAfter implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 检查是否是部分更新模式
        $eventData = $event->getData();
        $isPartialUpgrade = $eventData['is_partial_upgrade'] ?? false;
        $routeOnly = $eventData['route_only'] ?? false;
        $modelOnly = $eventData['model_only'] ?? false;
        
        // 如果是部分更新模式，跳过主题相关操作（主题注册和缓存清理应该在完整升级时执行）
        if ($isPartialUpgrade) {
            // 部分更新模式，跳过主题相关操作
            return;
        }
        
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);

            // 1. 清除所有主题缓存
            $theme->_cache->delete('theme');

            // 清除所有主题的父主题缓存
            $themes = $theme->select()->fetch()->getItems();
            foreach ($themes as $themeItem) {
                if (isset($themeItem['id']) && $themeItem['id']) {
                    $theme->_cache->delete('theme_parent_' . $themeItem['id']);
                }
            }

            // 2. 自动扫描并注册 app/design 下的主题
            $this->autoRegisterThemes();
        } catch (\Exception $e) {
            // 记录错误但不中断系统更新流程
            Env::log_error('theme', "主题升级后清理失败: " . $e->getMessage());
        }
    }

    /**
     * 扫描 app/design 下的主题并执行各自的 register.php
     * 
     * - 等价于对每个主题执行一次 Register::register(...)
     * - Installer::register 内部已经做了“已安装则更新 / 未安装则新装”的判断，因此可以安全重复调用
     * - 所有异常都会被捕获记录，不影响主升级流程
     */
    private function autoRegisterThemes(): void
    {
        try {
            $designPath = Env::path_CODE_DESIGN;
            if (empty($designPath) || !is_dir($designPath)) {
                return;
            }

            // 目录结构示例：
            // app/design/Vendor/ThemeName/register.php
            $dirIterator = new \RecursiveDirectoryIterator($designPath, \RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iterator as $file) {
                // 以 app/design 为根：
                // depth 0: app/design/Vendor
                // depth 1: app/design/Vendor/ThemeName ← 主题目录
                if ($file->isDir() && $iterator->getDepth() === 1) {
                    $themePath = $file->getPathname();
                    $registerFile = $themePath . DS . 'register.php';

                    if (is_file($registerFile)) {
                        try {
                            // register.php 内部会调用 Register::register，从而驱动 Installer 注册/更新主题
                            require_once $registerFile;
                        } catch (\Throwable $e) {
                            Env::log_error('theme', '自动注册主题失败（' . $registerFile . '）：' . $e->getMessage());
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // 扫描整体失败也只记录日志，不打断升级
            Env::log_error('theme', '自动扫描主题目录失败：' . $e->getMessage());
        }
    }
}

