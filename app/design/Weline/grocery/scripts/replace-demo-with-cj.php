<?php

declare(strict_types=1);

/**
 * Clear Neighborhood Grocery demo shelf (GROCERY-* + grocery-* categories),
 * re-enable existing CJ listings, and pull more CJ products into website=grocery.
 *
 * Usage:
 *   php app/design/Weline/grocery/scripts/replace-demo-with-cj.php
 *   php app/design/Weline/grocery/scripts/replace-demo-with-cj.php --pull=24
 *
 * Refuse default website (0). Design-theme script only — not under app/code.
 */

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Service\DropshipCategoryEnsureService;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Dropship\Service\DropshipPublishService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontCategoryTreeIndex;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$pullLimit = 24;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--pull=(\d+)$/', $arg, $m)) {
        $pullLimit = max(0, min(80, (int)$m[1]));
    }
}

$websiteModel = ObjectManager::getInstance(Website::class);
$websiteId = 0;
foreach ($websiteModel->reset()->where(Website::schema_primary_key, 0, '>=')->select()->fetchArray() as $row) {
    if ((string)($row['code'] ?? '') === 'grocery') {
        $websiteId = (int)($row['website_id'] ?? 0);
        break;
    }
}
if ($websiteId <= 0 || $websiteId === Website::ID_DEFAULT) {
    fwrite(STDERR, "Refuse: website code=grocery not found or resolved to default.\n");
    exit(1);
}

$storeId = 0;
foreach (ObjectManager::getInstance(Store::class)->clear()->where('website_id', $websiteId)->select()->fetchArray() as $store) {
    if ((int)($store['is_default'] ?? 0) === 1 || $storeId === 0) {
        $storeId = (int)($store['store_id'] ?? 0);
    }
}
if ($storeId <= 0) {
    fwrite(STDERR, "Refuse: grocery default store not found.\n");
    exit(1);
}

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var OfferRepository $offers */
$offers = ObjectManager::getInstance(OfferRepository::class);
/** @var CategoryRepository $categories */
$categories = ObjectManager::getInstance(CategoryRepository::class);
/** @var ProductCategoryAdminService $catAdmin */
$catAdmin = ObjectManager::getInstance(ProductCategoryAdminService::class);
/** @var ProductCategoryAttributeService $catAttrs */
$catAttrs = ObjectManager::getInstance(ProductCategoryAttributeService::class);
/** @var DropshipCategoryEnsureService $catEnsure */
$catEnsure = ObjectManager::getInstance(DropshipCategoryEnsureService::class);
/** @var DropshipChannelManager $channels */
$channels = ObjectManager::getInstance(DropshipChannelManager::class);
$channels->registerAllProviders();
$provider = $channels->getProvider('cj');
if ($provider === null) {
    fwrite(STDERR, "Refuse: CJ provider unavailable.\n");
    exit(1);
}
/** @var DropshipPublishService $publish */
$publish = ObjectManager::getInstance(DropshipPublishService::class);

$scope = [
    'website_id' => $websiteId,
    'store_id' => $storeId,
    'channel' => 'default',
    'storage_scope' => 'default.__website__.default',
    'actor_id' => 0,
];

$report = [
    'website_id' => $websiteId,
    'store_id' => $storeId,
    'demo_products_disabled' => 0,
    'demo_offers_disabled' => 0,
    'grocery_categories_inactive' => 0,
    'cj_products_reenabled' => 0,
    'cj_offers_reenabled' => 0,
    'cj_categories_ensured' => 0,
    'cj_pulled' => 0,
    'cj_pull_errors' => [],
    'pull_limit' => $pullLimit,
];

// 1) Disable GROCERY-* demo products (published → disabled).
foreach ($products->listAll($websiteId) as $row) {
    $sku = strtoupper(trim((string)($row['sku'] ?? '')));
    if (!str_starts_with($sku, 'GROCERY-')) {
        continue;
    }
    $productId = (int)($row['product_id'] ?? $row['id'] ?? 0);
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $version = (int)($row['publish_version'] ?? 0);
    if ($productId <= 0) {
        continue;
    }
    if ($status === Product::STATUS_PUBLISHED) {
        try {
            $products->transition($websiteId, $productId, $version, Product::STATUS_DISABLED);
            ++$report['demo_products_disabled'];
        } catch (Throwable $e) {
            $report['cj_pull_errors'][] = 'demo_product:' . $sku . ':' . $e->getMessage();
        }
    }
    foreach ($offers->listByProductIds($websiteId, [$productId]) as $offerRow) {
        if (!is_array($offerRow)) {
            continue;
        }
        $offerId = (int)($offerRow['offer_id'] ?? 0);
        $offerStatus = strtolower(trim((string)($offerRow['status'] ?? '')));
        $offerVersion = (int)($offerRow['publish_version'] ?? 0);
        if ($offerId <= 0 || $offerStatus !== 'published') {
            continue;
        }
        try {
            $offers->transition($websiteId, $offerId, $offerVersion, 'disabled');
            ++$report['demo_offers_disabled'];
        } catch (Throwable $e) {
            $report['cj_pull_errors'][] = 'demo_offer:' . $sku . ':' . $e->getMessage();
        }
    }
}

// 2) Deactivate grocery-* seed categories.
$codeIndex = [];
$allCatIds = [];
foreach ($categories->listAll($websiteId) as $row) {
    $id = (int)($row['category_id'] ?? 0);
    if ($id > 0) {
        $allCatIds[] = $id;
    }
}
$enCodes = $catAttrs->readCodeMap($websiteId, $allCatIds, 'en_US');
$zhCodes = $catAttrs->readCodeMap($websiteId, $allCatIds, 'zh_Hans_CN');
foreach ($allCatIds as $id) {
    $code = strtolower(trim((string)($enCodes[$id] ?? '')));
    if ($code === '') {
        $code = strtolower(trim((string)($zhCodes[$id] ?? '')));
    }
    if ($code !== '') {
        $codeIndex[$code] = $id;
    }
}
foreach ($codeIndex as $code => $categoryId) {
    if (!str_starts_with($code, 'grocery-')) {
        continue;
    }
    $row = null;
    foreach ($categories->listAll($websiteId) as $candidate) {
        if ((int)($candidate['category_id'] ?? 0) === $categoryId) {
            $row = $candidate;
            break;
        }
    }
    if ($row === null) {
        continue;
    }
    $status = strtolower(trim((string)($row['status'] ?? 'active')));
    if ($status === 'inactive') {
        continue;
    }
    $nameEn = trim((string)($catAttrs->readNameMap($websiteId, [$categoryId], 'en_US')[$categoryId] ?? ''));
    if ($nameEn === '') {
        $nameEn = $code;
    }
    $catAdmin->save(
        $websiteId,
        $categoryId,
        (int)($row['parent_id'] ?? 0),
        $nameEn,
        'inactive',
        $code,
        'en_US',
    );
    ++$report['grocery_categories_inactive'];
}

// 3) Re-enable existing DS-CJ products + ensure their CJ category paths.
/** @var DropshipListing $listingModel */
$listingModel = ObjectManager::getInstance(DropshipListing::class);
$listings = $listingModel->clear()
    ->where(DropshipListing::schema_fields_WEBSITE_ID, $websiteId)
    ->where(DropshipListing::schema_fields_PROVIDER_CODE, 'cj')
    ->select()
    ->fetchArray();

$productBySku = [];
foreach ($products->listAll($websiteId) as $row) {
    $sku = strtoupper(trim((string)($row['sku'] ?? '')));
    if ($sku !== '') {
        $productBySku[$sku] = $row;
    }
}

$ensuredPaths = [];
foreach ($listings as $listing) {
    $path = trim((string)($listing[DropshipListing::schema_fields_REMOTE_CATEGORY_PATH] ?? ''));
    if ($path !== '' && !isset($ensuredPaths[$path])) {
        try {
            $ids = $catEnsure->ensureFromRemotePath($websiteId, 'cj', $path, 'zh_Hans_CN');
            $ensuredPaths[$path] = $ids;
            $report['cj_categories_ensured'] += count($ids);
        } catch (Throwable $e) {
            $report['cj_pull_errors'][] = 'ensure_cat:' . mb_substr($path, 0, 80) . ':' . $e->getMessage();
        }
    }

    $offerId = (int)($listing[DropshipListing::schema_fields_LOCAL_OFFER_ID] ?? 0);
    $skuHint = 'DS-CJ-' . strtoupper(trim((string)($listing[DropshipListing::schema_fields_EXTERNAL_SKU] ?? '')));
    $matched = null;
    foreach ($productBySku as $sku => $row) {
        if ($sku === $skuHint || str_starts_with($sku, $skuHint . '-') || str_contains($sku, strtoupper(trim((string)($listing[DropshipListing::schema_fields_EXTERNAL_SKU] ?? ''))))) {
            // Prefer exact DS-CJ-{sku} or variant prefix match for this listing's product uuid via offer.
            $matched = $row;
            if ($sku === $skuHint) {
                break;
            }
        }
    }
    // Resolve by local_offer_id → product
    if ($offerId > 0) {
        try {
            $offer = $offers->findById($websiteId, $offerId);
            if ($offer) {
                $pid = (int)$offer->getData('product_id');
                foreach ($products->listAll($websiteId) as $row) {
                    if ((int)($row['product_id'] ?? 0) === $pid) {
                        $matched = $row;
                        break;
                    }
                }
                $offerStatus = strtolower(trim((string)$offer->getData('status')));
                $offerVersion = (int)$offer->getData('publish_version');
                if ($offerStatus === 'disabled') {
                    $offers->transition($websiteId, $offerId, $offerVersion, 'published');
                    ++$report['cj_offers_reenabled'];
                } elseif ($offerStatus === 'draft') {
                    $offers->publish($websiteId, $offerId, $offerVersion);
                    ++$report['cj_offers_reenabled'];
                }
            }
        } catch (Throwable $e) {
            $report['cj_pull_errors'][] = 'offer:' . $offerId . ':' . $e->getMessage();
        }
    }
    if ($matched !== null) {
        $productId = (int)($matched['product_id'] ?? 0);
        $status = strtolower(trim((string)($matched['status'] ?? '')));
        $version = (int)($matched['publish_version'] ?? 0);
        if ($productId > 0 && $status === Product::STATUS_DISABLED) {
            try {
                $products->transition($websiteId, $productId, $version, Product::STATUS_PUBLISHED);
                ++$report['cj_products_reenabled'];
            } catch (Throwable $e) {
                $report['cj_pull_errors'][] = 'product:' . $productId . ':' . $e->getMessage();
            }
        }
    }
}

// 4) Fresh CJ pull into grocery scope (home / household oriented leaf categories).
$categoryIds = [
    '87CF251F-8D11-4DE0-A154-9694D9858EB3', // 家居办公收纳
    'B62EE40F-7650-4715-A7A5-BA227540593C', // 浴室收纳
];
// Add a few more home-related leaves if present.
foreach ($provider->listCategories(['locale' => 'zh_Hans_CN']) as $node) {
    if (!is_array($node)) {
        continue;
    }
    $path = (string)($node['path'] ?? '');
    $id = trim((string)($node['id'] ?? ''));
    if ($id === '') {
        continue;
    }
    if (
        str_contains($path, '家居、园艺与家具')
        && (str_contains($path, '厨') || str_contains($path, '清洁') || str_contains($path, '收纳') || str_contains($path, 'Kitchen') || str_contains($path, 'Cleaning'))
    ) {
        $categoryIds[] = $id;
    }
}
$categoryIds = array_values(array_unique($categoryIds));

$existingSpus = [];
foreach ($listings as $listing) {
    $spu = trim((string)($listing[DropshipListing::schema_fields_EXTERNAL_SPU] ?? ''));
    if ($spu !== '') {
        $existingSpus[$spu] = true;
    }
}

$pulled = 0;
foreach ($categoryIds as $categoryId) {
    if ($pulled >= $pullLimit) {
        break;
    }
    $page = 1;
    while ($pulled < $pullLimit && $page <= 3) {
        try {
            /** @var list<DropshipCatalogSnapshot> $snaps */
            $snaps = $provider->searchProducts([
                'page' => $page,
                'size' => 20,
                'locale' => 'zh_Hans_CN',
                'country_code' => 'CN',
                'category_id' => $categoryId,
            ]);
        } catch (Throwable $e) {
            $report['cj_pull_errors'][] = 'search:' . $categoryId . ':p' . $page . ':' . $e->getMessage();
            break;
        }
        if ($snaps === []) {
            break;
        }
        foreach ($snaps as $snap) {
            if ($pulled >= $pullLimit) {
                break;
            }
            if (!$snap instanceof DropshipCatalogSnapshot) {
                continue;
            }
            $spu = trim($snap->externalSpu);
            if ($spu === '' || isset($existingSpus[$spu])) {
                continue;
            }
            try {
                $detail = $provider->getProduct([
                    'pid' => $spu,
                    'external_spu' => $spu,
                    'locale' => 'zh_Hans_CN',
                    'country_code' => 'CN',
                ]) ?? $snap;
                $publish->publish($detail, $scope);
                $existingSpus[$spu] = true;
                ++$pulled;
                ++$report['cj_pulled'];
                echo "+ pulled {$spu} " . mb_substr($detail->title, 0, 48) . PHP_EOL;
            } catch (Throwable $e) {
                $report['cj_pull_errors'][] = 'publish:' . $spu . ':' . $e->getMessage();
            }
        }
        ++$page;
    }
}

// 5) Relink every published CJ listing to ensured active category leaves.
$byOffer = [];
$byUuid = [];
foreach ($products->listAll($websiteId) as $row) {
    $pid = (int)($row['product_id'] ?? 0);
    $uuid = trim((string)($row['global_product_uuid'] ?? ''));
    if ($uuid !== '') {
        $byUuid[$uuid] = $row;
    }
    foreach ($offers->listByProductIds($websiteId, [$pid]) as $offerRow) {
        if (is_array($offerRow)) {
            $byOffer[(int)($offerRow['offer_id'] ?? 0)] = $row;
        }
    }
}
/** @var \Weline\Product\Repository\CategoryLinkRepository $categoryLinks */
$categoryLinks = ObjectManager::getInstance(\Weline\Product\Repository\CategoryLinkRepository::class);
$report['cj_relinked'] = 0;
$report['cj_categories_activated'] = 0;
$activateChain = static function (int $leafId) use (
    $categories,
    $catAdmin,
    $catAttrs,
    $websiteId,
    &$report,
): void {
    $byId = [];
    foreach ($categories->listAll($websiteId) as $row) {
        $byId[(int)($row['category_id'] ?? 0)] = $row;
    }
    $id = $leafId;
    $guard = 0;
    while ($id > 0 && $guard++ < 20) {
        $row = $byId[$id] ?? null;
        if ($row === null) {
            break;
        }
        $status = strtolower(trim((string)($row['status'] ?? 'active')));
        if ($status !== 'active') {
            $name = trim((string)($catAttrs->readNameMap($websiteId, [$id], 'zh_Hans_CN')[$id] ?? ''));
            if ($name === '') {
                $name = trim((string)($catAttrs->readNameMap($websiteId, [$id], 'en_US')[$id] ?? 'category'));
            }
            $code = trim((string)($catAttrs->readCodeMap($websiteId, [$id], 'en_US')[$id] ?? ''));
            $catAdmin->save(
                $websiteId,
                $id,
                (int)($row['parent_id'] ?? 0),
                $name !== '' ? $name : 'category',
                'active',
                $code,
                'zh_Hans_CN',
            );
            ++$report['cj_categories_activated'];
        }
        $id = (int)($row['parent_id'] ?? 0);
    }
};
foreach ($listings as $listing) {
    $path = trim((string)($listing[DropshipListing::schema_fields_REMOTE_CATEGORY_PATH] ?? ''));
    $offerId = (int)($listing[DropshipListing::schema_fields_LOCAL_OFFER_ID] ?? 0);
    $uuid = trim((string)($listing[DropshipListing::schema_fields_LOCAL_PRODUCT_UUID] ?? ''));
    $productRow = $byOffer[$offerId] ?? ($byUuid[$uuid] ?? null);
    if (!is_array($productRow) || $path === '') {
        continue;
    }
    if (strtolower(trim((string)($productRow['status'] ?? ''))) !== Product::STATUS_PUBLISHED) {
        continue;
    }
    $productId = (int)($productRow['product_id'] ?? 0);
    try {
        $leafIds = $catEnsure->ensureFromRemotePath($websiteId, 'cj', $path, 'zh_Hans_CN');
        $leaf = (int)($leafIds[0] ?? 0);
        if ($leaf <= 0 || $productId <= 0) {
            continue;
        }
        $activateChain($leaf);
        $categoryLinks->syncProductScope($websiteId, $productId, 0, [[
            'category_id' => $leaf,
            'selected' => true,
            'scope_state' => 'explicit',
            'position' => 0,
        ]]);
        ++$report['cj_relinked'];
    } catch (Throwable $e) {
        $report['cj_pull_errors'][] = 'relink:' . $productId . ':' . $e->getMessage();
    }
}

try {
    ObjectManager::getInstance(StorefrontCategoryTreeIndex::class)->invalidate($websiteId);
} catch (Throwable) {
}
try {
    ObjectManager::getInstance(StorefrontAllMenuCategoryTreeService::class)->invalidate($websiteId);
} catch (Throwable) {
}
try {
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged($websiteId, 'grocery-replace-demo-with-cj');
} catch (Throwable) {
}

// Final counts
$pub = 0;
$groceryLeft = 0;
$dscjPub = 0;
foreach ($products->listAll($websiteId) as $row) {
    $sku = strtoupper(trim((string)($row['sku'] ?? '')));
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status !== Product::STATUS_PUBLISHED) {
        continue;
    }
    ++$pub;
    if (str_starts_with($sku, 'GROCERY-')) {
        ++$groceryLeft;
    }
    if (str_starts_with($sku, 'DS-CJ')) {
        ++$dscjPub;
    }
}
$activeCats = 0;
$activeGroceryCats = 0;
foreach ($categories->listAll($websiteId) as $row) {
    if (strtolower(trim((string)($row['status'] ?? ''))) !== 'active') {
        continue;
    }
    ++$activeCats;
    $id = (int)($row['category_id'] ?? 0);
    $code = strtolower(trim((string)($enCodes[$id] ?? $zhCodes[$id] ?? '')));
    if (str_starts_with($code, 'grocery-')) {
        ++$activeGroceryCats;
    }
}

$report['ok'] = $groceryLeft === 0 && $pub > 0 && $activeGroceryCats === 0;
$report['published_total'] = $pub;
$report['published_grocery_left'] = $groceryLeft;
$report['published_ds_cj'] = $dscjPub;
$report['active_categories'] = $activeCats;
$report['active_grocery_categories_left'] = $activeGroceryCats;

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($report['ok'] ? 0 : 2);
