<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Model\Shard\Offer;

/**
 * Shell-owned publish: ProductAdminCommand + listing row; providers never write catalog.
 */
class DropshipPublishService
{
    public function __construct(
        private readonly DropshipPricingService $pricing,
        private readonly DropshipSettings $settings,
        private readonly DropshipWarehouseMapService $warehouseMap,
    ) {
    }

    /**
     * @param array{website_id:int,store_id:int,channel?:string,storage_scope?:string,actor_id?:int} $scope
     * @return array<string, mixed>
     */
    public function publish(DropshipCatalogSnapshot $snapshot, array $scope): array
    {
        $websiteId = (int)$scope['website_id'];
        $storeId = (int)$scope['store_id'];
        $channel = (string)($scope['channel'] ?? 'default');
        $storageScope = (string)($scope['storage_scope'] ?? 'default.default.default');
        $provider = $snapshot->providerCode;

        if (!$this->settings->isPlatformEnabled($provider, $storageScope)) {
            throw new \RuntimeException('dropship_platform_not_enabled:' . $provider);
        }

        $map = $this->warehouseMap->resolve($provider, $websiteId, $storeId);
        $uplift = $this->settings->upliftPercent($storageScope);
        $sale = $this->pricing->saleFromOrigin($snapshot->originPriceMinor, $uplift);

        /** @var DropshipListing $listing */
        $listing = ObjectManager::getInstance(DropshipListing::class);
        $existing = $listing->clear()
            ->where(DropshipListing::schema_fields_PROVIDER_CODE, $provider)
            ->where(DropshipListing::schema_fields_EXTERNAL_SPU, $snapshot->externalSpu)
            ->where(DropshipListing::schema_fields_WEBSITE_ID, $websiteId)
            ->where(DropshipListing::schema_fields_STORE_ID, $storeId)
            ->where(DropshipListing::schema_fields_CHANNEL, $channel)
            ->find()
            ->fetch();

        $now = date('Y-m-d H:i:s');
        $suggestedEav = array_merge([
            'dropship_source' => $provider,
            'dropship_price_direction' => '',
            'dropship_price_drop_tip' => '',
        ], $snapshot->suggestedEav);

        $local = $this->createOrReuseLocalProduct($snapshot, $scope, $sale, $suggestedEav, $existing);

        $data = [
            DropshipListing::schema_fields_PROVIDER_CODE => $provider,
            DropshipListing::schema_fields_EXTERNAL_SPU => $snapshot->externalSpu,
            DropshipListing::schema_fields_EXTERNAL_SKU => $snapshot->externalSku,
            DropshipListing::schema_fields_WEBSITE_ID => $websiteId,
            DropshipListing::schema_fields_STORE_ID => $storeId,
            DropshipListing::schema_fields_CHANNEL => $channel,
            DropshipListing::schema_fields_LOCAL_WAREHOUSE_ID => (int)($map['local_warehouse_id'] ?? 0),
            DropshipListing::schema_fields_REMOTE_COUNTRY => $snapshot->countryCode !== '' ? $snapshot->countryCode : (string)($map['cj_country_code'] ?? ''),
            DropshipListing::schema_fields_REMOTE_STORAGE_ID => $snapshot->storageId !== '' ? $snapshot->storageId : (string)($map['cj_storage_id'] ?? ''),
            DropshipListing::schema_fields_ORIGIN_PRICE_MINOR => $snapshot->originPriceMinor,
            DropshipListing::schema_fields_ORIGIN_CURRENCY => $snapshot->originCurrency,
            DropshipListing::schema_fields_SALE_PRICE_MINOR => $sale,
            DropshipListing::schema_fields_UPLIFT_PERCENT => $uplift,
            DropshipListing::schema_fields_PRICE_DIRECTION => '',
            DropshipListing::schema_fields_PRICE_DROP_TIP => null,
            DropshipListing::schema_fields_REMOTE_QTY => $snapshot->qty,
            DropshipListing::schema_fields_SYNC_STATUS => DropshipListing::STATUS_ACTIVE,
            DropshipListing::schema_fields_LAST_SYNCED_AT => $now,
            DropshipListing::schema_fields_UPDATED_AT => $now,
        ];
        if (!empty($local['product_uuid'])) {
            $data[DropshipListing::schema_fields_LOCAL_PRODUCT_UUID] = $local['product_uuid'];
        }
        if (!empty($local['offer_id'])) {
            $data[DropshipListing::schema_fields_LOCAL_OFFER_ID] = $local['offer_id'];
        }

        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();
            $listingId = (int)$existing->getId();
        } else {
            $data[DropshipListing::schema_fields_CREATED_AT] = $now;
            $listing->clear()->setData($data)->save();
            $listingId = (int)$listing->getId();
        }

        return [
            'listing_id' => $listingId,
            'sale_price_minor' => $sale,
            'uplift_percent' => $uplift,
            'dropship_source' => $provider,
            'local_product_uuid' => $data[DropshipListing::schema_fields_LOCAL_PRODUCT_UUID] ?? null,
            'local_offer_id' => $data[DropshipListing::schema_fields_LOCAL_OFFER_ID] ?? null,
            'suggested_eav' => $suggestedEav,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $suggestedEav
     * @return array{product_uuid:?string,offer_id:?int}
     */
    private function createOrReuseLocalProduct(
        DropshipCatalogSnapshot $snapshot,
        array $scope,
        int $saleMinor,
        array $suggestedEav,
        mixed $existing,
    ): array {
        if ($existing && $existing->getId()) {
            $uuid = (string)$existing->getData(DropshipListing::schema_fields_LOCAL_PRODUCT_UUID);
            $offerId = (int)$existing->getData(DropshipListing::schema_fields_LOCAL_OFFER_ID);
            if ($uuid !== '' || $offerId > 0) {
                return ['product_uuid' => $uuid !== '' ? $uuid : null, 'offer_id' => $offerId > 0 ? $offerId : null];
            }
        }

        try {
            if (!interface_exists(ProductAdminCommandInterface::class)) {
                return ['product_uuid' => null, 'offer_id' => null];
            }
            /** @var ProductAdminCommandInterface $cmd */
            $cmd = ObjectManager::getInstance(ProductAdminCommandInterface::class);
            $websiteId = (int)$scope['website_id'];
            $storeId = (int)$scope['store_id'];
            $sku = $snapshot->externalSku !== ''
                ? ('DS-' . strtoupper($snapshot->providerCode) . '-' . $snapshot->externalSku)
                : ('DS-' . strtoupper($snapshot->providerCode) . '-' . $snapshot->externalSpu);
            $requestHash = hash('sha256', implode('|', [
                'dropship-publish',
                $snapshot->providerCode,
                $snapshot->externalSpu,
                (string)$websiteId,
                (string)$storeId,
                (string)microtime(true),
            ]));
            $attributes = [];
            foreach ($suggestedEav as $k => $v) {
                $attributes[] = ['code' => (string)$k, 'value' => is_scalar($v) ? (string)$v : json_encode($v)];
            }
            $result = $cmd->execute(new ProductAdminCommand(
                action: ProductAdminCommand::ACTION_CREATE,
                websiteId: $websiteId,
                globalProductUuid: null,
                expectedVersion: null,
                requestHash: $requestHash,
                actorId: (int)($scope['actor_id'] ?? 0),
                payload: [
                    'name' => $snapshot->title !== '' ? $snapshot->title : $sku,
                    'sku' => $sku,
                    'product_type' => 'simple',
                    'currency' => $snapshot->originCurrency !== '' ? $snapshot->originCurrency : 'USD',
                    'price_minor' => $saleMinor,
                    'stock' => max(0, $snapshot->qty),
                    'store_ids' => [$storeId],
                    'attributes' => $attributes,
                ],
            ));
            if (!$result->success) {
                w_log_error('dropship publish product failed: ' . $result->message);

                return ['product_uuid' => null, 'offer_id' => null];
            }
            $productUuid = (string)($result->data['identity']['global_product_uuid'] ?? '');
            $productId = (int)($result->data['product_id'] ?? 0);
            $offerId = $this->resolveOfferId($websiteId, $productId, $sku);
            if ($offerId !== null) {
                $this->assertOfferSourceUnique($offerId, $snapshot->providerCode);
            }

            return [
                'product_uuid' => $productUuid !== '' ? $productUuid : null,
                'offer_id' => $offerId,
            ];
        } catch (\Throwable $e) {
            w_log_error('dropship publish product exception: ' . $e->getMessage());

            return ['product_uuid' => null, 'offer_id' => null];
        }
    }

    private function resolveOfferId(int $websiteId, int $productId, string $sku): ?int
    {
        if ($productId <= 0) {
            return null;
        }
        try {
            /** @var Offer $offer */
            $offer = ObjectManager::getInstance(Offer::class)->forWebsite($websiteId);
            $row = $offer->clear()
                ->where(Offer::schema_fields_PRODUCT_ID, $productId)
                ->find()
                ->fetch();
            if ($row && $row->getId()) {
                return (int)$row->getId();
            }
            $bySku = $offer->clear()->where(Offer::schema_fields_SKU, $sku)->find()->fetch();
            if ($bySku && $bySku->getId()) {
                return (int)$bySku->getId();
            }
        } catch (\Throwable $e) {
            w_log_error('dropship resolve offer failed: ' . $e->getMessage());
        }

        return null;
    }

    private function assertOfferSourceUnique(int $offerId, string $providerCode): void
    {
        /** @var DropshipListing $listing */
        $listing = ObjectManager::getInstance(DropshipListing::class);
        $other = $listing->clear()
            ->where(DropshipListing::schema_fields_LOCAL_OFFER_ID, $offerId)
            ->find()
            ->fetch();
        if ($other && $other->getId()) {
            $existingProvider = (string)$other->getData(DropshipListing::schema_fields_PROVIDER_CODE);
            if ($existingProvider !== '' && $existingProvider !== $providerCode) {
                throw new \RuntimeException('dropship_offer_source_conflict:' . $existingProvider);
            }
        }
    }
}
