<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentStorefrontLandingUrlService;

/**
 * Builds canonical Checkout L3 success URLs for Payment browser landing injection.
 */
final class CheckoutSuccessUrlBuilder
{
    public function __construct(
        private readonly Url $url,
        private readonly ?PaymentStorefrontLandingUrlService $storefrontUrls = null,
    ) {
    }

    /**
     * @param list<string> $orderUuids
     * @param array<string, scalar|null> $queryExtras
     */
    public function buildForOrders(array $orderUuids, array $queryExtras = [], ?int $websiteId = null): string
    {
        $orderUuids = array_values(array_filter(array_map(
            static fn(mixed $uuid): string => trim((string) $uuid),
            $orderUuids,
        )));
        $params = array_replace($queryExtras, []);
        if ($orderUuids !== []) {
            $params['order_uuid'] = $orderUuids[0];
            if (count($orderUuids) > 1) {
                $params['order_uuids'] = implode(',', $orderUuids);
            }
        }

        return $this->buildRoute('checkout/success', $params, $websiteId);
    }

    /**
     * Express review landing (PayPal return before capture).
     *
     * @param array<string, scalar|null> $queryExtras
     */
    public function buildExpressReview(array $queryExtras = [], ?int $websiteId = null): string
    {
        return $this->buildRoute('checkout/express-review', $queryExtras, $websiteId);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildRoute(string $route, array $query, ?int $websiteId): string
    {
        if ($websiteId !== null) {
            try {
                $storefront = $this->storefrontUrls();
                $base = $storefront->resolveStorefrontBaseUrl($websiteId);
                if ($base !== '') {
                    return $storefront->buildAbsoluteRoute($route, $query, $base, '', $websiteId);
                }
            } catch (\Throwable) {
                // Unit tests / CLI without ObjectManager bootstrap fall back to Url helper.
            }
        }

        return $this->url->getUrl($route, $query);
    }

    private function storefrontUrls(): PaymentStorefrontLandingUrlService
    {
        if ($this->storefrontUrls !== null) {
            return $this->storefrontUrls;
        }

        return ObjectManager::getInstance()->getInstance(PaymentStorefrontLandingUrlService::class);
    }
}