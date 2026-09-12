<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductAdminReadInterface;

/**
 * Project local ProductAdmin snapshot into a slim listed-row accordion payload.
 * Shell-only: never reads supplier raw APIs.
 */
class DropshipListedLocalDetailService
{
    /**
     * @param array<string, mixed> $listingRow
     * @return array<string, mixed>
     */
    public function build(array $listingRow): array
    {
        $listingId = (int)($listingRow[DropshipListing::schema_fields_ID] ?? $listingRow['listing_id'] ?? 0);
        $websiteId = (int)($listingRow[DropshipListing::schema_fields_WEBSITE_ID] ?? 0);
        $storeId = (int)($listingRow[DropshipListing::schema_fields_STORE_ID] ?? 0);
        $uuid = trim((string)($listingRow[DropshipListing::schema_fields_LOCAL_PRODUCT_UUID] ?? ''));
        $originMinor = (int)($listingRow[DropshipListing::schema_fields_ORIGIN_PRICE_MINOR] ?? 0);
        $originPrevMinor = (int)($listingRow[DropshipListing::schema_fields_ORIGIN_PRICE_PREV_MINOR] ?? 0);
        $originCurrency = strtoupper(trim((string)($listingRow[DropshipListing::schema_fields_ORIGIN_CURRENCY] ?? 'USD'))) ?: 'USD';
        $saleMinor = (int)($listingRow[DropshipListing::schema_fields_SALE_PRICE_MINOR] ?? 0);
        $uplift = (int)($listingRow[DropshipListing::schema_fields_UPLIFT_PERCENT] ?? 0);
        $priceDirection = trim((string)($listingRow[DropshipListing::schema_fields_PRICE_DIRECTION] ?? ''));
        $priceDropTip = trim((string)($listingRow[DropshipListing::schema_fields_PRICE_DROP_TIP] ?? ''));
        $priceLock = (int)($listingRow[DropshipListing::schema_fields_PRICE_LOCK] ?? 0) === 1;
        $lastSyncedAt = trim((string)($listingRow[DropshipListing::schema_fields_LAST_SYNCED_AT] ?? ''));

        if ($listingId <= 0) {
            return ['ok' => false, 'message' => 'listing_required'];
        }
        if ($uuid === '') {
            return [
                'ok' => true,
                'listing_id' => $listingId,
                'has_local' => false,
                'message' => 'local_product_missing',
                'origin' => [
                    'amount_minor' => $originMinor,
                    'currency' => $originCurrency,
                    'prev_minor' => $originPrevMinor,
                ],
                'sale' => $this->buildSalePayload($saleMinor, 'CNY', $uplift, $originCurrency),
                'economics' => $this->buildEconomics(
                    $originMinor,
                    $originPrevMinor,
                    $originCurrency,
                    $saleMinor,
                    'CNY',
                    $priceDirection,
                ),
                'ops' => $this->buildOps($priceLock, $lastSyncedAt, $priceDropTip),
                'product' => null,
                'variants' => [],
            ];
        }

        if (!interface_exists(ProductAdminReadInterface::class)) {
            return ['ok' => false, 'message' => 'product_admin_read_unavailable'];
        }

        /** @var ProductAdminReadInterface $reader */
        $reader = ObjectManager::getInstance(ProductAdminReadInterface::class);
        try {
            $snap = $reader->snapshot(
                $websiteId,
                $uuid,
                $storeId > 0 ? $storeId : null,
                '',
                'CNY',
            )->toArray();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'listing_id' => $listingId,
                'message' => 'local_snapshot_failed',
                'detail' => $e->getMessage(),
            ];
        }

        $product = is_array($snap['product'] ?? null) ? $snap['product'] : [];
        $matrix = is_array($snap['offer_matrix'] ?? null) ? $snap['offer_matrix'] : [];
        $productType = strtolower(trim((string)($product['product_type'] ?? $snap['identity']['product_type'] ?? 'simple')));
        $currency = strtoupper(trim((string)($matrix['currency'] ?? 'CNY'))) ?: 'CNY';

        $variants = [];
        if (!empty($matrix['enabled']) && is_array($matrix['rows'] ?? null)) {
            foreach ($matrix['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $variants[] = $this->projectVariantRow($row, $currency, $originCurrency);
            }
        } elseif (is_array($snap['offers'] ?? null)) {
            $priceByOffer = [];
            foreach (is_array($snap['prices'] ?? null) ? $snap['prices'] : [] as $price) {
                if (!is_array($price)) {
                    continue;
                }
                if ((int)($price['store_id'] ?? 0) !== 0) {
                    continue;
                }
                if (strtoupper((string)($price['currency'] ?? '')) !== $currency) {
                    continue;
                }
                $priceByOffer[(int)($price['offer_id'] ?? 0)] = (int)($price['amount_minor'] ?? 0);
            }
            foreach ($snap['offers'] as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $oid = (int)($offer['offer_id'] ?? 0);
                $sku = trim((string)($offer['sku'] ?? ''));
                $variants[] = $this->withSaleCompare([
                    'offer_id' => $oid,
                    'sku' => $sku,
                    'label' => (string)__('默认规格'),
                    'combination' => [],
                    'amount_minor' => $priceByOffer[$oid] ?? $saleMinor,
                    'currency' => $currency,
                    'status' => trim((string)($offer['status'] ?? '')),
                    'image_url' => '',
                ], $originCurrency);
            }
        }

        if ($variants === [] && $saleMinor > 0) {
            $variants[] = $this->withSaleCompare([
                'offer_id' => (int)($listingRow[DropshipListing::schema_fields_LOCAL_OFFER_ID] ?? 0),
                'sku' => '',
                'label' => (string)__('默认规格'),
                'combination' => [],
                'amount_minor' => $saleMinor,
                'currency' => $currency,
                'status' => 'published',
                'image_url' => '',
            ], $originCurrency);
        }

        $resolvedSale = $saleMinor > 0 ? $saleMinor : (int)($variants[0]['amount_minor'] ?? 0);

        return [
            'ok' => true,
            'listing_id' => $listingId,
            'has_local' => true,
            'product' => [
                'global_product_uuid' => $uuid,
                'product_id' => (int)($product['product_id'] ?? $product['id'] ?? 0),
                'product_type' => $productType,
                'sku' => trim((string)($product['sku'] ?? '')),
                'status' => trim((string)($product['status'] ?? '')),
            ],
            'origin' => [
                'amount_minor' => $originMinor,
                'currency' => $originCurrency,
                'prev_minor' => $originPrevMinor,
            ],
            'sale' => $this->buildSalePayload($resolvedSale, $currency, $uplift, $originCurrency),
            'economics' => $this->buildEconomics(
                $originMinor,
                $originPrevMinor,
                $originCurrency,
                $resolvedSale,
                $currency,
                $priceDirection,
            ),
            'ops' => $this->buildOps($priceLock, $lastSyncedAt, $priceDropTip),
            'is_configurable' => $productType === 'configurable' || count($variants) > 1,
            'variants' => $variants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSalePayload(int $saleMinor, string $saleCurrency, int $uplift, string $originCurrency): array
    {
        $sale = [
            'amount_minor' => $saleMinor,
            'currency' => $saleCurrency,
            'uplift_percent' => $uplift,
        ];
        $compare = (new DropshipPricingService())->saleCompareInOriginCurrency(
            $saleMinor,
            $saleCurrency,
            $originCurrency,
        );
        if ($compare !== null) {
            $sale['compare_amount_minor'] = $compare['compare_amount_minor'];
            $sale['compare_currency'] = $compare['compare_currency'];
        }

        return $sale;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withSaleCompare(array $row, string $originCurrency): array
    {
        $compare = (new DropshipPricingService())->saleCompareInOriginCurrency(
            (int)($row['amount_minor'] ?? 0),
            (string)($row['currency'] ?? 'CNY'),
            $originCurrency,
        );
        if ($compare !== null) {
            $row['compare_amount_minor'] = $compare['compare_amount_minor'];
            $row['compare_currency'] = $compare['compare_currency'];
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEconomics(
        int $originMinor,
        int $originPrevMinor,
        string $originCurrency,
        int $saleMinor,
        string $saleCurrency,
        string $priceDirection,
    ): array {
        return (new DropshipPricingService())->economicsSnapshot(
            $originMinor,
            $originPrevMinor,
            $originCurrency,
            $saleMinor,
            $saleCurrency,
            $priceDirection,
        );
    }

    /**
     * @return array{price_lock:bool,last_synced_at:string,price_drop_tip:string}
     */
    private function buildOps(bool $priceLock, string $lastSyncedAt, string $priceDropTip): array
    {
        return [
            'price_lock' => $priceLock,
            'last_synced_at' => $lastSyncedAt,
            'price_drop_tip' => $priceDropTip,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectVariantRow(array $row, string $currency, string $originCurrency = 'USD'): array
    {
        $combination = is_array($row['combination'] ?? null) ? $row['combination'] : [];
        $parts = [];
        foreach ($combination as $axis => $value) {
            $v = trim((string)$value);
            if ($v === '') {
                continue;
            }
            $parts[] = $v;
        }
        $label = $parts !== [] ? implode(' · ', $parts) : trim((string)($row['sku'] ?? ''));
        if ($label === '') {
            $label = (string)__('规格');
        }

        return $this->withSaleCompare([
            'offer_id' => (int)($row['offer_id'] ?? 0),
            'sku' => trim((string)($row['sku'] ?? '')),
            'label' => $label,
            'combination' => $combination,
            'amount_minor' => (int)($row['amount_minor'] ?? 0),
            'currency' => strtoupper(trim((string)($row['currency'] ?? $currency))) ?: $currency,
            'status' => trim((string)($row['status'] ?? '')),
            'image_url' => trim((string)($row['image_url'] ?? '')),
        ], $originCurrency);
    }
}
