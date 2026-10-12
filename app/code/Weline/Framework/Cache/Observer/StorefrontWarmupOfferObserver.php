<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Observer;

use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOfferQueue;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Weline_Framework::storefront_warmup_offer → ad-hoc origin FPC warm queue.
 */
final class StorefrontWarmupOfferObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            $event->setData('result', ['accepted' => 0, 'success' => false]);

            return;
        }
        $urls = $data['urls'] ?? [];
        if (!is_array($urls)) {
            $urls = [];
        }
        $source = trim((string)($data['source'] ?? 'event'));
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        if (isset($data['website_ids']) && is_array($data['website_ids'])) {
            $meta['website_ids'] = array_values(array_map('intval', $data['website_ids']));
        }
        /** @var StorefrontWarmupOfferQueue $queue */
        $queue = ObjectManager::getInstance(StorefrontWarmupOfferQueue::class);
        $accepted = $queue->offer(array_map('strval', $urls), $source !== '' ? $source : 'event', $meta);
        $event->setData('result', [
            'success' => true,
            'accepted' => $accepted,
            'pending' => $queue->pendingCount(),
        ]);
    }
}
