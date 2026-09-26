<?php

declare(strict_types=1);

/**
 * #543 七月喜服：主图/画廊/规格 剥 blur-fill 侧边 → 真·AI outpaint→1:1 WebP replaceContent
 * 禁 cover / 色垫 / rembg / 空放大
 *
 * php app/code/Weline/Product/scripts/replace-543-main-gallery-variant-ar.php
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
$outDir = '/tmp/p543-slot1-20260926/out';
$prefix = 'catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/';

$jobs = [
    [
        'file' => '01-93bea8934b55.webp',
        'expect_id' => 'edfbd386-fbd5-486b-b125-4117d7c94e51',
        'role' => 'main',
    ],
    [
        'file' => '02-8fd0e7e9da10.webp',
        'expect_id' => '92148abe-e1c0-4fab-a6b2-5abc5bc6c24b',
        'role' => 'gallery+variant',
    ],
    [
        'file' => '03-10ff4e68d698.webp',
        'expect_id' => '147ccb35-c57c-4194-b3c6-7168e9c26618',
        'role' => 'gallery',
    ],
    [
        'file' => '04-072130c497af.webp',
        'expect_id' => '90ce57b0-ac49-49a4-96cf-071a7cbeb5b3',
        'role' => 'gallery+variant',
    ],
    [
        'file' => '05-824bbdab9f5f.webp',
        'expect_id' => '64a07cdb-651f-476d-b20f-065b9526bfb5',
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
    echo "OK #543 {$j['role']} {$j['file']} -> {$w}x{$h} pub={$pubSize[0]}x{$pubSize[1]} asset={$aid}\n";
}
echo "done\n";
