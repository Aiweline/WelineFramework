<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 模块升级后事件监听器
 * 监听模块升级事件，自动收集部件并生成注册表
 */
class ModuleUpgradeAfter implements ObserverInterface
{
    /**
     * 处理模块升级后事件
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        try {
            /** @var WidgetRegistry $registry */
            $registry = ObjectManager::getInstance(WidgetRegistry::class);
            
            // 刷新部件注册表
            $registry->refresh();
        } catch (\Exception $e) {
            error_log("模块升级后收集部件注册表时出错: " . $e->getMessage());
        }
    }
}
