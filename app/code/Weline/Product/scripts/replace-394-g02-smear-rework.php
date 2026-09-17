<?php

declare(strict_types=1);

/**
 * #394 gallery-02 裙摆拖影返工 → 1:1 replaceContent
 *
 * php app/code/Weline/Product/scripts/replace-394-g02-smear-rework.php
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
$mediaRoot = dirname(__DIR__, 5) . '/pub/media';
$src = '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets/p394-02-sq-rework2.jpg';
$objectKey = 'catalog/hanfu/1688/factory-caibao/845147765317/02-b39929260d49.jpg';
$expectId = '8d45151e-9e93-441b-8512-b1978987b7e7';
$bakDir = '/tmp/p394-g02-rework-20260916';
if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true) && !is_dir($bakDir)) {
    throw new RuntimeException('bak mkdir fail');
}

if (!is_file($src)) {
    fwrite(STDERR, "missing {$src}\n");
    exit(1);
}
$info = @getimagesize($src);
if ($info === false) {
    fwrite(STDERR, "bad image {$src}\n");
    exit(1);
}
[$w, $h] = [(int)$info[0], (int)$info[1]];
if ($w !== $h) {
    fwrite(STDERR, "not square {$w}x{$h}\n");
    exit(1);
}

$orig = $mediaRoot . '/' . $objectKey;
$bakPath = $bakDir . '/' . str_replace('/', '__', $objectKey);
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
echo "OK {$objectKey} {$w}x{$h} asset={$aid}\n";
