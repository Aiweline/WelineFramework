<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Repository\AttributeValueRepository;

/**
 * Hydrate SellingModePolicy product flags from storefront offer / product EAV.
 *
 * Unset flags stay absent (SellingModePolicy fail-soft = allow).
 */
final class ProductSellingModeFlags
{
    /**
     * @param array<string,mixed>|null $offer
     * @param array<string,mixed>|null $explicit
     * @return array<string,mixed>|null
     */
    public static function fromOffer(?array $offer = null, ?array $explicit = null): ?array
    {
        if (is_array($explicit) && $explicit !== []) {
            return self::normalize($explicit);
        }
        $offer = is_array($offer) ? $offer : [];
        if ($offer === []) {
            try {
                $offer = StorefrontOfferResolver::currentOffer();
            } catch (\Throwable) {
                $offer = [];
            }
        }

        $productId = max(0, (int)($offer['product_id'] ?? 0));
        $cacheKey = null;
        if ($productId > 0 && RequestContext::isInitialized()) {
            $cacheKey = 'b2b.selling_mode_flags.' . $productId
                . '|' . max(0, (int)RequestContext::getWelineWebsiteId())
                . '|' . max(0, (int)RequestContext::getWelineStoreId());
            $cached = RequestContext::get($cacheKey);
            if (is_array($cached) && array_key_exists('flags', $cached)) {
                $flags = $cached['flags'];

                return is_array($flags) ? $flags : null;
            }
        }

        $flags = [];
        foreach ([SellingModePolicy::PRODUCT_FLAG_TOC, SellingModePolicy::PRODUCT_FLAG_TOB] as $code) {
            if ($offer !== [] && array_key_exists($code, $offer)) {
                $flags[$code] = $offer[$code];
                continue;
            }
            $attrs = $offer['attributes'] ?? null;
            if (is_array($attrs) && array_key_exists($code, $attrs)) {
                $flags[$code] = $attrs[$code];
            }
        }

        if ($productId > 0) {
            $missing = [];
            foreach ([SellingModePolicy::PRODUCT_FLAG_TOC, SellingModePolicy::PRODUCT_FLAG_TOB] as $code) {
                if (!array_key_exists($code, $flags)) {
                    $missing[] = $code;
                }
            }
            if ($missing !== []) {
                $fromEav = self::fromProductEav($productId, $missing);
                foreach ($fromEav as $code => $value) {
                    $flags[$code] = $value;
                }
            }
        }

        $normalized = $flags === [] ? null : self::normalize($flags);
        if ($cacheKey !== null) {
            RequestContext::set($cacheKey, ['flags' => $normalized]);
        }

        return $normalized;
    }

    /**
     * @param list<string> $codes
     * @return array<string,mixed>
     */
    private static function fromProductEav(int $productId, array $codes): array
    {
        if ($productId <= 0 || $codes === [] || !class_exists(AttributeValueRepository::class)) {
            return [];
        }
        try {
            /** @var AttributeValueRepository $repo */
            $repo = ObjectManager::getInstance(AttributeValueRepository::class);
            $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
            $storeId = max(0, (int)RequestContext::getWelineStoreId());
            $out = [];
            foreach ($codes as $code) {
                $row = $repo->read($websiteId, $storeId, 'product', $productId, $code, '', ['']);
                if (!$row->isExplicit()) {
                    continue;
                }
                $out[$code] = $row->value;
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $flags
     * @return array<string,mixed>
     */
    private static function normalize(array $flags): array
    {
        $out = [];
        foreach ($flags as $key => $value) {
            $code = strtolower(trim((string)$key));
            if ($code === '') {
                continue;
            }
            $out[$code] = $value;
        }

        return $out;
    }
}
