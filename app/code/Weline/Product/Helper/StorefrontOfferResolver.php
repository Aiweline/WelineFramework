<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Product\Service\StorefrontCatalogViewService;

/**
 * Resolve the current PDP storefront offer for slot widgets that render outside
 * the Detail controller assign() context.
 *
 * Identity comes from request Context (`input.query.id` / `slug`) after Router/Detail
 * resolve once — do not re-parse public URLs here.
 */
final class StorefrontOfferResolver
{
    private const CONTEXT_OFFER_KEY = 'product.storefront.resolved_offer';

    /** Carry the selected PDP projection to independent slot templates in this request. */
    public static function rememberResolvedOffer(array $offer): void
    {
        if (Context::hasCurrent()) {
            Context::current()->set(self::CONTEXT_OFFER_KEY, $offer);
        }
    }

    /** @return array<string, mixed> */
    public static function currentOffer(): array
    {
        $offer = Context::getCurrent()?->get(self::CONTEXT_OFFER_KEY);
        return is_array($offer) ? $offer : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolve(Template $template): array
    {

        $offer = $template->getData('storefront_offer');
        $offer = is_array($offer) ? $offer : [];
        if ($offer !== []) {
            return self::enrichOffer($offer);
        }

        $cardProductId = max(0, (int)$template->getData('card_product_id'));
        $contextOffer = self::currentOffer();
        if ($contextOffer !== [] && ($cardProductId === 0 || (int)($contextOffer['product_id'] ?? 0) === $cardProductId)) {
            return self::enrichOffer($contextOffer);
        }

        $bagProduct = self::seoBagProduct();
        if ($bagProduct !== [] && ($cardProductId === 0 || (int)($bagProduct['product_id'] ?? 0) === $cardProductId)) {
            return self::enrichOffer($bagProduct);
        }

        if ($cardProductId > 0) {
            try {
                /** @var StorefrontCatalogViewService $catalog */
                $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
                $resolved = $catalog->publishedOffer($cardProductId);
                if (is_array($resolved) && $resolved !== []) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Optional catalog dependency; keep empty when unavailable.
            }
            return [];
        }

        if (!class_exists(StorefrontCatalogViewService::class)) {
            return [];
        }

        try {
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
            [$slug, $productId] = self::resolveRouteIdentity($template);
            $resolved = null;
            if ($slug !== '') {
                $resolved = $catalog->publishedOfferBySlug($slug);
            } elseif ($productId > 0) {
                $resolved = $catalog->publishedOffer($productId);
            }
            if (is_array($resolved) && $resolved !== []) {
                self::carryIdentity(
                    max(0, (int)($resolved['product_id'] ?? $productId)),
                    strtolower(trim((string)($resolved['slug'] ?? $slug))),
                );

                return $resolved;
            }
        } catch (\Throwable $e) {
            // Optional catalog dependency; keep empty when unavailable.
        }

        return [];
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private static function enrichOffer(array $offer): array
    {
        $productId = max(0, (int)($offer['product_id'] ?? 0));
        $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
        if ($productId <= 0 || $offerUuid !== '') {
            return $offer;
        }

        // Detail clears uuid while configurable selection is still required.
        // Slot widgets must not re-hydrate the catalog for every empty uuid.
        if (!empty($offer['selection_required'])) {
            return $offer;
        }

        // Already a hydrated PDP/card projection.
        if (trim((string)($offer['name'] ?? '')) !== ''
            || (isset($offer['images']) && is_array($offer['images']))
        ) {
            return $offer;
        }

        if (!class_exists(StorefrontCatalogViewService::class)) {
            return $offer;
        }

        try {
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
            $resolved = $catalog->publishedOffer($productId);
            if (is_array($resolved) && $resolved !== []) {
                return $resolved;
            }
        } catch (\Throwable) {
            // Optional catalog dependency; keep card snapshot when unavailable.
        }

        return $offer;
    }

    /**
     * @return array<string, mixed>
     */
    private static function seoBagProduct(): array
    {
        if (!class_exists(\Weline\Seo\Service\Head\SeoPageProfileBag::class)) {
            return [];
        }
        $profile = \Weline\Seo\Service\Head\SeoPageProfileBag::pull();
        $product = is_array($profile['product'] ?? null) ? $profile['product'] : [];
        unset($product['storefront_offers']);

        return $product;
    }

    /**
     * Prefer Context / request params. Never re-parse URLs.
     *
     * @return array{0:string,1:int}
     */
    private static function resolveRouteIdentity(Template $template): array
    {
        $slug = '';
        $productId = 0;

        try {
            $ctx = Context::current();
            $slug = strtolower(trim((string)($ctx->query('slug') ?? '')));
            $productId = (int)($ctx->query('id') ?? 0);
        } catch (\Throwable) {
        }

        if ($slug === '' && $productId <= 0) {
            try {
                $request = $template->getRequest();
                $slug = strtolower(trim((string)$request->getParam('slug', '')));
                $productId = (int)$request->getParam('id', 0);
            } catch (\Throwable) {
            }
        }

        return [$slug, max(0, $productId)];
    }

    private static function carryIdentity(int $productId, string $slug): void
    {
        try {
            $ctx = Context::current();
            if ($productId > 0) {
                $ctx->set('input.query.id', $productId);
            }
            if ($slug !== '') {
                $ctx->set('input.query.slug', $slug);
            }
        } catch (\Throwable) {
        }
    }
}
