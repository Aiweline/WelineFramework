<?php

declare(strict_types=1);

/**
 * #189：主图/画廊按主图比例去垫边（blur-fill/灰框）+ 去噪，禁空放大
 * php app/code/Weline/Product/scripts/replace-189-main-gallery-ar.php
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
$dir = '/tmp/p189-out-20260916-113244-main-gallery';
$prefix = 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/';

$jobs = [
    ['file' => '01-hd-833f7c71d9f4.jpg', 'id' => '1bc24667-6c56-4273-91c1-d37491d30a95'],
    ['file' => '02-hd-d076e13cca8d.jpg', 'id' => '79d088ee-c4e9-43fa-a033-48096022f1f0'],
    ['file' => '03-hd-89b1a03b9894.jpg', 'id' => 'cf50630e-8116-4805-986f-6ad61f52b4b0'],
    ['file' => '04-hd-d8d7e7193d2a.jpg', 'id' => '64f95dfd-ba79-4128-a698-46e745e8a68c'],
    ['file' => '05-hd-d2d810579a4a.jpg', 'id' => '1bd809a8-3f2c-4afa-9d6c-4f5b5f43cdf1'],
    ['file' => '06-hd-e9926210e31b.jpg', 'id' => 'c82deb85-e9be-4c69-8058-9e046dc95b92'],
    ['file' => '07-hd-1c2ecd8f2d36.jpg', 'id' => '0cf3549d-f59e-4df2-a437-0326ca6a34ab'],
    ['file' => '08-hd-9e30f8237d06.jpg', 'id' => '985dc958-46c9-4036-b708-a6f71f2b2e13'],
];

foreach ($jobs as $j) {
    $path = $dir . '/' . $j['file'];
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
    $stream = fopen($path, 'rb');
    try {
        $desc = $library->replaceContent(
            $disk,
            $prefix . $j['file'],
            $stream,
            $j['file'],
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
    if ($aid !== '' && $aid !== $j['id']) {
        fwrite(STDERR, "id drift {$j['file']}: {$aid}\n");
        exit(1);
    }
    echo "OK {$j['file']} {$w}x{$h}\n";
}
echo "done\n";
