<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/**
 * Request-scoped PDP buy-box facts (nested slots + product-info parent leaf).
 *
 * Filled once by Detail after offer seed / page assigns. Slots read bag first;
 * null key = miss (cold fallback allowed); present key (even empty) = hit.
 *
 * Does not replace {@see StorefrontOfferResolver} (single offer) or
 * {@see StorefrontPageAssignBag} (offers / variant_catalog).
 */
final class StorefrontPdpBuyBoxBag
{
    public const BAG_KEY = 'product.pdp_buy_box.v1';

    /**
     * Build and store buy-box facts for the current PDP display offer.
     *
     * @param array<string, mixed> $displayOffer
     */
    public static function fillFromDisplayOffer(array $displayOffer): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }

        $productId = max(0, (int)($displayOffer['product_id'] ?? 0));
        $offerId = max(0, (int)($displayOffer['offer_id'] ?? $displayOffer['product_offer_id'] ?? 0));
        $offerUuid = trim((string)($displayOffer['global_offer_uuid'] ?? ''));
        $slug = strtolower(trim((string)($displayOffer['slug'] ?? '')));
        $sellable = !empty($displayOffer['sellable']);
        $quoteOnly = !empty($displayOffer['quote_only']);
        $selectionRequired = !empty($displayOffer['selection_required']);
        $requiresShipping = array_key_exists('requires_shipping', $displayOffer)
            ? (bool)$displayOffer['requires_shipping']
            : true;
        $shippingProfileCode = trim((string)($displayOffer['shipping_profile_code']
            ?? ($displayOffer['fulfillment_metadata']['shipping_profile_code'] ?? '')));

        $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = max(0, (int)RequestContext::getWelineStoreId());
        $bag = [
            'product_id' => $productId,
            'offer_id' => $offerId,
            'global_offer_uuid' => $offerUuid,
            'slug' => $slug,
            'sellable' => $sellable,
            'quote_only' => $quoteOnly,
            'requires_shipping' => $requiresShipping,
            'selection_required' => $selectionRequired,
            'origin_country' => self::resolveOriginCountry(),
            'express_methods' => self::resolveExpressMethods(),
            'selling' => self::resolveSellingFacts($displayOffer, $websiteId, $storeId),
            'after_add' => self::resolveAfterAddFacts($productId, $slug, $websiteId, $storeId),
            'shipping_hint' => self::resolveShippingHint($shippingProfileCode, $requiresShipping),
            'review' => self::resolveReviewFacts($offerUuid),
        ];

        RequestContext::set(self::BAG_KEY, $bag);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pull(): array
    {
        $value = RequestContext::get(self::BAG_KEY);

        return is_array($value) ? $value : [];
    }

    public static function has(): bool
    {
        return self::pull() !== [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $bag = self::pull();

        return array_key_exists($key, $bag) ? $bag[$key] : $default;
    }

    /** True when Detail filled the bag (key present), including empty string/array values. */
    public static function hasKey(string $key): bool
    {
        return array_key_exists($key, self::pull());
    }

    public static function reset(): void
    {
        RequestContext::remove(self::BAG_KEY);
    }

    private static function resolveOriginCountry(): string
    {
        if (!class_exists(\Weline\Shipping\Service\StorefrontOfferOriginCountryService::class)) {
            return '';
        }
        try {
            /** @var \Weline\Shipping\Service\StorefrontOfferOriginCountryService $svc */
            $svc = ObjectManager::getInstance(\Weline\Shipping\Service\StorefrontOfferOriginCountryService::class);

            return trim((string)$svc->resolveDefaultOriginCountry());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function resolveExpressMethods(): array
    {
        if (!interface_exists(\Weline\Payment\Api\PaymentExpressFacadeInterface::class)
            && !class_exists(\Weline\Payment\Service\ExpressCheckoutOrchestrator::class)
        ) {
            return [];
        }
        try {
            $facade = ObjectManager::getInstance(\Weline\Payment\Api\PaymentExpressFacadeInterface::class);
            if (!$facade instanceof \Weline\Payment\Api\PaymentExpressFacadeInterface) {
                $facade = ObjectManager::getInstance(\Weline\Payment\Service\ExpressCheckoutOrchestrator::class);
            }
            if (!is_object($facade) || !method_exists($facade, 'listExpressMethods')) {
                return [];
            }
            $methods = $facade->listExpressMethods([]);

            return is_array($methods) ? array_values(array_filter($methods, 'is_array')) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Guest-safe selling facts only (no membership / login state).
     *
     * @param array<string, mixed> $displayOffer
     * @return array{
     *   site_tob_enabled: bool,
     *   sku_wholesale_ok: bool,
     *   toc_enabled: bool,
     *   tob_enabled: bool,
     *   moq: int,
     *   qty_step: int
     * }
     */
    private static function resolveSellingFacts(array $displayOffer, int $websiteId, int $storeId): array
    {
        $moq = 5;
        $qtyStep = 5;
        $siteTobEnabled = true;
        $skuWholesaleOk = true;
        $tocEnabled = true;
        $tobEnabled = true;
        $sku = trim((string)($displayOffer['sku'] ?? ''));

        if (!class_exists(\Weline\B2B\Service\SellingModePolicy::class)) {
            return [
                'site_tob_enabled' => $siteTobEnabled,
                'sku_wholesale_ok' => $skuWholesaleOk,
                'toc_enabled' => $tocEnabled,
                'tob_enabled' => $tobEnabled,
                'moq' => $moq,
                'qty_step' => $qtyStep,
            ];
        }

        try {
            /** @var \Weline\B2B\Service\SellingModePolicy $policy */
            $policy = ObjectManager::getInstance(\Weline\B2B\Service\SellingModePolicy::class);
            $moq = max(1, (int)$policy->defaultMoq());
            $qtyStep = max(1, (int)$policy->defaultQtyStep());
            $siteTobEnabled = $policy->isModeEnabled(
                \Weline\B2B\Service\SellingModePolicy::MODE_TOB,
                $websiteId,
                $storeId,
                null,
            );
        } catch (\Throwable) {
        }

        try {
            if (class_exists(\Weline\B2B\Service\ProductWholesaleEligibility::class)) {
                /** @var \Weline\B2B\Service\ProductWholesaleEligibility $eligibility */
                $eligibility = ObjectManager::getInstance(\Weline\B2B\Service\ProductWholesaleEligibility::class);
                $skuWholesaleOk = $eligibility->allowsWholesaleDisplay($websiteId, $storeId, null, $sku);
            }
        } catch (\Throwable) {
            $skuWholesaleOk = false;
        }

        $productFlags = null;
        try {
            if (class_exists(\Weline\B2B\Service\ProductSellingModeFlags::class)) {
                $productFlags = \Weline\B2B\Service\ProductSellingModeFlags::fromOffer($displayOffer);
            }
        } catch (\Throwable) {
            $productFlags = null;
        }

        try {
            /** @var \Weline\B2B\Service\SellingModePolicy $policy */
            $policy = ObjectManager::getInstance(\Weline\B2B\Service\SellingModePolicy::class);
            $tocEnabled = $policy->isModeEnabled(
                \Weline\B2B\Service\SellingModePolicy::MODE_TOC,
                $websiteId,
                $storeId,
                $productFlags,
            );
            $tobEnabled = $skuWholesaleOk && $policy->isModeEnabled(
                \Weline\B2B\Service\SellingModePolicy::MODE_TOB,
                $websiteId,
                $storeId,
                $productFlags,
            );
        } catch (\Throwable) {
        }

        if (!$skuWholesaleOk) {
            $tobEnabled = false;
        }

        return [
            'site_tob_enabled' => $siteTobEnabled,
            'sku_wholesale_ok' => $skuWholesaleOk,
            'toc_enabled' => $tocEnabled,
            'tob_enabled' => $tobEnabled,
            'moq' => $moq,
            'qty_step' => $qtyStep,
        ];
    }

    /**
     * @return array{share_enabled: bool, product_id: int, slug: string}
     */
    private static function resolveAfterAddFacts(int $productId, string $slug, int $websiteId, int $storeId): array
    {
        $shareEnabled = true;
        if (class_exists(\Weline\Affiliate\Service\AffiliateStorefrontPolicy::class)) {
            try {
                /** @var \Weline\Affiliate\Service\AffiliateStorefrontPolicy $policy */
                $policy = ObjectManager::getInstance(\Weline\Affiliate\Service\AffiliateStorefrontPolicy::class);
                $shareEnabled = $policy->isProductShareEnabled($websiteId, $storeId);
            } catch (\Throwable) {
                $shareEnabled = true;
            }
        }

        return [
            'share_enabled' => $shareEnabled,
            'product_id' => $productId,
            'slug' => $slug,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveShippingHint(string $shippingProfileCode, bool $requiresShipping): ?array
    {
        if (!class_exists(\Weline\Product\Service\Storefront\StorefrontShippingProfileCatalogProviderRegistry::class)) {
            return null;
        }
        try {
            $shipReg = ObjectManager::getInstance(
                \Weline\Product\Service\Storefront\StorefrontShippingProfileCatalogProviderRegistry::class,
            );
            $shipProvider = $shipReg->primary();
            if ($shipProvider === null) {
                return null;
            }
            $hint = $shipProvider->previewHint($shippingProfileCode, $requiresShipping);

            return is_array($hint) ? $hint : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{average_rating: float, review_count: int}
     */
    private static function resolveReviewFacts(string $offerUuid): array
    {
        $rating = 0.0;
        $count = 0;
        if ($offerUuid === '' || !class_exists(\Weline\Review\Service\ReviewService::class)) {
            return ['average_rating' => $rating, 'review_count' => $count];
        }
        try {
            $facts = ObjectManager::getInstance(\Weline\Review\Service\ReviewService::class)
                ->seoFacts('product', $offerUuid, 1);
            $rating = max(0.0, (float)($facts['average_rating'] ?? 0));
            $count = max(0, (int)($facts['review_count'] ?? 0));
        } catch (\Throwable) {
        }

        return ['average_rating' => $rating, 'review_count' => $count];
    }
}
