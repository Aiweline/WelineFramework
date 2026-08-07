<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Acl\Model\Acl;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteAclGrantService;

/** On non-default sites, ACL assignment UI only shows the website grant package. */
final class FilterAclAssignmentRowsByWebsiteGrant implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var WebsiteAclGrantService $grants */
        $grants = ObjectManager::getInstance(WebsiteAclGrantService::class);
        $websiteId = (int)($event->getData('website_id') ?? $grants->currentWebsiteId());
        if ($websiteId === Website::ID_DEFAULT) {
            return;
        }

        $rows = $event->getData('rows');
        if (!\is_array($rows)) {
            $event->setData('rows', []);
            return;
        }

        $grantSet = \array_fill_keys($grants->getGrantedSourceIds($websiteId), true);
        if ($grantSet === []) {
            $event->setData('rows', []);
            return;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $sourceId = \trim((string)($row[Acl::schema_fields_SOURCE_ID] ?? ''));
            if ($sourceId !== '' && isset($grantSet[$sourceId])) {
                $filtered[] = $row;
            }
        }
        $event->setData('rows', $filtered);
    }
}
