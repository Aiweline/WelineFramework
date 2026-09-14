<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCategoryPathLocalizerInterface;
use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Websites\Model\Website;

/**
 * Shell-owned publish: ProductAdminCommand CREATE+PUBLISH + listing row; providers never write catalog.
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

        $map = $this->warehouseMap->resolve($provider, $websiteId, $storeId, (string)$snapshot->countryCode);
        $uplift = $this->settings->upliftPercent($storageScope);
        $currency = $this->resolveWebsiteCurrency($websiteId);
        $sale = $this->pricing->saleFromOriginInCurrency(
            $snapshot->originPriceMinor,
            $snapshot->originCurrency,
            $currency,
            $uplift,
        );

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

        $local = $this->createOrReuseLocalProduct($snapshot, $scope, $sale, $existing);
        if (empty($local['product_uuid']) || empty($local['offer_id'])) {
            throw new \RuntimeException('dropship_publish_local_product_required');
        }

        $data = [
            DropshipListing::schema_fields_PROVIDER_CODE => $provider,
            DropshipListing::schema_fields_EXTERNAL_SPU => $snapshot->externalSpu,
            DropshipListing::schema_fields_EXTERNAL_SKU => $snapshot->externalSku,
            DropshipListing::schema_fields_TITLE => $snapshot->title,
            DropshipListing::schema_fields_THUMB_URL => DropshipListingDraftService::firstThumbUrl($snapshot->media),
            DropshipListing::schema_fields_WEBSITE_ID => $websiteId,
            DropshipListing::schema_fields_STORE_ID => $storeId,
            DropshipListing::schema_fields_CHANNEL => $channel,
            DropshipListing::schema_fields_LOCAL_WAREHOUSE_ID => (int)($map['local_warehouse_id'] ?? 0),
            DropshipListing::schema_fields_REMOTE_COUNTRY => $snapshot->countryCode !== '' ? $snapshot->countryCode : DropshipWarehouseMapService::remoteCountryCode($map),
            DropshipListing::schema_fields_REMOTE_CATEGORY_ID => $snapshot->categoryId,
            DropshipListing::schema_fields_REMOTE_CATEGORY_PATH => $snapshot->categoryPath,
            DropshipListing::schema_fields_REMOTE_STORAGE_ID => $snapshot->storageId !== '' ? $snapshot->storageId : DropshipWarehouseMapService::remoteStorageId($map),
            DropshipListing::schema_fields_ORIGIN_PRICE_MINOR => $snapshot->originPriceMinor,
            DropshipListing::schema_fields_ORIGIN_CURRENCY => $snapshot->originCurrency,
            DropshipListing::schema_fields_SALE_PRICE_MINOR => $sale,
            DropshipListing::schema_fields_UPLIFT_PERCENT => $uplift,
            DropshipListing::schema_fields_PRICE_DIRECTION => '',
            DropshipListing::schema_fields_PRICE_DROP_TIP => null,
            DropshipListing::schema_fields_REMOTE_QTY => $snapshot->qty,
            DropshipListing::schema_fields_LOCAL_PRODUCT_UUID => $local['product_uuid'],
            DropshipListing::schema_fields_LOCAL_OFFER_ID => $local['offer_id'],
            DropshipListing::schema_fields_SYNC_STATUS => DropshipListing::STATUS_ACTIVE,
            DropshipListing::schema_fields_LAST_SYNCED_AT => $now,
            DropshipListing::schema_fields_UPDATED_AT => $now,
        ];

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
            'local_product_uuid' => $local['product_uuid'],
            'local_offer_id' => $local['offer_id'],
            'product_type' => $local['product_type'] ?? 'simple',
            'suggested_eav' => $suggestedEav,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{product_uuid:string,offer_id:int,product_type:string}
     */
    private function createOrReuseLocalProduct(
        DropshipCatalogSnapshot $snapshot,
        array $scope,
        int $saleMinor,
        mixed $existing,
    ): array {
        if ($existing && $existing->getId()) {
            $uuid = (string)$existing->getData(DropshipListing::schema_fields_LOCAL_PRODUCT_UUID);
            $offerId = (int)$existing->getData(DropshipListing::schema_fields_LOCAL_OFFER_ID);
            if ($uuid !== '' && $offerId > 0) {
                if (!interface_exists(ProductAdminCommandInterface::class)) {
                    throw new \RuntimeException('dropship_product_admin_unavailable');
                }
                /** @var ProductAdminCommandInterface $cmd */
                $cmd = ObjectManager::getInstance(ProductAdminCommandInterface::class);
                $this->enrichExistingLocalProduct($cmd, $uuid, $snapshot, $scope, $saleMinor);

                return [
                    'product_uuid' => $uuid,
                    'offer_id' => $offerId,
                    'product_type' => $this->detectProductType($snapshot),
                ];
            }
        }

        if (!interface_exists(ProductAdminCommandInterface::class)) {
            throw new \RuntimeException('dropship_product_admin_unavailable');
        }

        /** @var ProductAdminCommandInterface $cmd */
        $cmd = ObjectManager::getInstance(ProductAdminCommandInterface::class);
        $websiteId = (int)$scope['website_id'];
        $storeId = (int)$scope['store_id'];
        $actorId = (int)($scope['actor_id'] ?? 0);
        $currency = $this->resolveWebsiteCurrency($websiteId);
        $productType = $this->detectProductType($snapshot);
        $sku = $snapshot->externalSku !== ''
            ? ('DS-' . strtoupper($snapshot->providerCode) . '-' . $snapshot->externalSku)
            : ('DS-' . strtoupper($snapshot->providerCode) . '-' . $snapshot->externalSpu);
        $sku = substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?? $sku, 0, 120);
        $requestHash = hash('sha256', implode('|', [
            'dropship-publish',
            $snapshot->providerCode,
            $snapshot->externalSpu,
            (string)$websiteId,
            (string)$storeId,
            $productType,
            (string)microtime(true),
        ]));

        $payload = [
            'name' => $snapshot->title !== '' ? $snapshot->title : $sku,
            'sku' => $sku,
            'product_type' => $productType,
            'currency' => $currency,
            'price_minor' => max(0, $saleMinor),
            'stock' => max(0, $snapshot->qty),
            'store_ids' => [$storeId],
            'visibility' => 'catalog_search',
            'short_description' => $snapshot->title !== '' ? $snapshot->title : $sku,
            'attribute_set' => 'dropship',
            'attribute_set_label' => '货源商品',
        ];
        ObjectManager::getInstance(\Weline\Product\Service\ProductCatalogEavBootstrap::class)
            ->ensureDropshipSchema();
        $description = trim($snapshot->description);
        if ($description !== '') {
            $payload['description'] = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($description)) ?? ''), 0, 18000);
        }

        $attributeRows = $this->buildAttributeRows($snapshot);
        if ($attributeRows !== []) {
            $payload['attributes'] = $attributeRows;
        }

        $categoryIds = $this->resolveCategoryIds($websiteId, $snapshot);
        if ($categoryIds !== []) {
            $payload['category_assignments'] = array_map(
                static fn(int $id): array => ['category_id' => $id, 'selected' => true],
                $categoryIds,
            );
        }

        if ($productType === 'configurable') {
            $axes = $this->buildVariantAxes($snapshot);
            if ($axes === []) {
                throw new \RuntimeException('dropship_variant_axes_required');
            }
            $payload['sku_prefix'] = $sku;
            $payload['axes'] = $axes;
        }

        $mediaAssignments = $this->buildMediaAssignments($snapshot);
        if ($mediaAssignments !== []) {
            $payload['media_assignments'] = $mediaAssignments;
        }

        $this->applyShippingDims($payload, $snapshot);

        $create = $cmd->execute(new ProductAdminCommand(
            action: ProductAdminCommand::ACTION_CREATE,
            websiteId: $websiteId,
            globalProductUuid: null,
            expectedVersion: null,
            requestHash: $requestHash,
            actorId: $actorId,
            payload: $payload,
        ));
        if (!$create->success) {
            $detail = trim((string)($create->errorCode ?: $create->message));
            w_log_error('dropship publish product create failed: ' . $detail);
            throw new \RuntimeException('dropship_publish_create_failed:' . ($detail !== '' ? $detail : 'unknown'));
        }

        $productUuid = (string)($create->data['identity']['global_product_uuid'] ?? '');
        $identityVersion = (int)($create->data['identity']['version'] ?? 0);
        $productId = (int)($create->data['product_id'] ?? 0);
        if ($productUuid === '' || $productId <= 0) {
            throw new \RuntimeException('dropship_publish_create_incomplete');
        }

        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class)->forWebsite($websiteId);
        $productRow = $productModel->clear()
            ->where(Product::schema_fields_ID, $productId)
            ->find()
            ->fetch();
        $localVersion = (int)($productRow?->getData(Product::schema_fields_PUBLISH_VERSION) ?? 0);

        $publish = $cmd->execute(new ProductAdminCommand(
            action: ProductAdminCommand::ACTION_PUBLISH,
            websiteId: $websiteId,
            globalProductUuid: $productUuid,
            expectedVersion: $identityVersion > 0 ? $identityVersion : null,
            requestHash: hash('sha256', $requestHash . '|publish'),
            actorId: $actorId,
            payload: [
                'local_version' => $localVersion,
                'store_ids' => [$storeId],
                'currency' => $currency,
                'locale' => 'zh_Hans_CN',
            ],
        ));
        if (!$publish->success) {
            $detail = trim((string)($publish->errorCode ?: $publish->message));
            w_log_error('dropship publish product publish failed: ' . $detail);
            throw new \RuntimeException('dropship_publish_publish_failed:' . ($detail !== '' ? $detail : 'unknown'));
        }

        $offerId = $this->resolveOfferId($websiteId, $productId, $sku);
        if ($offerId === null || $offerId <= 0) {
            throw new \RuntimeException('dropship_publish_offer_missing');
        }
        $this->assertOfferSourceUnique($offerId, $snapshot->providerCode);

        return [
            'product_uuid' => $productUuid,
            'offer_id' => $offerId,
            'product_type' => $productType,
        ];
    }

    /**
     * Re-publish path: SAVE description / attributes / categories onto an existing local product.
     *
     * @param array{website_id:int,store_id:int,channel?:string,storage_scope?:string,actor_id?:int} $scope
     */
    private function enrichExistingLocalProduct(
        ProductAdminCommandInterface $cmd,
        string $productUuid,
        DropshipCatalogSnapshot $snapshot,
        array $scope,
        int $saleMinor,
    ): void {
        $websiteId = (int)$scope['website_id'];
        $storeId = (int)$scope['store_id'];
        $actorId = (int)($scope['actor_id'] ?? 0);

        /** @var \Weline\Product\Service\ProductIdentityV2Service $identities */
        $identities = ObjectManager::getInstance(\Weline\Product\Service\ProductIdentityV2Service::class);
        $identity = $identities->resolveProductByUuid($productUuid);
        if ($identity === null) {
            return;
        }

        $payload = [];
        ObjectManager::getInstance(\Weline\Product\Service\ProductCatalogEavBootstrap::class)
            ->ensureDropshipSchema();
        $description = trim($snapshot->description);
        if ($description !== '') {
            $payload['description'] = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($description)) ?? ''), 0, 18000);
        }
        $attributeRows = $this->buildAttributeRows($snapshot);
        $attributeRows[] = [
            'attribute_code' => 'attribute_set',
            'value' => 'dropship',
            'scope_state' => 'explicit',
            'store_id' => 0,
            'locale' => '',
        ];
        $attributeRows[] = [
            'attribute_code' => 'attribute_set_label',
            'value' => '货源商品',
            'scope_state' => 'explicit',
            'store_id' => 0,
            'locale' => '',
        ];
        $payload['attributes'] = $attributeRows;
        $categoryIds = $this->resolveCategoryIds($websiteId, $snapshot);
        if ($categoryIds !== []) {
            $payload['category_assignments'] = array_map(
                static fn(int $id): array => ['category_id' => $id, 'selected' => true],
                $categoryIds,
            );
        }
        $this->applyShippingDims($payload, $snapshot);
        if ($payload === []) {
            return;
        }

        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class)->forWebsite($websiteId);
        $productRow = $productModel->clear()
            ->where(Product::schema_fields_GLOBAL_PRODUCT_UUID, $productUuid)
            ->find()
            ->fetch();
        $localVersion = (int)($productRow?->getData(Product::schema_fields_PUBLISH_VERSION) ?? 0);
        $payload['local_version'] = $localVersion;
        $payload['store_ids'] = [$storeId];
        if ($saleMinor > 0) {
            $payload['price_minor'] = $saleMinor;
            $payload['currency'] = $this->resolveWebsiteCurrency($websiteId);
        }

        $save = $cmd->execute(new ProductAdminCommand(
            action: ProductAdminCommand::ACTION_SAVE,
            websiteId: $websiteId,
            globalProductUuid: $productUuid,
            expectedVersion: (int)$identity->version,
            requestHash: hash('sha256', implode('|', [
                'dropship-enrich',
                $snapshot->providerCode,
                $snapshot->externalSpu,
                $productUuid,
                (string)microtime(true),
            ])),
            actorId: $actorId,
            payload: $payload,
        ));
        if (!$save->success) {
            $detail = trim((string)($save->errorCode ?: $save->message));
            w_log_error('dropship publish product enrich failed: ' . $detail);
            throw new \RuntimeException('dropship_publish_enrich_failed:' . ($detail !== '' ? $detail : 'unknown'));
        }
    }

    private function detectProductType(DropshipCatalogSnapshot $snapshot): string
    {
        return $this->buildVariantAxes($snapshot) !== [] ? 'configurable' : 'simple';
    }

    /**
     * @return list<array{code:string,label:string,options:list<array{value:string,label:string}>}>
     */
    private function buildVariantAxes(DropshipCatalogSnapshot $snapshot): array
    {
        $variants = $snapshot->variants;
        if ($variants === []) {
            return [];
        }

        if (isset($variants['axes']) && is_array($variants['axes']) && $variants['axes'] !== []) {
            $axes = [];
            foreach ($variants['axes'] as $axis) {
                if (!is_array($axis)) {
                    continue;
                }
                $code = strtolower(trim((string)($axis['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $options = [];
                foreach (is_array($axis['options'] ?? null) ? $axis['options'] : [] as $option) {
                    if (is_array($option)) {
                        $value = trim((string)($option['value'] ?? $option['code'] ?? ''));
                        $label = trim((string)($option['label'] ?? $option['name'] ?? $value));
                    } else {
                        $value = trim((string)$option);
                        $label = $value;
                    }
                    if ($value === '') {
                        continue;
                    }
                    $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
                }
                if ($options === []) {
                    continue;
                }
                $axes[] = [
                    'code' => $code,
                    'label' => trim((string)($axis['label'] ?? $code)),
                    'options' => $options,
                ];
            }

            return $axes;
        }

        // Flat list of variant rows with attrs map → derive axes.
        $axisOptions = [];
        $axisLabels = [];
        $rows = $this->variantOfferRows($snapshot);
        foreach ($rows as $row) {
            $attrs = is_array($row['combination'] ?? null) ? $row['combination'] : [];
            foreach ($attrs as $code => $value) {
                $code = strtolower(trim((string)$code));
                $value = trim((string)$value);
                if ($code === '' || $value === '') {
                    continue;
                }
                $axisLabels[$code] = $axisLabels[$code] ?? $code;
                $axisOptions[$code][$value] = $value;
            }
        }
        $axes = [];
        foreach ($axisOptions as $code => $values) {
            $options = [];
            foreach (array_values($values) as $value) {
                $options[] = ['value' => $value, 'label' => $value];
            }
            if ($options === []) {
                continue;
            }
            $axes[] = [
                'code' => $code,
                'label' => (string)$axisLabels[$code],
                'options' => $options,
            ];
        }

        return $axes;
    }

    /**
     * @return array<string,string> combination_key => sku
     */
    private function buildSkuOverrides(DropshipCatalogSnapshot $snapshot, string $skuPrefix): array
    {
        $overrides = [];
        foreach ($this->variantOfferRows($snapshot) as $row) {
            $combination = is_array($row['combination'] ?? null) ? $row['combination'] : [];
            $rowSku = trim((string)($row['sku'] ?? ''));
            if ($combination === [] || $rowSku === '') {
                continue;
            }
            ksort($combination, SORT_STRING);
            $parts = [];
            foreach ($combination as $code => $value) {
                $parts[] = strtolower(trim((string)$code)) . '=' . trim((string)$value);
            }
            if ($parts === []) {
                continue;
            }
            $key = implode('&', $parts);
            $safeSku = substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', $rowSku) ?? $rowSku, 0, 120);
            $overrides[$key] = str_starts_with($safeSku, $skuPrefix) ? $safeSku : ($skuPrefix . '-' . $safeSku);
        }

        return $overrides;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function variantOfferRows(DropshipCatalogSnapshot $snapshot): array
    {
        $variants = $snapshot->variants;
        if (isset($variants['offers']) && is_array($variants['offers'])) {
            $rows = [];
            foreach ($variants['offers'] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }
        if (array_is_list($variants)) {
            $rows = [];
            foreach ($variants as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $attrs = is_array($row['attrs'] ?? null)
                    ? $row['attrs']
                    : (is_array($row['attributes'] ?? null) ? $row['attributes'] : (is_array($row['combination'] ?? null) ? $row['combination'] : []));
                $rows[] = [
                    'sku' => (string)($row['sku'] ?? ''),
                    'combination' => $attrs,
                    'image_url' => (string)($row['image_url'] ?? $row['image'] ?? ''),
                    'price_minor' => (int)($row['price_minor'] ?? 0),
                    'qty' => (int)($row['qty'] ?? 0),
                ];
            }

            return $rows;
        }

        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildMediaAssignments(DropshipCatalogSnapshot $snapshot): array
    {
        $urls = [];
        foreach ($snapshot->media as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        foreach ($this->variantOfferRows($snapshot) as $row) {
            $url = trim((string)($row['image_url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        if ($urls === []) {
            return [];
        }

        /** @var DropshipRemoteMediaImporter $importer */
        $importer = ObjectManager::getInstance(DropshipRemoteMediaImporter::class);
        $assetByUrl = $importer->importUrls(
            $snapshot->providerCode,
            $snapshot->externalSpu,
            $snapshot->title !== '' ? $snapshot->title : $snapshot->externalSpu,
            $urls,
        );
        if ($assetByUrl === []) {
            return [];
        }

        $assignments = [];
        $position = 0;
        $mainSet = false;
        $usedAssets = [];
        foreach ($snapshot->media as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? ''));
            $assetId = $assetByUrl[$url] ?? '';
            if ($assetId === '' || isset($usedAssets[$assetId])) {
                continue;
            }
            $role = strtolower(trim((string)($item['role'] ?? 'gallery')));
            if ($role === 'thumb' || $role === 'main') {
                $role = $mainSet ? 'gallery' : 'main';
            } elseif (!in_array($role, ['main', 'gallery'], true)) {
                $role = 'gallery';
            }
            if ($role === 'main' && $mainSet) {
                $role = 'gallery';
            }
            if ($role === 'main') {
                $mainSet = true;
            }
            $assignments[] = [
                'asset_id' => $assetId,
                'role' => $role,
                'position' => $position++,
            ];
            $usedAssets[$assetId] = true;
        }
        if (!$mainSet && $assignments !== []) {
            $assignments[0]['role'] = 'main';
            $mainSet = true;
        }

        foreach ($this->variantOfferRows($snapshot) as $row) {
            $url = trim((string)($row['image_url'] ?? ''));
            $assetId = $assetByUrl[$url] ?? '';
            $combination = is_array($row['combination'] ?? null) ? $row['combination'] : [];
            if ($assetId === '' || $combination === []) {
                continue;
            }
            $assignments[] = [
                'asset_id' => $assetId,
                'role' => 'variant',
                'combination' => $combination,
                'position' => $position++,
            ];
        }

        return $assignments;
    }

    /**
     * Map provider-normalized shipping dims onto ProductAdmin payload (kg / cm).
     *
     * @param array<string, mixed> $payload
     */
    private function applyShippingDims(array &$payload, DropshipCatalogSnapshot $snapshot): void
    {
        $shipping = DropshipCatalogSnapshot::normalizeShipping($snapshot->shipping);
        if ($shipping['weight_kg'] > 0) {
            $payload['weight'] = $shipping['weight_kg'];
        }
        if ($shipping['length_cm'] > 0) {
            $payload['length'] = $shipping['length_cm'];
        }
        if ($shipping['width_cm'] > 0) {
            $payload['width'] = $shipping['width_cm'];
        }
        if ($shipping['height_cm'] > 0) {
            $payload['height'] = $shipping['height_cm'];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildAttributeRows(DropshipCatalogSnapshot $snapshot): array
    {
        $rows = [];
        foreach ($snapshot->attributes as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['attribute_code'] ?? $row['code'] ?? ''));
            if ($code === '' || str_starts_with($code, 'dropship_')) {
                continue;
            }
            $rows[] = [
                'attribute_code' => $code,
                'value' => $row['value'] ?? null,
                'scope_state' => (string)($row['scope_state'] ?? 'explicit'),
                'store_id' => (int)($row['store_id'] ?? 0),
                'locale' => (string)($row['locale'] ?? ''),
            ];
        }
        // Legacy suggested_eav: only non-dropship_* keys that look like product attributes.
        foreach ($snapshot->suggestedEav as $code => $value) {
            $code = trim((string)$code);
            if ($code === '' || str_starts_with($code, 'dropship_')) {
                continue;
            }
            $already = false;
            foreach ($rows as $existing) {
                if (($existing['attribute_code'] ?? '') === $code) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }
            $rows[] = [
                'attribute_code' => $code,
                'value' => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
                'scope_state' => 'explicit',
                'store_id' => 0,
                'locale' => '',
            ];
        }

        $hasSource = false;
        foreach ($rows as $existing) {
            if (($existing['attribute_code'] ?? '') === 'source_platform') {
                $hasSource = true;
                break;
            }
        }
        if (!$hasSource && $snapshot->providerCode !== '') {
            $rows[] = [
                'attribute_code' => 'source_platform',
                'value' => strtolower($snapshot->providerCode),
                'scope_state' => 'explicit',
                'store_id' => 0,
                'locale' => '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function resolveCategoryIds(int $websiteId, DropshipCatalogSnapshot $snapshot): array
    {
        /** @var DropshipCategoryEnsureService $ensure */
        $ensure = ObjectManager::getInstance(DropshipCategoryEnsureService::class);
        $path = trim($snapshot->categoryPath);
        if ($path === '') {
            $path = trim($snapshot->categoryId);
        }
        $locale = 'zh_Hans_CN';
        try {
            /** @var DropshipChannelManager $channels */
            $channels = ObjectManager::getInstance(DropshipChannelManager::class);
            $provider = $channels->getProvider($snapshot->providerCode);
            if ($provider instanceof DropshipCategoryPathLocalizerInterface) {
                $localized = trim($provider->localizeCategoryPath($path, $locale));
                if ($localized !== '') {
                    $path = $localized;
                }
            }
        } catch (\Throwable $e) {
            w_log_warning('dropship localize category path failed: ' . $e->getMessage());
        }

        return $ensure->ensureFromRemotePath($websiteId, $snapshot->providerCode, $path, $locale);
    }

    private function resolveWebsiteCurrency(int $websiteId): string
    {
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $row = $website->clear()->where(Website::schema_fields_ID, $websiteId)->find()->fetch();
            if ($row && $row->getId()) {
                $code = strtoupper(trim((string)($row->getDefaultCurrency() ?? '')));
                if ($code !== '') {
                    return $code;
                }
                $codes = $row->getCurrencyCodes();
                if ($codes !== []) {
                    return strtoupper(trim((string)$codes[0]));
                }
            }
        } catch (\Throwable $e) {
            w_log_error('dropship resolve website currency failed: ' . $e->getMessage());
        }

        return 'CNY';
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
