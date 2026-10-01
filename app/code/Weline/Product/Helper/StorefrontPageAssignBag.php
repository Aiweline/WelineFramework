<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

use Weline\Framework\Runtime\RequestContext;

/**
 * Request-scoped storefront page assigns (non-PDP-offer).
 *
 * Controllers publish page facts here so widget Template::unsetData() cannot
 * erase them. PDP single-offer authority remains {@see StorefrontOfferResolver}.
 * Widget/card setData must never call publish/replace (SEO pollution lesson).
 */
final class StorefrontPageAssignBag
{
    public const BAG_KEY = 'product.storefront.page_assigns.v1';

    /** @var list<string> */
    public const ALLOWED_KEYS = [
        'storefront_offers',
        'storefront_offers_unfiltered',
        'storefront_category',
        'storefront_category_children',
        'storefront_category_siblings',
        'storefront_category_tree',
        'storefront_category_active_path_ids',
        'storefront_category_breadcrumbs',
        'storefront_category_path',
        'selected_offer_uuid',
        'variant_catalog',
        'page_title',
        'storefront_heading',
        'storefront_lede',
        'storefront_surface',
        'storefront_listing_price',
        'storefront_listing_sort',
        'storefront_listing_total',
        'storefront_listing_count',
        'storefront_listing_page',
        'storefront_listing_page_size',
        'storefront_listing_total_pages',
        'storefront_listing_page_options',
        'storefront_listing_sort_options',
    ];

    /**
     * Replace the request bag with a whitelist-filtered payload.
     *
     * @param array<string, mixed> $assigns
     */
    public static function replace(array $assigns): void
    {
        RequestContext::set(self::BAG_KEY, self::filterAllowed($assigns));
    }

    /**
     * Merge whitelist keys into the existing bag (controller page publisher only).
     *
     * @param array<string, mixed> $assigns
     */
    public static function publish(array $assigns): void
    {
        $incoming = self::filterAllowed($assigns);
        if ($incoming === []) {
            return;
        }
        $existing = self::pull();
        RequestContext::set(self::BAG_KEY, array_replace($existing, $incoming));
    }

    /**
     * @return array<string, mixed>
     */
    public static function pull(): array
    {
        $value = RequestContext::get(self::BAG_KEY);

        return is_array($value) ? $value : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $bag = self::pull();

        return array_key_exists($key, $bag) ? $bag[$key] : $default;
    }

    /**
     * Prefer Template assign; fall back to request bag when unsetData wiped it.
     */
    public static function coalesce(mixed $templateValue, string $key, mixed $default = null): mixed
    {
        if ($templateValue !== null && $templateValue !== '' && $templateValue !== []) {
            return $templateValue;
        }
        $fromBag = self::get($key);
        if ($fromBag !== null && $fromBag !== '' && $fromBag !== []) {
            return $fromBag;
        }

        return $default;
    }

    public static function reset(): void
    {
        RequestContext::remove(self::BAG_KEY);
    }

    /**
     * @param array<string, mixed> $assigns
     * @return array<string, mixed>
     */
    private static function filterAllowed(array $assigns): array
    {
        $out = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (!array_key_exists($key, $assigns)) {
                continue;
            }
            $out[$key] = $assigns[$key];
        }

        return $out;
    }
}
