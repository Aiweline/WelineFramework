<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Observer;

use Weline\Backend\Service\MenuCollector;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class UpgradeMenu implements ObserverInterface
{
    private MenuCollector $menuCollector;

    public function __construct(
        MenuCollector $menuCollector
    )
    {
        $this->menuCollector = $menuCollector;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 从事件数据中获取需要更新菜单的模块列表（可选）
        $modules = $event->getEvenData('modules');
        if (!is_array($modules)) {
            $modules = [];
        }
        
        // 委托给菜单收集服务（收集所有模块的菜单，禁用模块的菜单会自动设置为 is_enable=0）
        $this->collectMenus($modules);
        // 注意：Observer 的 execute 方法应该返回 void，返回值被忽略
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function collectMenus(array $modulesFilter = []): array
    {
        // 统一委托给菜单收集服务，保持向后兼容返回值结构
        return $this->menuCollector->collect($modulesFilter);
    }
}
