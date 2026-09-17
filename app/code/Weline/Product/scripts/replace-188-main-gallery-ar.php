<?php

declare(strict_types=1);

/**
 * #188 红染：主图/画廊/规格 AI outpaint→1:1 + 详情裁净写回
 * php app/code/Weline/Product/scripts/replace-188-main-gallery-ar.php
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
$outMain = '/tmp/p188-work-20260916-143400/out';
$outDetail = '/tmp/p188-work-20260916-143400/detail';
$prefix = 'catalog/hanfu/1688/factory-huazhaoji-cx/829653521887/';

$jobs = [
    ['dir' => $outMain, 'file' => '01-9a309fc10319.jpg', 'id' => '55e19edf-c882-45ef-9fb5-f8ab7db9ee2e'],
    ['dir' => $outMain, 'file' => '02-3741e4ac27dc.jpg', 'id' => 'ae1a96f7-0c84-4f08-bb3f-9b9775319d23'],
    ['dir' => $outMain, 'file' => '03-33c16642a570.jpg', 'id' => 'b4dcffeb-1d4c-4c07-96d0-3ae45308f03b'],
    ['dir' => $outMain, 'file' => '04-9f481a905223.jpg', 'id' => '659b5838-5783-4053-9818-415d97dba7db'],
    ['dir' => $outMain, 'file' => '05-38c58a6cca32.jpg', 'id' => '7e12c326-a93d-4eab-a93e-a269f88c2886'],
    ['dir' => $outMain, 'file' => '06-e7503acfeffb.jpg', 'id' => 'a1004b6b-4dd3-4e2c-bd13-4187eb1f0d42'],
    ['dir' => $outDetail, 'file' => 'detail-01-0825a43bb599.jpg', 'id' => '5e8512df-15c9-470f-a104-4eb1b55920ea'],
    // detail-02 discarded — skip replace
    ['dir' => $outDetail, 'file' => 'detail-03-8618201e8417.jpg', 'id' => '3d089508-be5e-4c03-a4c9-a1326be3b6dd'],
    ['dir' => $outDetail, 'file' => 'detail-04-4f3103aeac1f.jpg', 'id' => '530860d8-73d2-4f4a-bb97-7c8c52141fa5'],
    ['dir' => $outDetail, 'file' => 'detail-05-d600c0e59fc1.jpg', 'id' => 'bc2162d4-edf5-49a6-9ab8-2777c004c7cd'],
    ['dir' => $outDetail, 'file' => 'detail-06-d82756f6f82b.jpg', 'id' => 'ed5f8b65-8aac-4589-8e20-be00904456e1'],
    ['dir' => $outDetail, 'file' => 'detail-07-6734eb3ef38a.jpg', 'id' => 'd0221ce9-f604-4992-abbb-bb5466e41c98'],
    ['dir' => $outDetail, 'file' => 'detail-08-c4563de1bd2e.jpg', 'id' => '5bece2e2-d29a-4b5c-bef2-804c8a3b8b2b'],
    ['dir' => $outDetail, 'file' => 'detail-09-51091ae7bbf6.jpg', 'id' => 'ccc7758c-d6ab-45c3-a5cf-1861f3728e3d'],
    ['dir' => $outDetail, 'file' => 'detail-10-dea7a2a65562.jpg', 'id' => '23e26e18-c0ed-49dc-901d-ac799941334a'],
    ['dir' => $outDetail, 'file' => 'detail-11-c5add1dbca2c.jpg', 'id' => '52978184-fbd7-426a-99b5-910e8ce0549b'],
    ['dir' => $outDetail, 'file' => 'detail-12-f6277bbf0a93.jpg', 'id' => 'ea297bc8-2be8-42f4-9d91-dfecb68e3186'],
];

foreach ($jobs as $j) {
    $path = $j['dir'] . '/' . $j['file'];
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
    echo "OK {$j['file']} {$w}x{$h} asset={$aid}\n";
}
echo "done\n";
