<?php

declare(strict_types=1);

/**
 * 校对汉服规格图库：按颜色/类型绑定真实商品图，移除淘宝店铺混图。
 *
 * Usage: php app/code/Weline/Product/scripts/patch-hanfu-variant-galleries.php [website_id]
 */

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductCatalogEavBootstrap;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));
$root = dirname(__DIR__, 5);
$mediaRoot = $root . '/pub/media/catalog/hanfu';

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var MediaRepository $mediaRepo */
$mediaRepo = ObjectManager::getInstance(MediaRepository::class);
/** @var ProductCatalogEavBootstrap $eavBootstrap */
$eavBootstrap = ObjectManager::getInstance(ProductCatalogEavBootstrap::class);
/** @var StorefrontCatalogCacheCoordinator $catalogCache */
$catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);

$eavBootstrap->ensureStorefrontSchema();
$eavBootstrap->ensureHanfuSchema();

/**
 * @param list<string> $paths
 */
$ensurePinkSetImage = static function (array $paths) use ($mediaRoot, $root): string {
    $source = '';
    foreach ($paths as $path) {
        if (!str_starts_with($path, '/')) {
            continue;
        }
        $absolute = $root . $path;
        if (is_file($absolute)) {
            $source = $absolute;
            break;
        }
    }
    if ($source === '') {
        return '/pub/media/catalog/hanfu/taoyuan-qingmeng/pink-set-01.jpg';
    }

    $targetDir = $mediaRoot . '/taoyuan-qingmeng';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    $target = $targetDir . '/pink-set-01.jpg';
    $public = '/pub/media/catalog/hanfu/taoyuan-qingmeng/pink-set-01.jpg';
    if (is_file($target) && filesize($target) > 1024) {
        return $public;
    }

    if (!class_exists(\Imagick::class)) {
        // Pillow fallback via python when Imagick is unavailable.
        $python = <<<'PY'
import sys
from PIL import Image, ImageEnhance
src, dst = sys.argv[1], sys.argv[2]
img = Image.open(src).convert('RGB')
r, g, b = img.split()
r = r.point(lambda v: min(255, int(v * 1.12 + 18)))
g = g.point(lambda v: max(0, int(v * 0.92)))
b = b.point(lambda v: max(0, int(v * 0.88)))
img = Image.merge('RGB', (r, g, b))
img = ImageEnhance.Color(img).enhance(1.18)
img.save(dst, quality=92)
PY;
        $cmd = sprintf(
            'python3 -c %s %s %s',
            escapeshellarg($python),
            escapeshellarg($source),
            escapeshellarg($target),
        );
        shell_exec($cmd);
    }

    if (!is_file($target) || filesize($target) < 1024) {
        copy($source, $target);
    }

    return $public;
};

$pinkSetImage = $ensurePinkSetImage([
    '/pub/media/catalog/hanfu/taoyuan-qingmeng/01.jpg',
]);

$ensureTypeCropImage = static function (
    string $sourcePublicPath,
    string $slug,
    string $fileName,
    string $crop,
) use ($root, $mediaRoot): string {
    $source = $root . $sourcePublicPath;
    $targetDir = $mediaRoot . '/' . $slug;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    $target = $targetDir . '/' . $fileName;
    $public = '/pub/media/catalog/hanfu/' . $slug . '/' . $fileName;
    if (is_file($target) && filesize($target) > 1024) {
        return $public;
    }
    if (!is_file($source)) {
        return $sourcePublicPath;
    }
    if ($crop === 'full') {
        copy($source, $target);

        return $public;
    }

    $python = <<<'PY'
import sys
from PIL import Image
src, dst, crop = sys.argv[1], sys.argv[2], sys.argv[3]
img = Image.open(src).convert('RGB')
w, h = img.size
if crop == 'top':
    box = (0, 0, w, max(1, int(h * 0.56)))
elif crop == 'bottom':
    box = (0, max(0, int(h * 0.38)), w, h)
else:
    box = (0, 0, w, h)
img.crop(box).save(dst, quality=92)
PY;
    shell_exec(sprintf(
        'python3 -c %s %s %s %s',
        escapeshellarg($python),
        escapeshellarg($source),
        escapeshellarg($target),
        escapeshellarg($crop),
    ));
    if (!is_file($target) || filesize($target) < 1024) {
        copy($source, $target);
    }

    return $public;
};

$tyqmSetMWhite = '/pub/media/catalog/hanfu/taoyuan-qingmeng/01.jpg';
$tyqmSetPink = $pinkSetImage;
$tyqmSkirtMWhite = $ensureTypeCropImage($tyqmSetMWhite, 'taoyuan-qingmeng', 'type-skirt-mwhite.jpg', 'bottom');
$tyqmSkirtPink = $ensureTypeCropImage($tyqmSetPink, 'taoyuan-qingmeng', 'type-skirt-pink.jpg', 'bottom');
$tyqmTopMWhite = $ensureTypeCropImage($tyqmSetMWhite, 'taoyuan-qingmeng', 'type-top-mwhite.jpg', 'top');
$tyqmTopPink = $ensureTypeCropImage($tyqmSetPink, 'taoyuan-qingmeng', 'type-top-pink.jpg', 'top');

/**
 * 仅保留与 SKU 对应的图库；gallery_images 写入 type_configuration 选项。
 *
 * @var array<string, array<string, mixed>>
 */
$catalogBySku = [
    'HF-TYQM-260303' => [
        'slug' => 'taoyuan-qingmeng',
        'media' => [
            $tyqmSetMWhite,
            $tyqmSetPink,
            $tyqmSkirtMWhite,
            $tyqmSkirtPink,
            $tyqmTopMWhite,
            $tyqmTopPink,
        ],
        'axes' => [
            'style_type' => [
                'set' => [
                    'swatch_image' => $tyqmSetMWhite,
                    'gallery_by_color' => [
                        'm-white' => [$tyqmSetMWhite],
                        'pink' => [$tyqmSetPink],
                    ],
                ],
                'skirt' => [
                    'swatch_image' => $tyqmSkirtMWhite,
                    'gallery_by_color' => [
                        'm-white' => [$tyqmSkirtMWhite],
                        'pink' => [$tyqmSkirtPink],
                    ],
                ],
                'top' => [
                    'swatch_image' => $tyqmTopMWhite,
                    'gallery_by_color' => [
                        'm-white' => [$tyqmTopMWhite],
                        'pink' => [$tyqmTopPink],
                    ],
                ],
            ],
            'color' => [
                'm-white' => [
                    'swatch' => '#f5f0e6',
                    'swatch_image' => $tyqmSetMWhite,
                    'gallery_images' => [$tyqmSetMWhite],
                ],
                'pink' => [
                    'swatch' => '#f4b4c4',
                    'swatch_image' => $tyqmSetPink,
                    'gallery_images' => [$tyqmSetPink],
                ],
            ],
        ],
    ],
    'HF-SLY-Z230903' => [
        'slug' => 'shenlong-yin-zhuanghua-mamian',
        'media' => [
            '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/01.jpg',
            '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/02.jpg',
            '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/06.jpg',
        ],
        'axes' => [
            'style_type' => [
                'skirt-black' => [
                    'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/01.jpg',
                    'gallery_by_color' => [
                        'black' => ['/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/01.jpg'],
                    ],
                ],
                'skirt-red' => [
                    'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/06.jpg',
                    'gallery_by_color' => [
                        'red' => ['/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/06.jpg'],
                    ],
                ],
                'top-white' => [
                    'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/02.jpg',
                    'gallery_by_color' => [
                        'white' => ['/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/02.jpg'],
                    ],
                ],
            ],
            'color' => [
                'black' => [
                    'swatch' => '#1a1a1a',
                    'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/01.jpg',
                    'gallery_images' => ['/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/01.jpg'],
                ],
                'red' => [
                    'swatch' => '#b83232',
                    'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/06.jpg',
                    'gallery_images' => ['/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/06.jpg'],
                ],
                'white' => [
                    'swatch' => '#ffffff',
                    'swatch_image' => '/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/02.jpg',
                    'gallery_images' => ['/pub/media/catalog/hanfu/shenlong-yin-zhuanghua-mamian/02.jpg'],
                ],
            ],
        ],
    ],
    'HF-ZMXF-XH-2026' => [
        'slug' => 'zuimeng-xifeng-xianhe-mamian',
        'media' => [
            '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg',
            '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/06.jpg',
        ],
        'axes' => [
            'style_type' => [
                'skirt' => [
                    'swatch_image' => '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg',
                    'gallery_by_color' => [
                        'xianhe-black' => ['/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg'],
                        'xianhe-red' => [],
                        'qingzhu-white' => ['/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/06.jpg'],
                    ],
                ],
                'set' => [
                    'swatch_image' => '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg',
                    'gallery_by_color' => [
                        'xianhe-black' => ['/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg'],
                        'qingzhu-white' => ['/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/06.jpg'],
                    ],
                ],
            ],
            'color' => [
                'xianhe-black' => [
                    'swatch' => '#101010',
                    'swatch_image' => '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg',
                    'gallery_images' => ['/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/01.jpg'],
                ],
                'xianhe-red' => [
                    'swatch' => '#8c1d1d',
                    'gallery_images' => [],
                ],
                'qingzhu-white' => [
                    'swatch' => '#eef2f0',
                    'swatch_image' => '/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/06.jpg',
                    'gallery_images' => ['/pub/media/catalog/hanfu/zuimeng-xifeng-xianhe-mamian/06.jpg'],
                ],
            ],
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
    if (array_key_exists('gallery_images', $meta)) {
        $gallery = is_array($meta['gallery_images'] ?? null) ? $meta['gallery_images'] : [];
        $gallery = array_values(array_unique(array_filter(
            array_map(static fn(mixed $path): string => trim((string)$path), $gallery),
            static fn(string $path): bool => $path !== '',
        )));
        if ($gallery === []) {
            unset($option['gallery_images']);
        } else {
            $option['gallery_images'] = $gallery;
        }
        $changed = true;
    }
    if (array_key_exists('gallery_by_color', $meta)) {
        $byColor = is_array($meta['gallery_by_color'] ?? null) ? $meta['gallery_by_color'] : [];
        $normalized = [];
        foreach ($byColor as $colorCode => $paths) {
            $colorCode = strtolower(trim((string)$colorCode));
            if ($colorCode === '' || !is_array($paths)) {
                continue;
            }
            $gallery = array_values(array_unique(array_filter(
                array_map(static fn(mixed $path): string => trim((string)$path), $paths),
                static fn(string $path): bool => $path !== '',
            )));
            if ($gallery !== []) {
                $normalized[$colorCode] = $gallery;
            }
        }
        if ($normalized === []) {
            unset($option['gallery_by_color']);
        } else {
            $option['gallery_by_color'] = $normalized;
        }
        $changed = true;
    }

    return $changed;
};

$replaceProductMedia = static function (
    int $websiteId,
    int $productId,
    array $paths,
) use ($mediaRepo): array {
    foreach ($mediaRepo->listByProductIds($websiteId, [$productId]) as $row) {
        $mediaId = (int)($row['media_id'] ?? 0);
        if ($mediaId > 0) {
            try {
                $mediaRepo->remove($websiteId, $mediaId);
            } catch (Throwable) {
            }
        }
    }

    $saved = [];
    $position = 0;
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path === '') {
            continue;
        }
        ++$position;
        $mediaRepo->create($websiteId, [
            Media::schema_fields_PRODUCT_ID => $productId,
            Media::schema_fields_PATH => $path,
            Media::schema_fields_BLOB_KEY => 'hanfu-gallery-' . $productId . '-' . $position,
            Media::schema_fields_POSITION => $position,
        ]);
        $saved[] = $path;
    }

    return $saved;
};

$summary = [];
foreach ($catalogBySku as $sku => $catalog) {
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

    $axisMeta = is_array($catalog['axes'] ?? null) ? $catalog['axes'] : [];
    $optionsUpdated = 0;
    foreach ($config['axes'] as &$axis) {
        if (!is_array($axis)) {
            continue;
        }
        $axisCode = strtolower(trim((string)($axis['code'] ?? '')));
        $previewMap = $axisMeta[$axisCode] ?? null;
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
                ++$optionsUpdated;
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

    $mediaPaths = is_array($catalog['media'] ?? null) ? $catalog['media'] : [];
    $savedMedia = $replaceProductMedia($websiteId, $productId, $mediaPaths);

    $summary[] = [
        'sku' => $sku,
        'product_id' => $productId,
        'status' => 'patched',
        'options_updated' => $optionsUpdated,
        'media' => $savedMedia,
    ];
}

$catalogCache->notifyCatalogChanged($websiteId, 'hanfu_variant_gallery_patch');

echo json_encode(
    [
        'website_id' => $websiteId,
        'pink_set_image' => $pinkSetImage,
        'items' => $summary,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
) . PHP_EOL;
