<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteAclGrantService;

/**
 * Before saving role_access on a non-default site: reject sources outside the grant package.
 * Event data: role_id, website_id?, source_ids[], allowed (bool), reject_source_ids[].
 */
final class ValidateRoleAccessAgainstWebsiteGrant implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var WebsiteAclGrantService $grants */
        $grants = ObjectManager::getInstance(WebsiteAclGrantService::class);
        $websiteId = (int)($event->getData('website_id') ?? $grants->currentWebsiteId());
        if ($websiteId === Website::ID_DEFAULT) {
            return;
        }

        $sourceIds = $event->getData('source_ids');
        if (!\is_array($sourceIds)) {
            $sourceIds = [];
        }
        $outside = $grants->findSourcesOutsideGrant($websiteId, $sourceIds);
        if ($outside !== []) {
            $event->setData('allowed', false);
            $event->setData('reject_source_ids', $outside);
            $event->setData('message', (string)__('不能分配超出该站授权包的权限'));
        }
    }
}
