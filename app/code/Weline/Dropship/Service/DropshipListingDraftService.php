<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Model\DropshipScopeWarehouseMap;
use Weline\Framework\Manager\ObjectManager;

/**
 * Shell-owned draft basket: pending listings at website_id=0 / store_id=0.
 */
class DropshipListingDraftService
{
    public function __construct(
        private readonly DropshipSettings $settings,
        private readonly DropshipWarehouseMapService $warehouseMap,
    ) {
    }

    /**
     * @param list<array<string, mixed>|DropshipCatalogSnapshot> $snapshots
     * @param array{country_code?:string,category_id?:string,category_path?:string,storage_scope?:string} $context
     * @return array{ok:bool,admitted:int,listing_ids:list<int>,error?:string}
     */
    public function admit(string $providerCode, array $snapshots, array $context = []): array
    {
        $providerCode = trim($providerCode);
        if ($providerCode === '') {
            return ['ok' => false, 'admitted' => 0, 'listing_ids' => [], 'error' => 'provider_code_required'];
        }
        if ($snapshots === []) {
            return ['ok' => false, 'admitted' => 0, 'listing_ids' => [], 'error' => 'snapshots_required'];
        }

        $storageScope = (string)($context['storage_scope'] ?? 'default.default.default');
        if (!$this->settings->isPlatformEnabled($providerCode, $storageScope)) {
            return ['ok' => false, 'admitted' => 0, 'listing_ids' => [], 'error' => 'dropship_platform_not_enabled:' . $providerCode];
        }

        $ctxCountry = strtoupper(trim((string)($context['country_code'] ?? '')));
        $ctxCategoryId = trim((string)($context['category_id'] ?? ''));
        $ctxCategoryPath = trim((string)($context['category_path'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $listingIds = [];
        $admitted = 0;

        foreach ($snapshots as $raw) {
            $snapshot = $raw instanceof DropshipCatalogSnapshot
                ? $raw
                : DropshipCatalogSnapshot::fromArray(is_array($raw) ? $raw : []);
            if ($snapshot->providerCode !== $providerCode) {
                return [
                    'ok' => false,
                    'admitted' => $admitted,
                    'listing_ids' => $listingIds,
                    'error' => 'dropship_snapshot_provider_mismatch',
                ];
            }
            if (trim($snapshot->externalSpu) === '') {
                continue;
            }

            $country = $snapshot->countryCode !== '' ? $snapshot->countryCode : $ctxCountry;
            $categoryId = $snapshot->categoryId !== '' ? $snapshot->categoryId : $ctxCategoryId;
            $categoryPath = $snapshot->categoryPath !== '' ? $snapshot->categoryPath : $ctxCategoryPath;
            $localWarehouseId = 0;
            if ($country !== '') {
                try {
                    $map = $this->warehouseMap->resolve($providerCode, 0, 0, $country);
                    $localWarehouseId = (int)($map[DropshipScopeWarehouseMap::schema_fields_LOCAL_WAREHOUSE_ID] ?? 0);
                } catch (\Throwable) {
                    $localWarehouseId = 0;
                }
            }

            /** @var DropshipListing $listing */
            $listing = ObjectManager::getInstance(DropshipListing::class);
            $existing = $listing->clear()
                ->where(DropshipListing::schema_fields_PROVIDER_CODE, $providerCode)
                ->where(DropshipListing::schema_fields_EXTERNAL_SPU, $snapshot->externalSpu)
                ->where(DropshipListing::schema_fields_WEBSITE_ID, 0)
                ->where(DropshipListing::schema_fields_STORE_ID, 0)
                ->where(DropshipListing::schema_fields_CHANNEL, 'default')
                ->find()
                ->fetch();

            $data = [
                DropshipListing::schema_fields_PROVIDER_CODE => $providerCode,
                DropshipListing::schema_fields_EXTERNAL_SPU => $snapshot->externalSpu,
                DropshipListing::schema_fields_EXTERNAL_SKU => $snapshot->externalSku,
                DropshipListing::schema_fields_TITLE => $snapshot->title,
                DropshipListing::schema_fields_THUMB_URL => self::firstThumbUrl($snapshot->media),
                DropshipListing::schema_fields_WEBSITE_ID => 0,
                DropshipListing::schema_fields_STORE_ID => 0,
                DropshipListing::schema_fields_CHANNEL => 'default',
                DropshipListing::schema_fields_LOCAL_WAREHOUSE_ID => $localWarehouseId,
                DropshipListing::schema_fields_REMOTE_COUNTRY => $country,
                DropshipListing::schema_fields_REMOTE_CATEGORY_ID => $categoryId,
                DropshipListing::schema_fields_REMOTE_CATEGORY_PATH => $categoryPath,
                DropshipListing::schema_fields_REMOTE_STORAGE_ID => $snapshot->storageId,
                DropshipListing::schema_fields_ORIGIN_PRICE_MINOR => $snapshot->originPriceMinor,
                DropshipListing::schema_fields_ORIGIN_CURRENCY => $snapshot->originCurrency !== '' ? $snapshot->originCurrency : 'USD',
                DropshipListing::schema_fields_REMOTE_QTY => $snapshot->qty,
                DropshipListing::schema_fields_SYNC_STATUS => DropshipListing::STATUS_PENDING,
                DropshipListing::schema_fields_UPDATED_AT => $now,
            ];

            if ($existing && $existing->getId()) {
                // Keep active listings if already published into the basket key unexpectedly.
                $status = (string)$existing->getData(DropshipListing::schema_fields_SYNC_STATUS);
                if ($status !== DropshipListing::STATUS_ACTIVE) {
                    $data[DropshipListing::schema_fields_SYNC_STATUS] = DropshipListing::STATUS_PENDING;
                }
                $existing->setData($data)->save();
                $listingIds[] = (int)$existing->getId();
            } else {
                $data[DropshipListing::schema_fields_CREATED_AT] = $now;
                $listing->clear()->setData($data)->save();
                $listingIds[] = (int)$listing->getId();
            }
            ++$admitted;
        }

        return ['ok' => true, 'admitted' => $admitted, 'listing_ids' => $listingIds];
    }

    /**
     * Publish pending/active basket rows (or snapshot payloads) into a real website/store scope.
     *
     * @param list<int> $listingIds
     * @param list<array<string, mixed>> $snapshots
     * @param array{website_id:int,store_id:int,channel?:string,storage_scope?:string,actor_id?:int} $scope
     * @return array{ok:bool,published:int,errors:list<string>}
     */
    public function publishSelected(
        string $providerCode,
        array $listingIds,
        array $snapshots,
        array $scope,
        DropshipPublishService $publishService,
    ): array {
        $providerCode = trim($providerCode);
        // website_id=0 is the system default website (valid). Only reject Global (handled upstream as null).
        if ($providerCode === '') {
            return ['ok' => false, 'published' => 0, 'errors' => ['provider_required']];
        }
        if (!array_key_exists('website_id', $scope)) {
            return ['ok' => false, 'published' => 0, 'errors' => ['scope_required']];
        }

        $errors = [];
        $published = 0;
        $toPublish = [];

        foreach ($snapshots as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $snap = DropshipCatalogSnapshot::fromArray($raw);
            if ($snap->providerCode !== '' && $snap->providerCode !== $providerCode) {
                return ['ok' => false, 'published' => 0, 'errors' => ['dropship_snapshot_provider_mismatch']];
            }
            if ($snap->providerCode === '') {
                $raw['provider_code'] = $providerCode;
                $snap = DropshipCatalogSnapshot::fromArray($raw);
            }
            $toPublish[] = $snap;
        }

        if ($listingIds !== []) {
            /** @var DropshipChannelManager $channels */
            $channels = ObjectManager::getInstance(DropshipChannelManager::class);
            $channels->registerAllProviders();
            $provider = $channels->getProvider($providerCode);
            /** @var DropshipListing $model */
            $model = ObjectManager::getInstance(DropshipListing::class);
            foreach ($listingIds as $id) {
                $id = (int)$id;
                if ($id <= 0) {
                    continue;
                }
                $row = $model->clear()->where(DropshipListing::schema_fields_ID, $id)->find()->fetch();
                if (!$row || !$row->getId()) {
                    $errors[] = 'listing_not_found:' . $id;
                    continue;
                }
                $rowProvider = (string)$row->getData(DropshipListing::schema_fields_PROVIDER_CODE);
                if ($rowProvider !== $providerCode) {
                    return ['ok' => false, 'published' => 0, 'errors' => ['dropship_snapshot_provider_mismatch']];
                }
                $title = trim((string)$row->getData(DropshipListing::schema_fields_TITLE));
                $thumb = trim((string)$row->getData(DropshipListing::schema_fields_THUMB_URL));
                $spu = (string)$row->getData(DropshipListing::schema_fields_EXTERNAL_SPU);
                $fallback = DropshipCatalogSnapshot::fromArray([
                    'provider_code' => $rowProvider,
                    'external_spu' => $spu,
                    'external_sku' => (string)$row->getData(DropshipListing::schema_fields_EXTERNAL_SKU),
                    'title' => $title !== '' ? $title : $spu,
                    'origin_currency' => (string)$row->getData(DropshipListing::schema_fields_ORIGIN_CURRENCY) ?: 'USD',
                    'origin_price_minor' => (int)$row->getData(DropshipListing::schema_fields_ORIGIN_PRICE_MINOR),
                    'qty' => (int)$row->getData(DropshipListing::schema_fields_REMOTE_QTY),
                    'shelf_status' => 'active',
                    'country_code' => (string)$row->getData(DropshipListing::schema_fields_REMOTE_COUNTRY),
                    'storage_id' => (string)$row->getData(DropshipListing::schema_fields_REMOTE_STORAGE_ID),
                    'category_id' => (string)$row->getData(DropshipListing::schema_fields_REMOTE_CATEGORY_ID),
                    'category_path' => (string)$row->getData(DropshipListing::schema_fields_REMOTE_CATEGORY_PATH),
                    'media' => $thumb !== '' ? [['url' => $thumb, 'type' => 'image', 'role' => 'thumb']] : [],
                    'suggested_eav' => ['dropship_source' => $rowProvider],
                ]);
                $fresh = null;
                if ($provider instanceof \Weline\Dropship\Interface\DropshipCatalogProviderInterface) {
                    $fresh = $provider->getProduct([
                        'external_spu' => $spu,
                        'country_code' => (string)$row->getData(DropshipListing::schema_fields_REMOTE_COUNTRY),
                        'locale' => 'zh_Hans_CN',
                    ]);
                }
                $toPublish[] = $fresh ?? $fallback;
            }
        }

        foreach ($toPublish as $snap) {
            try {
                $result = $publishService->publish($snap, $scope);
                if (empty($result['local_product_uuid']) || empty($result['local_offer_id'])) {
                    $errors[] = 'dropship_publish_local_product_required:' . $snap->externalSpu;
                    continue;
                }
                ++$published;
                // Mark basket row active only after real local product ids exist.
                /** @var DropshipListing $basket */
                $basket = ObjectManager::getInstance(DropshipListing::class);
                $pending = $basket->clear()
                    ->where(DropshipListing::schema_fields_PROVIDER_CODE, $providerCode)
                    ->where(DropshipListing::schema_fields_EXTERNAL_SPU, $snap->externalSpu)
                    ->where(DropshipListing::schema_fields_WEBSITE_ID, 0)
                    ->where(DropshipListing::schema_fields_STORE_ID, 0)
                    ->where(DropshipListing::schema_fields_CHANNEL, 'default')
                    ->find()
                    ->fetch();
                if ($pending && $pending->getId()) {
                    $pending->setData([
                        DropshipListing::schema_fields_SYNC_STATUS => DropshipListing::STATUS_ACTIVE,
                        DropshipListing::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                        DropshipListing::schema_fields_LOCAL_PRODUCT_UUID => $result['local_product_uuid'],
                        DropshipListing::schema_fields_LOCAL_OFFER_ID => $result['local_offer_id'],
                        DropshipListing::schema_fields_SALE_PRICE_MINOR => (int)($result['sale_price_minor'] ?? 0),
                        DropshipListing::schema_fields_TITLE => $snap->title,
                        DropshipListing::schema_fields_THUMB_URL => self::firstThumbUrl($snap->media),
                    ])->save();
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage() !== '' ? $e->getMessage() : 'publish_failed';
            }
        }

        return [
            'ok' => $errors === [] && $published > 0,
            'published' => $published,
            'errors' => $errors,
        ];
    }

    /**
     * Map remote SPUs to basket / published state for the pick UI.
     *
     * - locked: any listing with website_id &gt; 0 (already published scope) → 已拉取不可操作
     * - in_basket: pending/active basket row at website_id=0/store_id=0 → 可取消移出
     * - pulled: locked or in_basket
     *
     * @param list<string> $externalSpus
     * @return array<string, array{pulled:bool,locked:bool,in_basket:bool,listing_id:int}>
     */
    public function mapPullState(string $providerCode, array $externalSpus): array
    {
        $providerCode = trim($providerCode);
        $spus = [];
        foreach ($externalSpus as $spu) {
            $spu = trim((string)$spu);
            if ($spu !== '') {
                $spus[$spu] = true;
            }
        }
        $out = [];
        if ($providerCode === '' || $spus === []) {
            return $out;
        }
        foreach (array_keys($spus) as $spu) {
            $out[$spu] = [
                'pulled' => false,
                'locked' => false,
                'in_basket' => false,
                'listing_id' => 0,
            ];
        }

        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $rows = $model->clear()
            ->where(DropshipListing::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipListing::schema_fields_EXTERNAL_SPU, array_keys($spus), 'IN')
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $spu = trim((string)($row[DropshipListing::schema_fields_EXTERNAL_SPU] ?? ''));
            if ($spu === '' || !isset($out[$spu])) {
                continue;
            }
            $websiteId = (int)($row[DropshipListing::schema_fields_WEBSITE_ID] ?? 0);
            $storeId = (int)($row[DropshipListing::schema_fields_STORE_ID] ?? 0);
            $listingId = (int)($row[DropshipListing::schema_fields_ID] ?? 0);
            $out[$spu]['pulled'] = true;
            if ($listingId > 0 && $out[$spu]['listing_id'] <= 0) {
                $out[$spu]['listing_id'] = $listingId;
            }
            if ($websiteId > 0) {
                $out[$spu]['locked'] = true;
            } elseif ($websiteId === 0 && $storeId === 0) {
                $out[$spu]['in_basket'] = true;
                if ($listingId > 0) {
                    $out[$spu]['listing_id'] = $listingId;
                }
            }
        }

        return $out;
    }

    /**
     * Remove pending basket rows only (website_id=0/store_id=0). Locked published scopes stay.
     *
     * @param list<string> $externalSpus
     * @return array{ok:bool,removed:int,skipped_locked:int,error?:string}
     */
    public function removeFromBasket(string $providerCode, array $externalSpus): array
    {
        $providerCode = trim($providerCode);
        if ($providerCode === '') {
            return ['ok' => false, 'removed' => 0, 'skipped_locked' => 0, 'error' => 'provider_code_required'];
        }
        $spus = [];
        foreach ($externalSpus as $spu) {
            $spu = trim((string)$spu);
            if ($spu !== '') {
                $spus[$spu] = true;
            }
        }
        if ($spus === []) {
            return ['ok' => false, 'removed' => 0, 'skipped_locked' => 0, 'error' => 'spus_required'];
        }

        $state = $this->mapPullState($providerCode, array_keys($spus));
        $removed = 0;
        $skippedLocked = 0;
        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        foreach (array_keys($spus) as $spu) {
            $st = $state[$spu] ?? null;
            if (!$st) {
                continue;
            }
            if (!empty($st['locked'])) {
                ++$skippedLocked;
                continue;
            }
            if (empty($st['in_basket'])) {
                continue;
            }
            $row = $model->clear()
                ->where(DropshipListing::schema_fields_PROVIDER_CODE, $providerCode)
                ->where(DropshipListing::schema_fields_EXTERNAL_SPU, $spu)
                ->where(DropshipListing::schema_fields_WEBSITE_ID, 0)
                ->where(DropshipListing::schema_fields_STORE_ID, 0)
                ->where(DropshipListing::schema_fields_CHANNEL, 'default')
                ->find()
                ->fetch();
            if ($row && $row->getId()) {
                $row->delete();
                ++$removed;
            }
        }

        return ['ok' => true, 'removed' => $removed, 'skipped_locked' => $skippedLocked];
    }

    /**
     * First http(s) media URL from a catalog snapshot media list.
     *
     * @param list<mixed> $media
     */
    public static function firstThumbUrl(array $media): string
    {
        foreach ($media as $m) {
            if (!is_array($m)) {
                continue;
            }
            $url = trim((string)($m['url'] ?? ''));
            if ($url !== '' && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
                return mb_substr($url, 0, 1024);
            }
        }

        return '';
    }
}
