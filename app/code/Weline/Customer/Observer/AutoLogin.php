<?php

declare(strict_types=1);

namespace Weline\Customer\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Customer\Service\CustomerRememberDeviceService;

/**
 * 自动登录Observer
 * 在用户访问时检查"记住我"token，自动登录
 */
class AutoLogin implements ObserverInterface
{
    /**
     * 执行自动登录
     */
    public function execute(Event &$event): void
    {
        try {
            ObjectManager::getInstance(CustomerRememberDeviceService::class)->restoreIfNeeded();
        } catch (\Throwable) {
            // A configured provider failure is intentionally fail-closed. Keep cookies
            // untouched so a transient outage cannot destroy a recoverable credential.
        }
    }
}
