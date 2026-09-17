<?php

declare(strict_types=1);

/**
 * #173 扶摇：主图/画廊/规格/详情图
 * - 剥拼版外框后 **真·生图 AI outpaint → 1:1（1200×1200）**
 * - 禁止 cover 裁窄、色垫/反射糊边假拓
 *
 * php app/code/Weline/Product/scripts/replace-173-main-gallery-ar.php
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
$dir = '/tmp/p173-src-20260916-115700/final-outpaint-1x1';
$prefix = 'catalog/hanfu/1688/factory-huazhaoji-cx/824333824966/';

$jobs = [
    ['file' => '01-8da106409295.jpg', 'id' => '7cf04cce-d15a-4a73-9176-4660684b49e9'],
    ['file' => '02-5c6348cc388c.jpg', 'id' => '10ce4b69-0986-42db-8fb6-113f89f7212f'],
    ['file' => '03-9765a45b9b84.jpg', 'id' => 'cfb453f3-63e2-463d-a87e-b0edd4eeb320'],
    ['file' => '04-78c1f042c408.jpg', 'id' => 'a887a60d-d5f6-4f74-ae65-8e60de1a5659'],
    ['file' => '05-cb908143d96c.jpg', 'id' => 'cf034ec6-e12a-4832-812e-26512a301b3b'],
    ['file' => '06-3a3911ef12e8.jpg', 'id' => '5d66c1f0-edd2-4464-b194-e362a9f227b1'],
    ['file' => '07-c7f3b2b71045.jpg', 'id' => '785b6afc-7d8d-4ec6-a222-73fcbc45b07b'],
    ['file' => 'detail-01-6267af94b4fa.jpg', 'id' => '2f821fe5-5982-4528-a555-d914f4b2adde'],
    ['file' => 'detail-02-54a0cbe2c3bd.jpg', 'id' => '6874303d-f470-43da-b84e-b7a31802a8dc'],
    ['file' => 'detail-03-5f78a4d36d22.jpg', 'id' => 'facbd6b1-3fc4-47e7-827a-dcb5a0c6310f'],
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
echo "done method=ai_outpaint_1x1 color_pad=no cover_crop=no\n";
