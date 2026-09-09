<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontVariantSelectionService;

/**
 * Builds storefront product-detail query params for listing cards.
 *
 * Prefer public EAV option codes (color=lan-se-jin-s) over opaque offer UUIDs.
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
        $uuid = trim((string)($offer['global_offer_uuid'] ?? ''));
        $selection = self::combination($offer);
        $query = [];
        if ($selection !== []) {
            try {
                /** @var StorefrontEavLabelResolver $labels */
                $labels = ObjectManager::getInstance(StorefrontEavLabelResolver::class);
                $public = $labels->toPublicQuery($selection);
                if ($public !== []) {
                    $query = $public;
                }
            } catch (\Throwable) {
            }
        }

        if ($query === [] && $uuid !== '') {
            $query = ['offer' => $uuid];
        }

        $themeId = max(0, (int)($offer['promotion_theme_id'] ?? 0));
        $campaignSlug = strtolower(trim((string)($offer['campaign_page_slug'] ?? $offer['page_slug'] ?? '')));
        if ($themeId > 0 || ($campaignSlug !== '' && $campaignSlug !== 'index')) {
            $query = StorefrontCampaignEntry::mergeIntoQuery($query, $themeId, $campaignSlug);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, string>
     */
    private static function combination(array $offer): array
    {
        $combination = $offer['combination'] ?? null;
        if (is_array($combination) && $combination !== []) {
            $normalized = [];
            foreach ($combination as $axis => $value) {
                $axis = strtolower(trim((string)$axis));
                $value = trim((string)$value);
                if ($axis !== '' && $value !== '') {
                    $normalized[$axis] = $value;
                }
            }
            ksort($normalized, SORT_STRING);

            return $normalized;
        }

        try {
            /** @var StorefrontVariantSelectionService $selection */
            $selection = ObjectManager::getInstance(StorefrontVariantSelectionService::class);

            return $selection->offerCombination($offer);
        } catch (\Throwable) {
            return [];
        }
    }
}
