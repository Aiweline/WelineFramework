<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOfferQueue;
use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;

/**
 * After FPC URL purge: enqueue expanded URLs for origin StorefrontWarmup drain.
 * Enqueue only — no HTTP. Not CDN Warmup.
 */
final class FpcWarmCapability implements ChangedCapabilityInterface
{
    public function code(): string
    {
        return 'fpc_warm';
    }

    public function description(): string
    {
        return '源站 FPC URL 预热入队（Changed purge 闭环）';
    }

    public function supportedEffects(): array
    {
        return [InvalidationEffect::CODE_WARM_FPC_URLS];
    }

    public function execute(InvalidationEffect $effect, ResourceChange $change): void
    {
        if ($effect->code !== InvalidationEffect::CODE_WARM_FPC_URLS) {
            return;
        }
        $urls = is_array($effect->payload['urls'] ?? null) ? $effect->payload['urls'] : [];
        if ($urls === []) {
            return;
        }
        /** @var StorefrontWarmupOfferQueue $queue */
        $queue = ObjectManager::getInstance(StorefrontWarmupOfferQueue::class);
        $queue->offer(
            array_map('strval', $urls),
            'changed:' . $change->resourceType(),
            [
                'event_id' => $change->eventId(),
                'website_id' => $change->hasWebsiteContext() ? $change->websiteId() : null,
            ],
        );
    }
}
