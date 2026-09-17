<?php

declare(strict_types=1);

/**
 * #189：去噪 + 禁止空放大（软图不拉分辨率）
 * php app/code/Weline/Product/scripts/replace-189-denoise-clarity.php
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
$dir = '/tmp/p189-backup-20260916-denoise-clarity/out';
$prefix = 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/';

$jobs = [
    ['file' => 'processed-clean-detail-16-981107dfcca9.jpg', 'id' => '015f4525-7791-4abc-ba21-2acabc3cd05b'],
    ['file' => 'processed-clean-detail-11-c35e3f7ae94a.jpg', 'id' => 'e831316a-c787-42ad-8db5-9e7d843b3160'],
    ['file' => 'processed-clean-detail-12-2d0cb3fb0cb8.jpg', 'id' => 'dbdc4771-402d-4a83-8fdd-fca08d09363c'],
    ['file' => 'processed-crop-detail-01-navy.jpg', 'id' => '3f8e8412-d918-4b36-ad02-c9ce4f232a63'],
    ['file' => 'processed-crop-macro-collar.jpg', 'id' => '2e271ae5-ad0f-4f3a-9fb5-a2c17a2939a5'],
    ['file' => 'processed-crop-macro-waist.jpg', 'id' => '813fb409-a3f3-4c69-96d9-b26e178600ce'],
    ['file' => 'processed-crop-detail-18-red.jpg', 'id' => '064df789-1703-4571-b414-e82aa9f54e5b'],
    ['file' => '02-hd-d076e13cca8d.jpg', 'id' => '79d088ee-c4e9-43fa-a033-48096022f1f0'],
    ['file' => '03-hd-89b1a03b9894.jpg', 'id' => 'cf50630e-8116-4805-986f-6ad61f52b4b0'],
    ['file' => '04-hd-d8d7e7193d2a.jpg', 'id' => '64f95dfd-ba79-4128-a698-46e745e8a68c'],
    ['file' => 'processed-crop-hem-print.jpg', 'id' => '2d7df0be-fc69-49be-8e72-05f78a6bb780'],
    ['file' => '01-hd-833f7c71d9f4.jpg', 'id' => '1bc24667-6c56-4273-91c1-d37491d30a95'],
    ['file' => 'processed-crop-detail-03-red.jpg', 'id' => '4531b576-e758-4823-b58c-7eb975e031ee'],
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
