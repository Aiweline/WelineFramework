<?php

declare(strict_types=1);

/**
 * #200 凤鸣在竹 · 03 黑底抠图 → 连续棚景整幅（禁 rembg）
 *
 * php app/code/Weline/Product/scripts/replace-200-03-anti-rembg.php
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;

$src = '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets/p200-03-studio-restore.jpg';
$objectKey = 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/03-c444c6fce848.jpg';
$expectId = '9ebbd243-6dc2-41aa-acc5-30516679afda';
$w = 864;
$h = 1152;

if (!is_file($src)) {
    fwrite(STDERR, "missing {$src}\n");
    exit(1);
}

$mediaRoot = dirname(__DIR__, 5) . '/pub/media';
$orig = $mediaRoot . '/' . $objectKey;
$bakDir = '/tmp/p200-backup-20260916-anti-rembg';
if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true) && !is_dir($bakDir)) {
    throw new RuntimeException('bak mkdir fail');
}
$bakPath = $bakDir . '/' . basename($objectKey);
if (is_file($orig) && !is_file($bakPath)) {
    copy($orig, $bakPath);
    echo "bak {$bakPath}\n";
}

$stream = fopen($src, 'rb');
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

$aid = (string)($desc['asset_id'] ?? '');
if ($aid !== '' && strtolower($aid) !== strtolower($expectId)) {
    fwrite(STDERR, "id drift {$objectKey} {$aid}\n");
    exit(1);
}

$im = @getimagesize($orig);
echo 'OK 03 ' . ($im[0] ?? '?') . 'x' . ($im[1] ?? '?') . " asset={$aid}\n";
echo "done\n";
