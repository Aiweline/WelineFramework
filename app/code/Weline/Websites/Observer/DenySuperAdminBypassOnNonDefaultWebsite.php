<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteAclGrantService;

/** Deny super-admin ACL bypass on non-default websites (site capability ceiling). */
final class DenySuperAdminBypassOnNonDefaultWebsite implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var WebsiteAclGrantService $grants */
        $grants = ObjectManager::getInstance(WebsiteAclGrantService::class);
        if (!$grants->isDefaultWebsite()) {
            $event->setData('allow_bypass', false);
        }
    }
}
