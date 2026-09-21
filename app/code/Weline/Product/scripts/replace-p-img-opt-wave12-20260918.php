<?php
declare(strict_types=1);

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
$done = '/tmp/p-img-opt-wave12-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/285_01-d34a296ba9a2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1060318347426/01-d34a296ba9a2.jpg',
        'expect_id' => '2f599f66-50c3-4915-99e1-1503449dbdad',
        'product_id' => '285',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/282_01-13e69ebca1d2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1057305598032/01-13e69ebca1d2.jpg',
        'expect_id' => '8e2a21fa-bf97-4b63-8958-40ffc5d1dbc4',
        'product_id' => '282',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/280_01-dfb8dbcf0d72.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1057297874596/01-dfb8dbcf0d72.jpg',
        'expect_id' => '1db851eb-5de0-4583-98d6-dd512da6421e',
        'product_id' => '280',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/279_01-d0f94e9c679a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1056727903561/01-d0f94e9c679a.jpg',
        'expect_id' => '7062fd0d-f070-4ec1-b661-868d386ceacb',
        'product_id' => '279',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/275_01-08ab4f9add9a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1053256436207/01-08ab4f9add9a.jpg',
        'expect_id' => '9ee3bbfd-4452-4fb6-b7ed-9a0c3f06f186',
        'product_id' => '275',
        'before' => '1500x1500',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/274_01-5d0974fb262b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1053171587264/01-5d0974fb262b.jpg',
        'expect_id' => 'b05d1ea2-f802-4651-a0a5-e06bede4c0d1',
        'product_id' => '274',
        'before' => '1706x1706',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/272_01-b18da853b484.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1051635342005/01-b18da853b484.jpg',
        'expect_id' => 'ec680b13-997d-429c-9a2d-d4a68a5a6993',
        'product_id' => '272',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/265_01-9c66502215b5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1047369506444/01-9c66502215b5.jpg',
        'expect_id' => '3142c17c-5121-462c-943c-a4d250843e2e',
        'product_id' => '265',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/264_01-4022790feb4c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1047359642728/01-4022790feb4c.jpg',
        'expect_id' => '3a58e773-4d61-47dc-83ce-120250b70a24',
        'product_id' => '264',
        'before' => '1920x1920',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/261_01-b33eee4c35ee.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1042500068281/01-b33eee4c35ee.jpg',
        'expect_id' => 'a6bb1a05-0091-4d3c-b56a-962fe1d0700f',
        'product_id' => '261',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/255_01-51a4c3e86655.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1031858679944/01-51a4c3e86655.jpg',
        'expect_id' => 'fa714a60-6351-4b14-98cf-5a13af603d07',
        'product_id' => '255',
        'before' => '1920x1920',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave12-20260918/out/251_01-54a076a6c218.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1005432264732/01-54a076a6c218.jpg',
        'expect_id' => '995283c1-ea21-42fb-be86-3d10e217998f',
        'product_id' => '251',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\twhy\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");

foreach ($jobs as $j) {
    $path = $j['src'];
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        exit(1);
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad {$path}\n");
        exit(1);
    }
    $w = (int)$size[0];
    $h = (int)$size[1];
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.05) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h}\n");
        exit(1);
    }
    $basename = basename($j['object_key']);
    $stream = fopen($path, 'rb');
    try {
        $desc = $library->replaceContent(
            $disk,
            $j['object_key'],
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
    if ($aid !== '' && $aid !== $j['expect_id']) {
        fwrite(STDERR, "id drift {$basename}: expect {$j['expect_id']} got {$aid}\n");
        exit(1);
    }
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['why']}\t{$j['before']}\t{$w}x{$h}\t是\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['why']} {$j['before']} -> {$w}x{$h}\n";
}
fclose($fh);
echo "done\n";
