<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;

/**
 * Supplies category candidates (name + URL + tag) for all-menu nav-tree editor.
 */
class AllMenuCategoryCandidates implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $existing = $event->getData('candidates');
        if (is_array($existing) && $existing !== []) {
            return;
        }

        try {
            $websiteId = $this->resolveWebsiteId();
            /** @var StorefrontAllMenuCategoryTreeService $service */
            $service = ObjectManager::getInstance(StorefrontAllMenuCategoryTreeService::class);
            $event->setData('candidates', $service->navTree($websiteId));
        } catch (\Throwable) {
            $event->setData('candidates', is_array($existing) ? $existing : []);
        }
    }

    private function resolveWebsiteId(): int
    {
        try {
            if (class_exists(\Weline\Websites\Service\WebsiteAclGrantService::class)) {
                /** @var \Weline\Websites\Service\WebsiteAclGrantService $grants */
                $grants = ObjectManager::getInstance(\Weline\Websites\Service\WebsiteAclGrantService::class);
                $id = (int)$grants->currentWebsiteId();
                if ($id >= 0) {
                    return $id;
                }
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
