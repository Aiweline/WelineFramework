<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Live variant availability for CDN-cached product detail shells.
 *
 * Spec axes and option labels come from the embedded variant catalog snapshot;
 * stock and sellability must be reconciled against the authoritative Cart
 * snapshot resolver on every browser session.
 */
final class StorefrontVariantAvailabilityService
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function livePayload(string $slug, int $productId): ?array
    {
        $slug = \strtolower(\trim($slug));
        if ($productId <= 0 && $slug !== '') {
            $cached = $this->catalog->publishedOfferBySlug($slug);
            $productId = (int)($cached['product_id'] ?? 0);
        }
        if ($productId <= 0) {
            return null;
        }

        $offers = $this->catalog->livePublishedOffersForProduct($productId);
        if ($offers === []) {
            return null;
        }

        return [
            'product_id' => $productId,
            'slug' => \trim((string)($offers[0]['slug'] ?? $slug)),
            'fetched_at' => \gmdate('c'),
            'offers' => \array_map(
                static fn(array $offer): array => [
                    'global_offer_uuid' => \trim((string)($offer['global_offer_uuid'] ?? '')),
                    'combination_key' => \trim((string)($offer['combination_key'] ?? '')),
                    'combination' => \is_array($offer['combination'] ?? null) ? $offer['combination'] : [],
                    'stock' => (int)($offer['stock'] ?? 0),
                    'sellable' => !empty($offer['sellable']),
                    'quote_only' => !empty($offer['quote_only']),
                    'unit_price_minor' => (int)($offer['unit_price_minor'] ?? 0),
                    'currency' => (string)($offer['currency'] ?? 'CNY'),
                    'message' => (string)($offer['message'] ?? ''),
                ],
                $offers,
            ),
        ];
    }
}
