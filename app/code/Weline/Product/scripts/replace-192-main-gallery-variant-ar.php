<?php

declare(strict_types=1);

/**
 * #192 洛川：主图/画廊/规格 真·AI outpaint→1:1 WebP replaceContent
 * 禁 cover / 色垫 / rembg / 空放大
 *
 * php app/code/Weline/Product/scripts/replace-192-main-gallery-variant-ar.php
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
$outDir = '/tmp/p192-slot1-20260926/out';
$prefix = 'catalog/hanfu/1688/factory-huazhaoji-cx/831923117968/';

$jobs = [
    [
        'file' => '01-bb580a4267bc.webp',
        'expect_id' => 'c2a8ecd4-ae47-40d3-97ad-804169d6b361',
        'role' => 'main',
    ],
    [
        'file' => '02-1c66594d646f.webp',
        'expect_id' => 'd9124f64-c60c-4cde-8c81-52cd679178c0',
        'role' => 'gallery',
    ],
    [
        'file' => '03-aeb584c2470b.webp',
        'expect_id' => '4a833599-b741-494f-8372-1d9e5cdb2d57',
        'role' => 'gallery',
    ],
    [
        'file' => '04-06f6f9fcd250.webp',
        'expect_id' => '13c3e849-5b94-4eb4-92c1-eaf03037c1da',
        'role' => 'gallery',
    ],
    [
        'file' => '05-fa0c84129202.webp',
        'expect_id' => '4d150085-d628-49b4-95f8-402fdeaaccf1',
        'role' => 'gallery',
    ],
    [
        'file' => '06-d2d07cf84638.webp',
        'expect_id' => '4b305a80-d21f-4bf5-886d-29bd8df40b16',
        'role' => 'gallery+variant',
    ],
];

foreach ($jobs as $j) {
    $path = $outDir . '/' . $j['file'];
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

    $objectKey = $prefix . $j['file'];
    $stream = fopen($path, 'rb');
    try {
        $desc = $library->replaceContent(
            $disk,
            $objectKey,
            $stream,
            $j['file'],
            'image/webp',
            'zh_Hans_CN',
            $access,
            $w,
            $h,
        );
    } finally {
        fclose($stream);
    }
    $aid = (string)($desc['asset_id'] ?? '');
    if ($aid !== '' && $aid !== $j['expect_id']) {
        fwrite(STDERR, "id drift {$j['file']}: expect {$j['expect_id']} got {$aid}\n");
        exit(1);
    }
    $pub = $mediaRoot . $objectKey;
    $pubSize = is_file($pub) ? getimagesize($pub) : false;
    if (!is_array($pubSize) || (int)$pubSize[0] !== $w || (int)$pubSize[1] !== $h || (int)$pubSize[0] !== (int)$pubSize[1]) {
        $got = is_array($pubSize) ? ($pubSize[0] . 'x' . $pubSize[1]) : 'missing';
        fwrite(STDERR, "pub/media gate fail {$pub}: {$got}\n");
        exit(1);
    }
    echo "OK #192 {$j['role']} {$j['file']} -> {$w}x{$h} pub={$pubSize[0]}x{$pubSize[1]} asset={$aid}\n";
}
echo "done\n";
