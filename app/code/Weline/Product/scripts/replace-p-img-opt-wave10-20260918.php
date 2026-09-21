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
$done = '/tmp/p-img-opt-wave10-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/383_01-763ccd802a7e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752469198811/01-763ccd802a7e.jpg',
        'expect_id' => '1043bf4e-9125-4e21-9ab2-c341c9b97aec',
        'product_id' => '383',
        'before' => '750x750',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/373_01-700aedba16f2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/725522200081/01-700aedba16f2.jpg',
        'expect_id' => 'c6b72f79-6563-4527-80ea-19efc1358a54',
        'product_id' => '373',
        'before' => '750x750',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/516_01-eda78950b702.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/814109218553/01-eda78950b702.jpg',
        'expect_id' => 'ed7c536c-2678-4c6e-a658-392531f9a00c',
        'product_id' => '516',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/513_01-18e9d53d7e79.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/781772872647/01-18e9d53d7e79.jpg',
        'expect_id' => '28d52ed0-f793-4b54-9fa2-462df7b4d5e9',
        'product_id' => '513',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/503_01-d8bc079c9aa1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/739899986740/01-d8bc079c9aa1.jpg',
        'expect_id' => '231a5931-15c9-4a5b-b343-d5eab5b8de8c',
        'product_id' => '503',
        'before' => '1000x1000',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/501_01-c06d4412d6c0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/724670420560/01-c06d4412d6c0.jpg',
        'expect_id' => '426a3df7-17d4-4432-9eb7-bbf870ecc77a',
        'product_id' => '501',
        'before' => '1064x1064',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/495_01-7abe445b9337.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/715181166033/01-7abe445b9337.jpg',
        'expect_id' => '11c32699-a063-47fe-b515-e6728dfca58c',
        'product_id' => '495',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/470_01-a72337438026.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1047365225013/01-a72337438026.jpg',
        'expect_id' => '71a2544b-eaca-4c40-9f1e-2a711e817ead',
        'product_id' => '470',
        'before' => '1500x1500',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/460_01-ad415aee74f6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1008408346289/01-ad415aee74f6.jpg',
        'expect_id' => '324c2216-2be0-4e46-a71f-d19cfeb8b323',
        'product_id' => '460',
        'before' => '1440x1440',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/455_01-a6d5a9960201.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/793852679404/01-a6d5a9960201.jpg',
        'expect_id' => '59d3dc16-e1f7-4205-919d-f7ca53d50871',
        'product_id' => '455',
        'before' => '1024x1024',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/447_01-c09e4e68118b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706028294343/01-c09e4e68118b.jpg',
        'expect_id' => 'e5b8ba75-9cd0-4267-b5b4-49c9c7842ecb',
        'product_id' => '447',
        'before' => '960x960',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave10-20260918/out/434_01-656d2215d8b0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705421798362/01-656d2215d8b0.jpg',
        'expect_id' => '4d75e128-d10c-4383-9322-76a46f57427c',
        'product_id' => '434',
        'before' => '1338x1338',
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
