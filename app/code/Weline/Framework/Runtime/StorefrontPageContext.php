<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;

/**
 * Request-only hand-off for data already resolved by a storefront controller.
 *
 * This is intentionally not a process/shared cache: page data can contain
 * request-specific filters and must never cross RequestContext boundaries.
 */
final class StorefrontPageContext
{
    private const STORAGE_KEY = 'framework.storefront.page_context.v1';
    private const LISTING_OFFERS_UNFILTERED = 'listing.offers.unfiltered';

    /** @param list<array<string, mixed>> $offers */
    public static function setListingOffers(array $offers): void
    {
        if (!Context::hasCurrent()) {
            return;
        }

        $data = RequestContext::get(self::STORAGE_KEY, []);
        if (!is_array($data)) {
            $data = [];
        }
        $data[self::LISTING_OFFERS_UNFILTERED] = $offers;
        RequestContext::set(self::STORAGE_KEY, $data);
    }

    /**
     * @return list<array<string, mixed>>|null Null means the controller did not
     * publish listing data; an empty list is a valid resolved result.
     */
    public static function listingOffers(): ?array
    {
        if (!Context::hasCurrent()) {
            return null;
        }

        $data = RequestContext::get(self::STORAGE_KEY, []);
        if (!is_array($data) || !array_key_exists(self::LISTING_OFFERS_UNFILTERED, $data)) {
            return null;
        }

        $offers = $data[self::LISTING_OFFERS_UNFILTERED];

        return is_array($offers) ? $offers : null;
    }

    public static function clear(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::remove(self::STORAGE_KEY);
        }
    }
}
