<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 系统升级后观察者
 * 监听 Framework_Setup::upgrade_after 事件，清除主题缓存并刷新主题信息
 */
class SetupUpgradeAfter implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            
            // 清除所有主题缓存
            $theme->_cache->delete('theme');
            
            // 清除所有主题的父主题缓存
            $themes = $theme->select()->fetch()->getItems();
            foreach ($themes as $themeItem) {
                if (isset($themeItem['id']) && $themeItem['id']) {
                    $theme->_cache->delete('theme_parent_' . $themeItem['id']);
                }
            }
            
        } catch (\Exception $e) {
            // 记录错误但不中断系统更新流程
            error_log("主题升级后清理失败: " . $e->getMessage());
        }
    }
}

