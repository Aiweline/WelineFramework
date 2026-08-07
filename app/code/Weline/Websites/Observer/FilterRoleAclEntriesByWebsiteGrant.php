<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteAclGrantService;

/** Non-default website: intersect role ACL entries with the website grant package. */
final class FilterRoleAclEntriesByWebsiteGrant implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $roleId = (int)$event->getData('role_id');
        $entries = $event->getData('entries');
        if ($roleId <= 0 || !\is_array($entries)) {
            return;
        }

        /** @var WebsiteAclGrantService $grants */
        $grants = ObjectManager::getInstance(WebsiteAclGrantService::class);
        $event->setData('entries', $grants->filterRoleAclEntries($entries, $roleId));
    }
}
