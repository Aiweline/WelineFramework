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
$done = '/tmp/p-img-opt-wave11-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/323_01-bdb83b41d5ac.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1050246114965/01-bdb83b41d5ac.jpg',
        'expect_id' => 'b184b471-0825-4a52-bd6c-7766d10ab079',
        'product_id' => '323',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/321_01-e95220fef678.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819215460099/01-e95220fef678.jpg',
        'expect_id' => '70fdd585-b0a3-45a1-888c-e2120bae1351',
        'product_id' => '321',
        'before' => '1280x1280',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/308_01-680ec893ccee.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/966204675886/01-680ec893ccee.jpg',
        'expect_id' => 'a180a056-ec92-4455-8b53-113c27c23968',
        'product_id' => '308',
        'before' => '1440x1440',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/304_01-574f2756808c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1041362147250/01-574f2756808c.jpg',
        'expect_id' => '5bde352b-5f9b-4a66-96d7-6cf6fe6de3b0',
        'product_id' => '304',
        'before' => '1500x1500',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/303_01-648c03b498e5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1039910431877/01-648c03b498e5.jpg',
        'expect_id' => '2fa6017d-a4aa-4a91-9435-0f9c6c8cd715',
        'product_id' => '303',
        'before' => '1920x1920',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/301_01-d021ecc0172b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1077963371242/01-d021ecc0172b.jpg',
        'expect_id' => 'cad7205c-c3c9-4323-bee0-5e29f7e419e5',
        'product_id' => '301',
        'before' => '1024x1024',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/299_01-3f704d00432b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1075946416699/01-3f704d00432b.jpg',
        'expect_id' => '4427899c-3d7d-4107-a5a8-aff1ce5a514b',
        'product_id' => '299',
        'before' => '1706x1706',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/298_01-a2d1193d8ec7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1075946281086/01-a2d1193d8ec7.jpg',
        'expect_id' => '5204567c-6638-4d93-80b8-6469830d6b04',
        'product_id' => '298',
        'before' => '1422x1422',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/296_01-708b0978fb90.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1073330742300/01-708b0978fb90.jpg',
        'expect_id' => '5819612a-a7ec-4baa-b4da-b29b05e2c5eb',
        'product_id' => '296',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/294_01-ef72cffdbd27.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1072486439963/01-ef72cffdbd27.jpg',
        'expect_id' => '91f7733b-c631-4e3e-b0d9-41e630ecef20',
        'product_id' => '294',
        'before' => '1600x1600',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/293_01-3eed949d4216.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1071901641727/01-3eed949d4216.jpg',
        'expect_id' => '66c24b7c-5cfa-4b7d-b10b-714c06c04964',
        'product_id' => '293',
        'before' => '1560x1560',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave11-20260918/out/290_01-f338c2c70c9a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1069516115630/01-f338c2c70c9a.jpg',
        'expect_id' => '09a440ef-b295-4af5-b801-eddfbdfe5397',
        'product_id' => '290',
        'before' => '1600x1600',
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
