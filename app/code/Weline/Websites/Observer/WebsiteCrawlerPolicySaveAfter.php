<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Websites\Service\WebsiteCrawlerPolicyService;

class WebsiteCrawlerPolicySaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebsiteCrawlerPolicyService $policyService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (!\is_array($eventData)
            || !\array_key_exists('website_id', $eventData)
            || $eventData['website_id'] === null
        ) {
            return;
        }
        $websiteId = (int)$eventData['website_id'];
        if ($websiteId < 0) {
            return;
        }

        $postData = $event->getData('post_data');
        if (!\is_array($postData)) {
            return;
        }
        $extensions = \is_array($postData['extensions'] ?? null) ? $postData['extensions'] : [];
        if (!\is_array($extensions['crawler'] ?? null)) {
            return;
        }

        $connection = $event->getData('connection');
        if (!$connection instanceof ConnectionFactory) {
            throw new \RuntimeException(
                'website_save_after missing connection; crawler policy must not open a second PDO'
            );
        }

        $this->policyService->saveForWebsite($websiteId, $extensions['crawler'], $connection);
    }
}
