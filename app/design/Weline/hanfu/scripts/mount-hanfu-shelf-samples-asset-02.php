<?php

declare(strict_types=1);

/**
 * WO-BUILD-ASSET-02：将货架样张轮换挂到首页可见核心 SKU 主图（replaceContent）。
 * 禁 Ollama；不再生图。样张源：pub/media/catalog/hanfu/r2/shelf-samples/
 *
 * Usage: php app/code/Weline/Product/scripts/mount-hanfu-shelf-samples-asset-02.php [--dry-run]
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Storage\Api\Data\StorageDiskCode;

$_SERVER['WELINE_WEBSITE_ID'] = '0';

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__, 5);
$mediaRoot = $root . '/pub/media';
$sampleDir = $mediaRoot . '/catalog/hanfu/r2/shelf-samples';
$mountCopyDir = $mediaRoot . '/catalog/hanfu/r2/shelf-mounted';
$stamp = date('Ymd_His');
$bakDir = $root . '/var/backup/hanfu-build-asset-02-' . $stamp;

/** 首页精选/特价可见核心 SKU（本波 12） */
$productIds = [543, 542, 540, 538, 261, 258, 257, 246, 245, 244, 241, 237];
$samples = [
    $sampleDir . '/changan-hanfu-shelf-01-1200x1200.webp',
    $sampleDir . '/changan-hanfu-shelf-02-1200x1200.webp',
    $sampleDir . '/changan-hanfu-shelf-03-1200x1200.webp',
    $sampleDir . '/changan-hanfu-shelf-04-1200x1200.webp',
];

foreach ($samples as $sample) {
    if (!is_file($sample)) {
        fwrite(STDERR, "missing sample {$sample}\n");
        exit(1);
    }
}

$env = include $root . '/app/etc/env.php';
$db = $env['db']['master'];
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'], $db['hostport'], $db['database']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$in = implode(',', array_map('intval', $productIds));
$sql = "SELECT m.product_id, m.asset_id::text AS asset_id, a.object_key, a.mime_type
FROM w_product_ws_0_media m
JOIN w_weline_file_asset a ON a.asset_id = m.asset_id
WHERE m.product_id IN ($in) AND m.role = 'main' AND COALESCE(m.hidden, 0) = 0
ORDER BY m.product_id DESC";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== count($productIds)) {
    fwrite(STDERR, 'main media count mismatch got=' . count($rows) . ' expect=' . count($productIds) . "\n");
    exit(1);
}

$webpToJpeg = static function (string $webpPath, string $jpegPath): array {
    if (!function_exists('imagecreatefromwebp')) {
        throw new RuntimeException('GD webp support missing');
    }
    $im = @imagecreatefromwebp($webpPath);
    if ($im === false) {
        throw new RuntimeException('webp decode fail: ' . $webpPath);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if (!is_dir(dirname($jpegPath))) {
        mkdir(dirname($jpegPath), 0775, true);
    }
    if (!imagejpeg($im, $jpegPath, 90)) {
        imagedestroy($im);
        throw new RuntimeException('jpeg encode fail: ' . $jpegPath);
    }
    imagedestroy($im);

    return [$w, $h];
};

if (!$dryRun) {
    if (is_dir($bakDir)) {
        fwrite(STDERR, "bak exists {$bakDir}\n");
        exit(1);
    }
    mkdir($bakDir, 0775, true);
    mkdir($mountCopyDir, 0775, true);
}

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;

$report = [];
$i = 0;
foreach ($rows as $row) {
    $productId = (int)$row['product_id'];
    $assetId = (string)$row['asset_id'];
    $objectKey = (string)$row['object_key'];
    $sampleIdx = $i % 4;
    $sampleWebp = $samples[$sampleIdx];
    $sampleLabel = 'shelf-0' . ($sampleIdx + 1);
    $i++;

    $origAbs = $mediaRoot . '/' . $objectKey;
    $workJpeg = sys_get_temp_dir() . '/hanfu-asset02-p' . $productId . '.jpg';
    [$w, $h] = $webpToJpeg($sampleWebp, $workJpeg);

    $entry = [
        'product_id' => $productId,
        'asset_id' => $assetId,
        'object_key' => $objectKey,
        'sample' => $sampleLabel,
        'dims' => $w . 'x' . $h,
        'status' => 'pending',
    ];

    if ($dryRun) {
        $entry['status'] = 'dry-run';
        $report[] = $entry;
        @unlink($workJpeg);
        continue;
    }

    if (is_file($origAbs)) {
        $bakName = 'p' . $productId . '__' . basename($objectKey);
        if (!copy($origAbs, $bakDir . '/' . $bakName)) {
            fwrite(STDERR, "bak fail {$origAbs}\n");
            exit(1);
        }
        $entry['backup'] = $bakName;
    }

    $mountName = 'product-' . $productId . '-' . $sampleLabel . '-1200x1200.webp';
    if (!copy($sampleWebp, $mountCopyDir . '/' . $mountName)) {
        fwrite(STDERR, "mount copy fail {$mountName}\n");
        exit(1);
    }
    $entry['mount_copy'] = '/pub/media/catalog/hanfu/r2/shelf-mounted/' . $mountName;

    $stream = fopen($workJpeg, 'rb');
    if ($stream === false) {
        fwrite(STDERR, "open fail {$workJpeg}\n");
        exit(1);
    }
    try {
        $desc = $library->replaceContent(
            $disk,
            $objectKey,
            $stream,
            basename($objectKey),
            'image/jpeg',
            'zh_Hans_CN',
            $access,
            $w,
            $h,
        );
    } finally {
        fclose($stream);
        @unlink($workJpeg);
    }

    $got = strtolower((string)($desc['asset_id'] ?? ''));
    if ($got !== '' && $got !== strtolower($assetId)) {
        fwrite(STDERR, "asset id drift product={$productId} got={$got} expect={$assetId}\n");
        exit(1);
    }
    $entry['status'] = 'mounted';
    $entry['after_sha256'] = (string)($desc['sha256'] ?? '');
    $report[] = $entry;
    echo "OK p{$productId} {$sampleLabel} {$objectKey}\n";
}

if (!$dryRun) {
    /** @var StorefrontCatalogCacheCoordinator $cache */
    $cache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
    $cache->notifyCatalogChanged(0, 'hanfu_build_asset_02_shelf_mount', [
        'product_ids' => $productIds,
        'count' => count($report),
    ]);
}

echo json_encode([
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'mounted' => count(array_filter($report, static fn ($r) => ($r['status'] ?? '') === 'mounted')),
    'product_ids' => $productIds,
    'backup_dir' => $dryRun ? null : $bakDir,
    'mount_copy_dir' => '/pub/media/catalog/hanfu/r2/shelf-mounted/',
    'rows' => $report,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
