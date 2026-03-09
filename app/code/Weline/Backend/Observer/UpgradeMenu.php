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
        // 系统级菜单严格以 menu.xml 为唯一来源，升级后始终全量收集。
        $this->collectMenus([]);
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
