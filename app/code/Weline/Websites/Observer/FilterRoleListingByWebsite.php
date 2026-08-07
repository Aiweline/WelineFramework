<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteAclGrantService;

/**
 * Filter role listing query to current website_id.
 * Event data: website_id (int|null to set), role_model (Role).
 */
final class FilterRoleListingByWebsite implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var WebsiteAclGrantService $grants */
        $grants = ObjectManager::getInstance(WebsiteAclGrantService::class);
        $websiteId = $grants->currentWebsiteId();
        $event->setData('website_id', $websiteId);

        $roleModel = $event->getData('role_model');
        if (\is_object($roleModel) && \method_exists($roleModel, 'where')) {
            $roleModel->where(\Weline\Acl\Model\Role::schema_fields_WEBSITE_ID, $websiteId);
        }
    }
}
