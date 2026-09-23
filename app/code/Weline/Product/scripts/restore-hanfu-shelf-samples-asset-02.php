<?php

declare(strict_types=1);

/**
 * 回滚 WO-BUILD-ASSET-02：把被 AI 货架样张覆盖的主图恢复为备份实拍，
 * 并删除 shelf-mounted / shelf-samples 乱加图。
 *
 * Usage: php app/code/Weline/Product/scripts/restore-hanfu-shelf-samples-asset-02.php [--dry-run]
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
$bakDir = $root . '/var/backup/hanfu-build-asset-02-20260923_084019';
$mountCopyDir = $mediaRoot . '/catalog/hanfu/r2/shelf-mounted';
$sampleDir = $mediaRoot . '/catalog/hanfu/r2/shelf-samples';

$productIds = [543, 542, 540, 538, 261, 258, 257, 246, 245, 244, 241, 237];

if (!is_dir($bakDir)) {
    fwrite(STDERR, "backup missing: {$bakDir}\n");
    exit(1);
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
$sql = "SELECT m.product_id, m.asset_id::text AS asset_id, a.object_key, a.mime_type, a.width, a.height
FROM w_product_ws_0_media m
JOIN w_weline_file_asset a ON a.asset_id = m.asset_id
WHERE m.product_id IN ($in) AND m.role = 'main' AND COALESCE(m.hidden, 0) = 0
ORDER BY m.product_id DESC";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$bakByProduct = [];
foreach (glob($bakDir . '/p*__*.jpg') ?: [] as $bakFile) {
    if (preg_match('/\/p(\d+)__/', $bakFile, $m)) {
        $bakByProduct[(int)$m[1]] = $bakFile;
    }
}

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;

$report = [];
foreach ($rows as $row) {
    $productId = (int)$row['product_id'];
    $assetId = (string)$row['asset_id'];
    $objectKey = (string)$row['object_key'];
    $bak = $bakByProduct[$productId] ?? null;
    $entry = [
        'product_id' => $productId,
        'asset_id' => $assetId,
        'object_key' => $objectKey,
        'backup' => $bak ? basename((string)$bak) : null,
        'status' => 'pending',
    ];
    if ($bak === null || !is_file($bak)) {
        $entry['status'] = 'missing-backup';
        $report[] = $entry;
        fwrite(STDERR, "missing backup for p{$productId}\n");
        continue;
    }

    $info = @getimagesize($bak);
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    if ($w < 1 || $h < 1) {
        $entry['status'] = 'bad-backup-dims';
        $report[] = $entry;
        continue;
    }

    if ($dryRun) {
        $entry['status'] = 'dry-run';
        $entry['dims'] = $w . 'x' . $h;
        $report[] = $entry;
        continue;
    }

    $stream = fopen($bak, 'rb');
    if ($stream === false) {
        fwrite(STDERR, "open fail {$bak}\n");
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
    }

    $got = strtolower((string)($desc['asset_id'] ?? ''));
    if ($got !== '' && $got !== strtolower($assetId)) {
        fwrite(STDERR, "asset id drift product={$productId} got={$got} expect={$assetId}\n");
        exit(1);
    }
    $entry['status'] = 'restored';
    $entry['dims'] = $w . 'x' . $h;
    $entry['after_sha256'] = (string)($desc['sha256'] ?? '');
    $report[] = $entry;
    echo "OK restore p{$productId} {$objectKey}\n";
}

$deleted = [];
if (!$dryRun) {
    foreach ([$mountCopyDir, $sampleDir] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (@unlink($file)) {
                $deleted[] = str_replace($mediaRoot, '/pub/media', $file);
            }
        }
    }

    /** @var StorefrontCatalogCacheCoordinator $cache */
    $cache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
    $cache->notifyCatalogChanged(0, 'hanfu_build_asset_02_shelf_restore', [
        'product_ids' => $productIds,
        'restored' => count(array_filter($report, static fn ($r) => ($r['status'] ?? '') === 'restored')),
    ]);
}

echo json_encode([
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'restored' => count(array_filter($report, static fn ($r) => ($r['status'] ?? '') === 'restored')),
    'product_ids' => $productIds,
    'backup_dir' => $bakDir,
    'deleted_files' => $deleted,
    'rows' => $report,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
