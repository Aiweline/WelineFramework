<?php

declare(strict_types=1);

namespace Weline\Product\Integration\Cart;

use Weline\Cart\Api\CartPriceSellabilityProviderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Service\CatalogConflictException;

/**
 * Product-owned implementation of the Cart sellability provider contract.
 *
 * Currency follows the snapshot resolver (`RequestContext::getWelineUserCurrency`),
 * and a missing display-currency price falls back to base-currency price + FX,
 * mirroring `rate-mode-and-storefront-conversion`: only an explicit cleared row
 * or an unresolvable amount blocks sellability.
 */
final class ProductCartPriceSellabilityProvider implements CartPriceSellabilityProviderInterface
{
    /**
     * Null resolves the repository lazily on first use; tests may inject a duck.
     */
    public function __construct(
        private readonly ?object $priceRepository = null,
    ) {
    }

    public function assertOrAllow(array $params): array
    {
        $websiteId = $this->resolveWebsiteId($params);
        $storeId = $this->resolveStoreId($params);
        $currency = $this->resolveCurrency($params);
        $offerId = $this->resolveOfferId($websiteId, $params);
        if ($offerId <= 0 || $websiteId < 0 || $storeId < 0 || $currency === '') {
            return ['ok' => true];
        }

        try {
            $this->prices()->assertSellable($websiteId, $storeId, $offerId, $currency);

            return ['ok' => true];
        } catch (CatalogConflictException $exception) {
            if ($exception->errorCode() === 'price_missing') {
                $fallback = $this->fxFallbackSellable($websiteId, $storeId, $offerId, $currency);
                if ($fallback) {
                    return ['ok' => true];
                }
            }

            return [
                'ok' => false,
                'error_code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'detail' => $exception->context(),
            ];
        }
    }

    /**
     * Display currency without an explicit row is sellable when the base
     * currency has a live price and a usable rate exists (rate > 0).
     */
    private function fxFallbackSellable(int $websiteId, int $storeId, int $offerId, string $displayCurrency): bool
    {
        try {
            if (!class_exists(\Weline\Currency\Service\CurrencyRateService::class)) {
                return false;
            }
            /** @var \Weline\Currency\Service\CurrencyRateService $rates */
            $rates = ObjectManager::getInstance(\Weline\Currency\Service\CurrencyRateService::class);
            $baseCurrency = strtoupper(trim($rates->getBaseCurrency())) ?: 'CNY';
            if ($baseCurrency === $displayCurrency) {
                return false;
            }
            $this->prices()->assertSellable($websiteId, $storeId, $offerId, $baseCurrency);
            $converted = $rates->tryConvert(100.0, $baseCurrency, $displayCurrency);

            return $converted !== null && $converted > 0.0;
        } catch (CatalogConflictException) {
            // cleared / missing in the base currency too → not sellable
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function prices(): object
    {
        return $this->priceRepository ?? ObjectManager::getInstance(\Weline\Product\Repository\PriceRepository::class);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveWebsiteId(array $params): int
    {
        if (isset($params['website_id']) && is_numeric($params['website_id'])) {
            return (int)$params['website_id'];
        }
        $fromRequest = RequestContext::getWelineWebsiteId();
        if ($fromRequest >= 0) {
            return $fromRequest;
        }

        return (int)Env::get('website_id', 0);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveStoreId(array $params): int
    {
        if (isset($params['store_id']) && is_numeric($params['store_id'])) {
            return (int)$params['store_id'];
        }
        $fromRequest = RequestContext::getWelineStoreId();
        if ($fromRequest >= 0) {
            return $fromRequest;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveCurrency(array $params): string
    {
        $currency = strtoupper(trim((string)($params['currency'] ?? '')));
        if ($currency !== '') {
            return $currency;
        }
        // Same source as the Cart item snapshot resolver: worker route currency
        // (State::getCurrency() is path/query-only and misses /bin/query calls).
        $fromContext = strtoupper(trim(RequestContext::getWelineUserCurrency()));
        if ($fromContext !== '') {
            return $fromContext;
        }

        return 'CNY';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveOfferId(int $websiteId, array $params): int
    {
        if (isset($params['offer_id']) && is_numeric($params['offer_id']) && (int)$params['offer_id'] > 0) {
            return (int)$params['offer_id'];
        }

        $productId = (int)($params['product_id'] ?? $params['id'] ?? 0);
        if ($productId <= 0 || $websiteId < 0) {
            return 0;
        }

        try {
            /** @var Offer $offer */
            $offer = ObjectManager::create(Offer::class, [], false);
            $row = $offer->forWebsite($websiteId)
                ->reset()
                ->where(Offer::schema_fields_PRODUCT_ID, $productId)
                ->find()
                ->fetch();
            if (!$row instanceof Offer || !(int)$row->getId()) {
                return 0;
            }

            return (int)$row->getData(Offer::schema_fields_ID);
        } catch (\Throwable) {
            return 0;
        }
    }
}
