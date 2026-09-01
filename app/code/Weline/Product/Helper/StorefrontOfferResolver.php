<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Product\Service\StorefrontCatalogViewService;

/**
 * Resolve the current PDP storefront offer for slot widgets that render outside
 * the Detail controller assign() context.
 */
final class StorefrontOfferResolver
{
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
                return $resolved;
            }
        } catch (\Throwable) {
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
     * @return array{0:string,1:int}
     */
    private static function resolveRouteIdentity(Template $template): array
    {
        $slug = '';
        $productId = 0;

        try {
            $request = $template->getRequest();
            $slug = strtolower(trim((string)$request->getParam('slug', '')));
            $productId = (int)$request->getParam('id', 0);
        } catch (\Throwable) {
            $slug = '';
            $productId = 0;
        }

        $publicRoute = '';
        try {
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $publicRoute = trim(str_replace('\\', '/', (string)$request->getParam('theme_public_route', '')), '/');
        } catch (\Throwable) {
            $publicRoute = '';
        }
        if ($publicRoute === '' && isset($_GET['theme_public_route'])) {
            $publicRoute = trim(str_replace('\\', '/', (string)$_GET['theme_public_route']), '/');
        }
        if ($publicRoute !== '' && preg_match('#(?:^|/)product/([a-z0-9][a-z0-9-]*)(?:/|$)#i', strtolower($publicRoute), $routeMatch) === 1) {
            $handle = strtolower((string)$routeMatch[1]);
            if (ctype_digit($handle)) {
                $productId = (int)$handle;
            } else {
                $slug = $handle;
            }
        }

        if ($slug === '' && $productId <= 0) {
            $requestUri = (string)(WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''));
            $requestPath = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '');
            $pathParts = array_values(array_filter(
                explode('/', trim($requestPath, '/')),
                static fn(string $part): bool => $part !== '',
            ));
            $productPartIndex = array_search('product', $pathParts, true);
            if ($productPartIndex !== false && isset($pathParts[$productPartIndex + 1])) {
                $handle = strtolower(rawurldecode((string)$pathParts[$productPartIndex + 1]));
                if (ctype_digit($handle)) {
                    $productId = (int)$handle;
                } else {
                    $slug = $handle;
                }
            }
        }

        return [$slug, $productId];
    }
}
