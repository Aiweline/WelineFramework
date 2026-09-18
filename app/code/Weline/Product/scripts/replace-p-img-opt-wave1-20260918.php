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
$out = '/tmp/p-img-opt-wave1-20260918/out';
$done = '/tmp/p-img-opt-wave1-20260918/done.tsv';

$jobs = [
    [
        'src' => $out . '/537_08-68213b1eaefd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/08-68213b1eaefd.jpg',
        'expect_id' => '00e43c04-80f4-48ec-aa60-41721da77e12',
        'product_id' => '537',
        'before' => '371x496',
    ],
    [
        'src' => $out . '/531_09-abb270150d4c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/861289256056/09-abb270150d4c.jpg',
        'expect_id' => '1a9aa021-d022-4ca8-9742-369ed44723da',
        'product_id' => '531',
        'before' => '178x389',
    ],
    [
        'src' => $out . '/528_04-bf92a28f11b9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/853856286725/04-bf92a28f11b9.jpg',
        'expect_id' => 'd9d57d3b-804c-444a-9aa6-63abd49ae911',
        'product_id' => '528',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/526_02-e789f44987aa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/836115959517/02-e789f44987aa.jpg',
        'expect_id' => '9fbc1f77-e5ad-444d-8c97-b73c915f83fb',
        'product_id' => '526',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/523_02-250583ce3475.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819243236248/02-250583ce3475.jpg',
        'expect_id' => '3413f0b0-9e6b-4e1b-a7fb-08b5fc0701dc',
        'product_id' => '523',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/515_07-e8a12b445404.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/813955763895/07-e8a12b445404.jpg',
        'expect_id' => '79e24b18-ae6a-47ac-b8d4-3523cfd44414',
        'product_id' => '515',
        'before' => '636x788',
    ],
    [
        'src' => $out . '/508_24-b7898d4ed2a0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/24-b7898d4ed2a0.jpg',
        'expect_id' => 'b337f995-86bd-4843-baac-bffb450a0e68',
        'product_id' => '508',
        'before' => '481x348',
    ],
    [
        'src' => $out . '/504_11-b32dbee3a5cc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/11-b32dbee3a5cc.jpg',
        'expect_id' => 'b70c7af7-cfe9-4aa7-85cd-db01aca4ca64',
        'product_id' => '504',
        'before' => '997x942',
    ],
    [
        'src' => $out . '/500_02-f35e9f93a6cd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/02-f35e9f93a6cd.jpg',
        'expect_id' => '26e17b80-2a89-4578-bf9c-ae6808f54648',
        'product_id' => '500',
        'before' => '584x768',
    ],
    [
        'src' => $out . '/499_07-154a975a6b7a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/07-154a975a6b7a.jpg',
        'expect_id' => 'abf0d4db-a955-4ec0-a774-a2b08dacf99b',
        'product_id' => '499',
        'before' => '540x469',
    ],
    [
        'src' => $out . '/492_11-f5eb90460608.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/712515408993/11-f5eb90460608.jpg',
        'expect_id' => '093a8c96-5180-43f9-8757-5b7c97deeb8c',
        'product_id' => '492',
        'before' => '702x647',
    ],
    [
        'src' => $out . '/489_06-91b1894e05ff.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705643992653/06-91b1894e05ff.jpg',
        'expect_id' => '54bf5423-ec8f-448b-8dfa-4f2334de2479',
        'product_id' => '489',
        'before' => '912x1200',
    ],
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");

foreach ($jobs as $j) {
    $path = $j['src'];
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
    $after = "{$w}x{$h}";
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['before']}\t{$after}\t是\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$after} asset={$aid}\n";
}
fclose($fh);
echo "done\n";
