<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

/**
 * Builds storefront product-detail query params for listing cards.
 *
 * Public PDP entry is path-only (`/product/{slug}`). Listing cards must not emit
 * offer UUID or axis deep-link query; campaign / quote marketing flags may remain.
 *
 * @return array<string, string>
 */
final class StorefrontOfferDetailQuery
{
    /**
     * @param array<string, mixed> $offer
     * @return array<string, string>
     */
    public static function params(array $offer): array
    {
        $query = [];
        $themeId = max(0, (int)($offer['promotion_theme_id'] ?? 0));
        $campaignSlug = strtolower(trim((string)($offer['campaign_page_slug'] ?? $offer['page_slug'] ?? '')));
        if ($themeId > 0 || ($campaignSlug !== '' && $campaignSlug !== 'index')) {
            $query = StorefrontCampaignEntry::mergeIntoQuery($query, $themeId, $campaignSlug);
        }

        return $query;
    }
}
