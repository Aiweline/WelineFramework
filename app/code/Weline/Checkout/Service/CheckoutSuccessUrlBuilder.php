<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Http\Url;

/**
 * Builds canonical Checkout L3 success URLs for Payment browser landing injection.
 */
final class CheckoutSuccessUrlBuilder
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    /**
     * @param list<string> $orderUuids
     * @param array<string, scalar|null> $queryExtras
     */
    public function buildForOrders(array $orderUuids, array $queryExtras = []): string
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

        return $this->url->getUrl('checkout/success', $params);
    }

    /**
     * Express review landing (PayPal return before capture).
     *
     * @param array<string, scalar|null> $queryExtras
     */
    public function buildExpressReview(array $queryExtras = []): string
    {
        return $this->url->getUrl('checkout/express-review', $queryExtras);
    }
}
