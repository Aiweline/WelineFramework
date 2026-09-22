<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 历史：非默认站曾关闭超管 ACL bypass（站能力硬顶）。
 * 现行策略：超管（role_id=1）无站点级区分，本观察者保持注册但不再改 allow_bypass。
 */
final class DenySuperAdminBypassOnNonDefaultWebsite implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // no-op: super-admin bypass stays allowed on all websites
    }
}
