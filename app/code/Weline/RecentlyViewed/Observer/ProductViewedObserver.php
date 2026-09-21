<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\RequestContext;

/**
 * Queue product view for client-side / post-FPC cookie write.
 *
 * SSR must not emit Set-Cookie: FullPageCacheCoordinator refuses to publish
 * any response that carries cookies, which permanently MISSes PDP FPC.
 */
final class ProductViewedObserver implements ObserverInterface
{
    public const PENDING_PRODUCT_ID_KEY = 'weline.recently_viewed.pending_product_id';

    public function execute(Event &$event): void
    {
        $productId = (int)($event->getData('product_id') ?? 0);
        if ($productId <= 0) {
            return;
        }
        // Stash for optional later flush; primary persistence is client-side
        // from data-product-id so FPC HIT pages still update the MRU cookie.
        RequestContext::set(self::PENDING_PRODUCT_ID_KEY, $productId);
    }
}
