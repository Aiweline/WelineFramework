<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\SkuAlias;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Service\ProductShardProvisioner;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_product_input(): array
{
    $input = json_decode((string)stream_get_contents(STDIN), true);
    return is_array($input) ? $input : [];
}

/** @param array<string,mixed> $payload */
function r43_product_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

function r43_product_token(array $input): string
{
    $token = strtolower((string)($input['token'] ?? ''));
    $token = preg_replace('/[^a-z0-9]/', '', $token) ?: '';
    return $token !== '' ? substr($token, 0, 20) : substr(bin2hex(random_bytes(8)), 0, 12);
}

function r43_product_model(string $class, int $websiteId): object
{
    $model = ObjectManager::getInstance($class, [], false);
    return $model->forWebsite($websiteId);
}

/** @param array<string,mixed> $where @return list<array<string,mixed>> */
function r43_product_rows(object $model, array $where): array
{
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $rows = $query->select()->fetchArray();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** @param array<string,mixed> $where */
function r43_product_delete(object $model, array $where): int
{
    $rows = r43_product_rows($model, $where);
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $query->delete()->fetch();
    return count($rows);
}

function r43_product_assert_isolated_database(): string
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_product_requires_isolated_database_flag');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $database = trim((string)($env['db']['master']['database'] ?? ''));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_product_requires_mig_clone_database:' . $database);
    }
    return $database;
}

function r43_product_assert_pgsql(): string
{
    $model = ObjectManager::getInstance(SkuRegistry::class, [], false);
    $connector = get_class($model->getConnection()->getConnector());
    $driver = strtolower($connector);
    if (!str_contains($driver, 'pgsql') && !str_contains($driver, 'postgres')) {
        throw new RuntimeException('r43_product_requires_postgresql:' . $connector);
    }
    return $connector;
}

/** @return array<string,mixed> */
function r43_product_data(string $token): array
{
    return [
        'token' => $token,
        'website_id' => 0,
        'sku' => 'R43-' . strtoupper($token),
        'request_hash' => hash('sha256', 'r43-product-' . $token),
        'category_path' => '/r43/' . $token,
        'media_path' => '/media/r43/' . $token . '.png',
        'blob_key' => 'r43-product-' . $token,
        'position' => 43,
        'store_id' => 0,
        'attribute_code' => 'name',
        'locale' => 'zh_Hans_CN',
        'site_content_value' => 'R43 站点文案 ' . $token,
    ];
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function r43_product_inspect(array $data): array
{
    $websiteId = (int)$data['website_id'];
    $sku = (string)$data['sku'];
    $registry = r43_product_rows(
        ObjectManager::getInstance(SkuRegistry::class, [], false),
        [SkuRegistry::schema_fields_SKU => $sku],
    );
    $products = r43_product_rows(
        r43_product_model(Product::class, $websiteId),
        [Product::schema_fields_SKU => $sku],
    );
    $productId = (int)($products[0][Product::schema_fields_ID] ?? 0);
    $offers = $productId > 0 ? r43_product_rows(
        r43_product_model(Offer::class, $websiteId),
        [Offer::schema_fields_PRODUCT_ID => $productId],
    ) : [];
    $categories = r43_product_rows(
        r43_product_model(Category::class, $websiteId),
        [Category::schema_fields_PATH => (string)$data['category_path']],
    );
    $media = r43_product_rows(
        r43_product_model(Media::class, $websiteId),
        [Media::schema_fields_BLOB_KEY => (string)$data['blob_key']],
    );
    $attributeValues = $productId > 0 ? r43_product_rows(
        r43_product_model(AttributeValue::class, $websiteId),
        [
            AttributeValue::schema_fields_STORE_ID => (int)$data['store_id'],
            AttributeValue::schema_fields_ENTITY_TYPE => 'product',
            AttributeValue::schema_fields_ENTITY_ID => $productId,
            AttributeValue::schema_fields_ATTRIBUTE_CODE => (string)$data['attribute_code'],
            AttributeValue::schema_fields_LOCALE => (string)$data['locale'],
        ],
    ) : [];

    return [
        'registry' => $registry,
        'products' => $products,
        'offers' => $offers,
        'categories' => $categories,
        'media' => $media,
        'attribute_values' => $attributeValues,
    ];
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function r43_product_cleanup(array $data): array
{
    $websiteId = (int)$data['website_id'];
    $sku = (string)$data['sku'];
    if (!str_starts_with($sku, 'R43-')) {
        throw new RuntimeException('refusing product cleanup outside R43 namespace');
    }
    $products = r43_product_rows(
        r43_product_model(Product::class, $websiteId),
        [Product::schema_fields_SKU => $sku],
    );
    $productId = (int)($products[0][Product::schema_fields_ID] ?? 0);
    $deleted = [
        'attribute_values' => 0,
        'media' => 0,
        'offers' => 0,
        'products' => 0,
        'categories' => 0,
        'aliases' => 0,
        'registry' => 0,
    ];
    if ($productId > 0) {
        $deleted['attribute_values'] = r43_product_delete(
            r43_product_model(AttributeValue::class, $websiteId),
            [
                AttributeValue::schema_fields_ENTITY_TYPE => 'product',
                AttributeValue::schema_fields_ENTITY_ID => $productId,
            ],
        );
        $deleted['media'] = r43_product_delete(
            r43_product_model(Media::class, $websiteId),
            [Media::schema_fields_PRODUCT_ID => $productId],
        );
        $deleted['offers'] = r43_product_delete(
            r43_product_model(Offer::class, $websiteId),
            [Offer::schema_fields_PRODUCT_ID => $productId],
        );
    }
    $deleted['products'] = r43_product_delete(
        r43_product_model(Product::class, $websiteId),
        [Product::schema_fields_SKU => $sku],
    );
    $deleted['categories'] = r43_product_delete(
        r43_product_model(Category::class, $websiteId),
        [Category::schema_fields_PATH => (string)$data['category_path']],
    );
    $registryRows = r43_product_rows(
        ObjectManager::getInstance(SkuRegistry::class, [], false),
        [SkuRegistry::schema_fields_SKU => $sku],
    );
    $registryId = (int)($registryRows[0][SkuRegistry::schema_fields_ID] ?? 0);
    if ($registryId > 0) {
        $deleted['aliases'] = r43_product_delete(
            ObjectManager::getInstance(SkuAlias::class, [], false),
            [SkuAlias::schema_fields_REGISTRY_ID => $registryId],
        );
    }
    $deleted['registry'] = r43_product_delete(
        ObjectManager::getInstance(SkuRegistry::class, [], false),
        [SkuRegistry::schema_fields_SKU => $sku],
    );
    $remainingRows = r43_product_inspect($data);
    $remaining = array_map('count', $remainingRows);
    if (array_sum($remaining) !== 0) {
        throw new RuntimeException('product fixture cleanup left rows: ' . json_encode($remaining));
    }

    return ['deleted' => $deleted, 'remaining' => $remaining];
}

try {
    $input = r43_product_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $data = r43_product_data(r43_product_token($input));
    r43_product_assert_isolated_database();
    $connector = r43_product_assert_pgsql();
    ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite((int)$data['website_id']);

    if ($action === 'prepare') {
        r43_product_cleanup($data);
        r43_product_output(['ok' => true, 'connector' => $connector] + $data);
    }
    if ($action === 'inspect') {
        r43_product_output(['ok' => true, 'connector' => $connector] + r43_product_inspect($data));
    }
    if ($action === 'cleanup') {
        r43_product_output(['ok' => true, 'connector' => $connector] + r43_product_cleanup($data));
    }
    throw new InvalidArgumentException('unsupported action: ' . $action);
} catch (Throwable $throwable) {
    r43_product_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
