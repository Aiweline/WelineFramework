<?php

declare(strict_types=1);

/**
 * Category delete product checklist fixture for Playwright e2e.
 * stdin JSON: {action:seed|verify_deleted, grant_version?, category_id?, product_id?}
 */

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Catalog\Service\CatalogHubService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\ProductRepository;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

try {
    $action = (string)($payload['action'] ?? '');
    $hub = ObjectManager::getInstance(CatalogHubService::class);
    $auth = ObjectManager::getInstance(ObjectAuthorizationServiceInterface::class);
    $scope = ScopeIdentity::website(0, 'default');
    $locale = 'zh_Hans_CN';
    $websiteId = 0;

    if ($action === 'seed') {
        $grantVersion = (int)($payload['grant_version'] ?? 0);
        $check = $auth->authorizeForSubmit(1, ObjectAction::CREATE, $scope, max(1, $grantVersion));
        if (!$check->allowed) {
            throw new RuntimeException('create_auth_denied:' . $check->reason);
        }
        $stamp = (string)(int)(microtime(true) * 1000);
        $result = $hub->execute('save', [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => $websiteId,
            'store_id' => 0,
            'channel_id' => 0,
            'locale' => $locale,
            'category_id' => 0,
            'parent_id' => 0,
            'name' => 'e2e-delprod-' . $stamp,
            'code' => 'e2e-delprod-' . $stamp,
            'is_active' => 1,
        ]);
        $categoryId = max(0, (int)($result['category_id'] ?? $result['id'] ?? 0));
        if ($categoryId <= 0) {
            throw new RuntimeException('create_category_failed');
        }

        /** @var ProductRepository $products */
        $products = ObjectManager::getInstance(ProductRepository::class);
        $product = $products->create($websiteId, [
            Product::schema_fields_SKU => 'E2E-DELPROD-' . $stamp,
            Product::schema_fields_PRODUCT_CODE => 'E2E-DELPROD-' . $stamp,
            Product::schema_fields_GLOBAL_PRODUCT_UUID => 'e2e-delprod-' . $stamp,
            Product::schema_fields_OWNER_WEBSITE_ID => $websiteId,
            Product::schema_fields_PROVIDER_CODE => 'local',
            Product::schema_fields_PRODUCT_TYPE => 'simple',
            Product::schema_fields_IDENTITY_VERSION => 1,
            Product::schema_fields_SOURCE_WEBSITE_ID => $websiteId,
            Product::schema_fields_SOURCE_VERSION => 1,
        ]);
        $productId = (int)$product->getId();
        if ($productId <= 0) {
            throw new RuntimeException('create_product_failed');
        }

        /** @var CategoryLinkRepository $links */
        $links = ObjectManager::getInstance(CategoryLinkRepository::class);
        $links->link($websiteId, $categoryId, $productId, 0, true, 1);

        echo json_encode([
            'ok' => true,
            'category_id' => $categoryId,
            'product_id' => $productId,
        ], JSON_UNESCAPED_UNICODE), PHP_EOL;
        exit(0);
    }

    if ($action === 'list') {
        $categoryId = max(0, (int)($payload['category_id'] ?? 0));
        $products = $hub->execute('listProductsForDelete', [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => $websiteId,
            'store_id' => 0,
            'channel_id' => 0,
            'locale' => $locale,
            'category_id' => $categoryId,
            'id' => $categoryId,
        ]);
        echo json_encode([
            'ok' => true,
            'products' => is_array($products) ? $products : [],
        ], JSON_UNESCAPED_UNICODE), PHP_EOL;
        exit(0);
    }

    if ($action === 'verify_deleted') {
        $categoryId = max(0, (int)($payload['category_id'] ?? 0));
        $productId = max(0, (int)($payload['product_id'] ?? 0));
        /** @var CategoryRepository $categories */
        $categories = ObjectManager::getInstance(CategoryRepository::class);
        /** @var ProductRepository $products */
        $products = ObjectManager::getInstance(ProductRepository::class);
        echo json_encode([
            'ok' => true,
            'category_gone' => $categories->findById($websiteId, $categoryId) === null,
            'product_kept' => $products->findById($websiteId, $productId) !== null,
        ], JSON_UNESCAPED_UNICODE), PHP_EOL;
        exit(0);
    }

    throw new RuntimeException('unknown_action');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
}
