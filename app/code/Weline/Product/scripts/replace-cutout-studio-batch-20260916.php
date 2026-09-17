<?php

declare(strict_types=1);

/**
 * 黑底抠图 → 连续棚景整幅 replaceContent（禁 rembg）
 * 覆盖 #194 / #321 / #302 扫描命中资产
 *
 * php app/code/Weline/Product/scripts/replace-cutout-studio-batch-20260916.php
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
$assets = '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets';
$mediaRoot = dirname(__DIR__, 5) . '/pub/media';
$bak = '/tmp/p-cutout-backup-20260916';
if (!is_dir($bak) && !mkdir($bak, 0775, true) && !is_dir($bak)) {
    throw new RuntimeException('bak mkdir fail');
}

$jobs = [
    // #194 黑山茶
    [
        'src' => $assets . '/p194-03-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831939753369/03-9319f0f12797.jpg',
        'expect_id' => '7c8f72b3-5bde-4a33-b4a1-1a4457a187c9',
    ],
    [
        'src' => $assets . '/p194-04-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831939753369/04-46be7b8fa045.jpg',
        'expect_id' => '09c2a50a-9845-465f-97a1-eae2966687fc',
    ],
    [
        'src' => $assets . '/p194-05-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831939753369/05-3f861418666a.jpg',
        'expect_id' => 'a530c1b4-18f3-4f5d-8215-041c2d667137',
    ],
    // #321 黑山茶（新款）
    [
        'src' => $assets . '/p321-03-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819215460099/03-033a57bd933d.jpg',
        'expect_id' => '0b9b4f79-f8fe-44e1-b9b7-ff78bf57924e',
    ],
    [
        'src' => $assets . '/p321-04-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819215460099/04-d28bb43ff26b.jpg',
        'expect_id' => '1d5f96ad-5c08-4e40-b4ea-17bacc4cacf6',
    ],
    // #302 洛青玄
    [
        'src' => $assets . '/p302-03-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/03-564ab55756de.jpg',
        'expect_id' => '15491d23-08b6-4f56-995b-545b6e8a2b08',
    ],
    [
        'src' => $assets . '/p302-04-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/04-033bf646515f.jpg',
        'expect_id' => '032d30c6-32d6-429e-a556-105d4952ff1c',
    ],
    [
        'src' => $assets . '/p302-06-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/06-59b77a1177b3.jpg',
        'expect_id' => '766b269b-130f-4f94-922b-fd2e2fada304',
    ],
    [
        'src' => $assets . '/p302-07-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/07-90906a0a0e07.jpg',
        'expect_id' => 'd5d7228e-17bc-4ecc-a08a-67286dbda9fa',
    ],
    [
        'src' => $assets . '/p302-08-studio.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/08-7f8e7eeff901.jpg',
        'expect_id' => 'df04efb2-5464-4e37-a4b2-a1fb441f4bb3',
    ],
];

foreach ($jobs as $j) {
    if (!is_file($j['src'])) {
        fwrite(STDERR, "missing {$j['src']}\n");
        exit(1);
    }
    $info = @getimagesize($j['src']);
    if ($info === false) {
        fwrite(STDERR, "bad image {$j['src']}\n");
        exit(1);
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    $orig = $mediaRoot . '/' . $j['object_key'];
    $bakPath = $bak . '/' . str_replace('/', '__', $j['object_key']);
    if (is_file($orig) && !is_file($bakPath)) {
        if (!is_dir(dirname($bakPath))) {
            mkdir(dirname($bakPath), 0775, true);
        }
        copy($orig, $bakPath);
        echo "bak {$bakPath}\n";
    }
    $stream = fopen($j['src'], 'rb');
    try {
        $desc = $library->replaceContent(
            $disk,
            $j['object_key'],
            $stream,
            basename($j['object_key']),
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
    if ($aid !== '' && strtolower($aid) !== strtolower($j['expect_id'])) {
        fwrite(STDERR, "id drift {$j['object_key']} {$aid}\n");
        exit(1);
    }
    echo 'OK ' . basename($j['object_key']) . " {$w}x{$h}\n";
}
echo "done " . count($jobs) . "\n";
