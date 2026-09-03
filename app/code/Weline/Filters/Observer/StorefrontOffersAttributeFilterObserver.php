<?php

declare(strict_types=1);

namespace Weline\Filters\Observer;

use Weline\Filters\Service\StorefrontAttributeListingFilter;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Applies af_* attribute facets onto storefront listing offers after Product price/sort.
 */
final class StorefrontOffersAttributeFilterObserver implements ObserverInterface
{
    public function __construct(
        private readonly StorefrontAttributeListingFilter $attributeListing,
    ) {
    }

    public function execute(Event &$event): void
    {
        $offers = $event->getData('offers');
        if (!is_array($offers)) {
            return;
        }

        $query = $event->getData('query');
        if (!is_array($query)) {
            $query = [];
        }

        $selected = $this->attributeListing->normalizeFromRequest($query);
        if ($selected === []) {
            return;
        }

        $filtered = $this->attributeListing->apply($offers, $selected);
        $event->setData('offers', $filtered);
    }
}
