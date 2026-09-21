<?php
declare(strict_types=1);

/**
 * wave32 审查#2：仅 product_id=179 主图真 outpaint → 方图 replaceContent
 * 禁 cover / 色垫 / rembg / 空放大
 *
 * php app/code/Weline/Product/scripts/replace-p-ar-wave32-179-20260919.php
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
$mediaRoot = dirname(__DIR__, 5) . '/pub/media/';

$job = [
    'src' => '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets/179-main-outpaint-1x1.jpg',
    'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826289398238/01-7a830bac9b80.jpg',
    'expect_id' => '674acbea-e55e-429a-bc09-ea23154326ab',
    'product_id' => '179',
    'before' => '636x1024',
];

$path = $job['src'];
if (!is_file($path)) {
    fwrite(STDERR, "missing {$path}\n");
    exit(1);
}
$size = getimagesize($path);
if (!is_array($size)) {
    fwrite(STDERR, "bad image {$path}\n");
    exit(1);
}
$w = (int)$size[0];
$h = (int)$size[1];
if ($w < 1024 || $h < 1024 || $w !== $h) {
    fwrite(STDERR, "gate fail {$path}: {$w}x{$h}\n");
    exit(1);
}

$basename = basename($job['object_key']);
$stream = fopen($path, 'rb');
try {
    $desc = $library->replaceContent(
        $disk,
        $job['object_key'],
        $stream,
        $basename,
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
if ($aid !== '' && $aid !== $job['expect_id']) {
    fwrite(STDERR, "id drift {$basename}: expect {$job['expect_id']} got {$aid}\n");
    exit(1);
}
$pub = $mediaRoot . $job['object_key'];
$pubSize = is_file($pub) ? getimagesize($pub) : false;
if (!is_array($pubSize) || (int)$pubSize[0] !== $w || (int)$pubSize[1] !== $h || (int)$pubSize[0] !== (int)$pubSize[1]) {
    $got = is_array($pubSize) ? ($pubSize[0] . 'x' . $pubSize[1]) : 'missing';
    fwrite(STDERR, "pub/media gate fail {$pub}: {$got}\n");
    exit(1);
}
echo "OK #{$job['product_id']} {$basename} {$job['before']} -> {$w}x{$h} pub={$pubSize[0]}x{$pubSize[1]} asset={$aid}\n";
