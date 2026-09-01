<?php

declare(strict_types=1);

/**
 * 从淘宝公开 listing 拉图并写入 pub/media，补全汉服商品图库。
 *
 * Usage: php app/code/Weline/Product/scripts/import-hanfu-media.php [website_id]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));

/** @var list<array{product_id:int,sku:string,slug:string,sources:list<string>}> */
$catalog = [
    [
        'product_id' => 26,
        'sku' => 'HF-TYQM-260303',
        'slug' => 'taoyuan-qingmeng',
        'sources' => [
            'https://img.alicdn.com/imgextra/i3/2213107394972/O1CN01tGQJZT1mbEMqJHEvo_!!2213107394972.jpg',
        ],
    ],
    [
        'product_id' => 27,
        'sku' => 'HF-SLY-Z230903',
        'slug' => 'shenlong-yin-zhuanghua-mamian',
        'sources' => [
            'https://gw.alicdn.com/imgextra/i4/2213107394972/O1CN01SRSjYe1mbE7QdTce8_!!2213107394972.jpg',
            'http://gw.alicdn.com/bao/uploaded/i1/2213107394972/O1CN01lvO39F1mbEEqy50sd_!!0-item_pic.jpg',
            'http://gw.alicdn.com/bao/uploaded/i1/2213107394972/O1CN01ERXcow1mbEHuRGbIb_!!4611686018427382172-0-item_pic.jpg',
            'http://gw.alicdn.com/bao/uploaded/i2/2213107394972/O1CN018tvrDH1mbEI2Rjg4C_!!4611686018427382172-0-item_pic.jpg',
            'http://gw.alicdn.com/bao/uploaded/i4/2213107394972/O1CN01buL7Vf1mbEMrI7fFW_!!4611686018427382172-0-item_pic.jpg',
            'http://gw.alicdn.com/bao/uploaded/i4/2213107394972/O1CN010bGdaB1mbEJlMJ4Oj_!!4611686018427382172-0-item_pic.jpg',
        ],
    ],
    [
        'product_id' => 28,
        'sku' => 'HF-ZMXF-XH-2026',
        'slug' => 'zuimeng-xifeng-xianhe-mamian',
        'sources' => [
            'https://img.alicdn.com/imgextra/i3/2208581694230/O1CN01BoLcO91h7ORlHlKcQ_!!2208581694230.jpg',
            'http://gw.alicdn.com/bao/uploaded/i4/2208581694230/O1CN01SnTxN01h7OGQOF43S_!!2208581694230.jpg',
            'http://gw.alicdn.com/bao/uploaded/i3/2208581694230/O1CN0167yip91h7OPsnXcFu_!!2208581694230.jpg',
            'http://gw.alicdn.com/bao/uploaded/i3/2208581694230/O1CN01QDeiGl1h7OIRyRRKb_!!2208581694230.jpg',
            'http://gw.alicdn.com/bao/uploaded/i3/2208581694230/O1CN01aVTjHy1h7OFAd5lSv_!!2208581694230.jpg',
            'http://gw.alicdn.com/bao/uploaded/i2/2208581694230/O1CN01CAJlYr1h7OJBJbmxq_!!2208581694230.jpg',
            'http://gw.alicdn.com/bao/uploaded/i3/2208581694230/O1CN01ZSwOWP1h7OFGMUuRg_!!2208581694230.jpg',
        ],
    ],
];

$root = dirname(__DIR__, 5);
$mediaRoot = $root . '/pub/media/catalog/hanfu';

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);
/** @var MediaRepository $mediaRepo */
$mediaRepo = ObjectManager::getInstance(MediaRepository::class);

$download = static function (string $url, string $targetFile): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (!is_dir(dirname($targetFile))) {
        mkdir(dirname($targetFile), 0775, true);
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    $fp = fopen($targetFile, 'wb');
    if ($fp === false) {
        curl_close($ch);
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_REFERER => 'https://www.taobao.com/',
    ]);
    $ok = curl_exec($ch) !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    fclose($fp);
    if (!$ok || !is_file($targetFile) || filesize($targetFile) < 1024) {
        @unlink($targetFile);
        return false;
    }

    return true;
};

$imported = [];
foreach ($catalog as $item) {
    $productId = (int)$item['product_id'];
    $slug = (string)$item['slug'];
    $sku = (string)$item['sku'];
    $dir = $mediaRoot . '/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

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
    foreach ($item['sources'] as $sourceUrl) {
        ++$position;
        $ext = '.jpg';
        $fileName = sprintf('%02d%s', $position, $ext);
        $absolute = $dir . '/' . $fileName;
        if (!$download($sourceUrl, $absolute)) {
            continue;
        }
        $publicPath = '/pub/media/catalog/hanfu/' . $slug . '/' . $fileName;
        $blobKey = 'hanfu-import-' . strtolower($slug) . '-' . $position;
        $mediaRepo->create($websiteId, [
            Media::schema_fields_PRODUCT_ID => $productId,
            Media::schema_fields_PATH => $publicPath,
            Media::schema_fields_BLOB_KEY => $blobKey,
            Media::schema_fields_POSITION => $position,
        ]);
        $saved[] = [
            'position' => $position,
            'path' => $publicPath,
            'source' => $sourceUrl,
            'bytes' => filesize($absolute),
        ];
    }

    $imported[] = [
        'product_id' => $productId,
        'sku' => $sku,
        'slug' => $slug,
        'images' => $saved,
    ];
}

try {
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)
        ->notifyCatalogChanged($websiteId, 'hanfu_media_import');
} catch (Throwable) {
}

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'media_root' => $mediaRoot,
    'products' => $imported,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
