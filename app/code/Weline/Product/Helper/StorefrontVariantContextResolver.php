<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontPdpVariantShell;
use Weline\Product\Service\StorefrontVariantSelectionService;

/**
 * Resolves configurable-product offers and interactive variant catalog for PDP widgets.
 *
 * Identity is read from request Context after Router/Detail resolve once.
 */
final class StorefrontVariantContextResolver
{
    /**
     * @return list<array<string, mixed>>
     */
    public function resolveOffers(Template $template): array
    {
        $assigned = $template->getData('storefront_offers');
        if (is_array($assigned)) {
            $offers = array_values(array_filter($assigned, 'is_array'));
            if ($offers !== []) {
                return $offers;
            }
        }

        $pageAssignOffers = StorefrontPageAssignBag::get('storefront_offers');
        if (is_array($pageAssignOffers)) {
            $offers = array_values(array_filter($pageAssignOffers, 'is_array'));
            if ($offers !== []) {
                return $offers;
            }
        }

        $bagOffers = $this->seoBagOffers();
        if ($bagOffers !== []) {
            return $bagOffers;
        }

        if (!class_exists(StorefrontCatalogViewService::class)) {
            return [];
        }

        try {
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
            [$slug, $productId] = $this->resolveRouteIdentity($template);
            if ($slug !== '') {
                $offers = $catalog->publishedOffersBySlug($slug);
                if ($offers !== []) {
                    $this->carryIdentity(
                        max(0, (int)($offers[0]['product_id'] ?? $productId)),
                        strtolower(trim((string)($offers[0]['slug'] ?? $slug))),
                    );
                }

                return $offers;
            }
            if ($productId > 0) {
                $offers = $catalog->publishedOffersForProduct($productId);
                if ($offers !== []) {
                    $this->carryIdentity(
                        max(0, (int)($offers[0]['product_id'] ?? $productId)),
                        strtolower(trim((string)($offers[0]['slug'] ?? ''))),
                    );
                }

                return $offers;
            }

            $offer = StorefrontOfferResolver::resolve($template);
            $resolvedProductId = (int)($offer['product_id'] ?? 0);
            if ($resolvedProductId > 0) {
                return $catalog->publishedOffersForProduct($resolvedProductId);
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    /**
     * Neutral catalog for path-only PDP: never bind request query into selected.
     * Prefer Detail/PageAssignBag catalog when already published for the request.
     *
     * @param list<array<string, mixed>> $offers
     * @return array{axes:list<array<string,mixed>>,offers:list<array<string,mixed>>,selected:array<string,string>}
     */
    public function buildCatalog(Template $template, array $offers, ?array $selectedOffer = null): array
    {
        unset($selectedOffer);
        $assigned = StorefrontPageAssignBag::get('variant_catalog');
        if (is_array($assigned)
            && is_array($assigned['offers'] ?? null)
            && $assigned['offers'] !== []
        ) {
            $catalog = $assigned;
            unset($catalog['selected_offer']);
            $catalog['selected'] = is_array($catalog['selected'] ?? null) ? $catalog['selected'] : [];

            return $catalog;
        }

        if ($offers === []) {
            return ['axes' => [], 'offers' => [], 'selected' => []];
        }

        try {
            /** @var StorefrontPdpVariantShell $shell */
            $shell = ObjectManager::getInstance(StorefrontPdpVariantShell::class);
            $built = $shell->buildNeutral($offers);
            $catalog = $built['variant_catalog'];
            unset($catalog['selected_offer']);
            $catalog['selected'] = is_array($catalog['selected'] ?? null) ? $catalog['selected'] : [];

            return $catalog;
        } catch (\Throwable) {
            /** @var StorefrontVariantSelectionService $selection */
            $selection = ObjectManager::getInstance(StorefrontVariantSelectionService::class);
            $catalog = $selection->compactCatalogMedia($selection->buildCatalog($offers, null));
            $catalog['selected'] = [];

            return $catalog;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seoBagOffers(): array
    {
        if (!class_exists(\Weline\Seo\Service\Head\SeoPageProfileBag::class)) {
            return [];
        }
        $profile = \Weline\Seo\Service\Head\SeoPageProfileBag::pull();
        $product = is_array($profile['product'] ?? null) ? $profile['product'] : [];
        $offers = is_array($product['storefront_offers'] ?? null) ? $product['storefront_offers'] : [];
        $offers = array_values(array_filter($offers, 'is_array'));
        if ($offers !== []) {
            return $offers;
        }

        return $product !== [] ? [$product] : [];
    }

    /**
     * Prefer Context / request params. Never re-parse URLs.
     *
     * @return array{0:string,1:int}
     */
    private function resolveRouteIdentity(Template $template): array
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

    private function carryIdentity(int $productId, string $slug): void
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
