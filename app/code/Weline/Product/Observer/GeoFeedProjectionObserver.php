<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;

/**
 * Product-owned bridge: projection URL changes write GEO feed items when Weline_Geo is present.
 */
final class GeoFeedProjectionObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!class_exists(\Weline\Geo\Service\FeedSubmitService::class)) {
            return;
        }

        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            return;
        }
        if ($change->resourceType() !== 'product_search_projection') {
            return;
        }

        try {
            /** @var \Weline\Geo\Service\FeedSubmitService $feedSubmit */
            $feedSubmit = ObjectManager::getInstance(\Weline\Geo\Service\FeedSubmitService::class);
        } catch (\Throwable) {
            return;
        }

        $payload = $change->toArray();
        $impact = is_array($payload['impact'] ?? null) ? $payload['impact'] : [];
        $after = is_array($payload['after'] ?? null) ? $payload['after'] : [];
        $productId = (int)($after['target_id'] ?? 0);
        $websiteId = $change->websiteId();
        $urls = is_array($impact['urls'] ?? null) ? $impact['urls'] : [];

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            $feedSubmit->requestSubmit($url, 'product', [
                'subject_type' => 'product',
                'subject_id' => $productId,
                'item_type' => 'product',
                'item_id' => $productId,
                'website_id' => $websiteId,
                'title' => $url,
                'is_published' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'source' => 'Weline_Product::geo_feed_projection',
            ]);
        }
    }
}
