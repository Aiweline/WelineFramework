<?php

declare(strict_types=1);

namespace Weline\Admin\Observer;

use Weline\Admin\Service\BackendMenuSearchIndexRebuilder;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * ACL / role_access 变更后重建后台菜单搜索索引。
 */
final class BackendMenuSearchIndexRebuildObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        unset($event);
        BackendMenuSearchIndexRebuilder::rebuild(0);
    }
}
