<?php

declare(strict_types=1);

/**
 * 校对汉服商品级规格样本图（仅写入 product.type_configuration）。
 *
 * 禁止把商品图同步到全局 EAV 选项；EAV 只提供「可填图」能力与抽象色值。
 *
 * Usage: php app/code/Weline/Product/scripts/patch-hanfu-variant-previews.php [website_id]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductCatalogEavBootstrap;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var ProductCatalogEavBootstrap $eavBootstrap */
$eavBootstrap = ObjectManager::getInstance(ProductCatalogEavBootstrap::class);
/** @var StorefrontCatalogCacheCoordinator $catalogCache */
$catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);

$eavBootstrap->ensureStorefrontSchema();
$eavBootstrap->ensureHanfuSchema();

/**
 * 商品级 preview：写入 type_configuration，不污染全局 EAV 选项图板。
 *
 * @var array<string, array<string, array<string, array{swatch?:string,swatch_image?:string|null,gallery_images?:list<string>}>>>
 */
$previewBySku = [
    'HF-TYQM-260303' => [
        'color' => [
            'm-white' => ['swatch' => '#f5f0e6', 'swatch_image' => '/pub/media/catalog/hanfu/taoyuan-qingmeng/01.jpg'],
            'pink' => ['swatch' => '#f4b4c4', 'swatch_image' => '/pub/media/catalog/hanfu/taoyuan-qingmeng/pink-set-01.jpg', 'gallery_images' => ['/pub/media/catalog/hanfu/taoyuan-qingmeng/pink-set-01.jpg']],
        ],
    ],
    'HF-SLY-Z230903' => [
        'color' => [
            'black' => ['swatch' => '#1a1a1a', 'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/01.jpg'],
            'red' => ['swatch' => '#b83232', 'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/06.jpg'],
            'white' => ['swatch' => '#ffffff', 'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/02.jpg'],
        ],
    ],
    'HF-ZMXF-XH-2026' => [
        'color' => [
            'xianhe-black' => ['swatch' => '#101010', 'swatch_image' => '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg'],
            'xianhe-red' => ['swatch' => '#8c1d1d', 'swatch_image' => null],
            'qingzhu-white' => ['swatch' => '#eef2f0', 'swatch_image' => '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/06.jpg'],
        ],
    ],
];

$applyOptionMeta = static function (array &$option, array $meta): bool {
    $changed = false;
    if (array_key_exists('swatch', $meta) && trim((string)$meta['swatch']) !== '') {
        $option['swatch'] = (string)$meta['swatch'];
        $changed = true;
    }
    if (array_key_exists('swatch_image', $meta)) {
        $image = trim((string)($meta['swatch_image'] ?? ''));
        if ($image === '') {
            unset($option['swatch_image']);
        } else {
            $option['swatch_image'] = $image;
        }
        $changed = true;
    }

    return $changed;
};

$summary = [];
foreach ($previewBySku as $sku => $axisPreviews) {
    $product = $products->findBySku($websiteId, $sku);
    if ($product === null) {
        $summary[] = ['sku' => $sku, 'status' => 'skipped', 'reason' => 'product_not_found'];
        continue;
    }

    $productId = (int)$product->getId();
    $raw = $attributes->read($websiteId, 0, 'product', $productId, 'type_configuration')->value;
    $config = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
    if (!is_array($config) || !is_array($config['axes'] ?? null)) {
        $summary[] = ['sku' => $sku, 'status' => 'skipped', 'reason' => 'type_configuration_missing'];
        continue;
    }

    $patchedAxes = 0;
    foreach ($config['axes'] as &$axis) {
        if (!is_array($axis)) {
            continue;
        }
        $axisCode = strtolower(trim((string)($axis['code'] ?? '')));
        $previewMap = $axisPreviews[$axisCode] ?? null;
        if (!is_array($previewMap)) {
            continue;
        }
        foreach ($axis['options'] ?? [] as &$option) {
            if (!is_array($option)) {
                continue;
            }
            $value = trim((string)($option['value'] ?? $option['code'] ?? ''));
            $meta = $previewMap[$value] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            if ($applyOptionMeta($option, $meta)) {
                ++$patchedAxes;
            }
        }
        unset($option);
    }
    unset($axis);

    $attributes->writeTyped(
        $websiteId,
        0,
        'product',
        $productId,
        'type_configuration',
        '',
        'json',
        $config,
        false,
    );

    $summary[] = [
        'sku' => $sku,
        'product_id' => $productId,
        'status' => 'patched',
        'options_updated' => $patchedAxes,
    ];
}

$catalogCache->notifyCatalogChanged($websiteId, 'hanfu_variant_preview_patch');

echo json_encode(
    [
        'website_id' => $websiteId,
        'items' => $summary,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
) . PHP_EOL;
