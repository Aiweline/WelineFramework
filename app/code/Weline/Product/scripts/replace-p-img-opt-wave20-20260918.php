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
$done = '/tmp/p-img-opt-wave20-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/182_02-310a48f4be8d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826565016319/02-310a48f4be8d.jpg',
        'expect_id' => 'bd3b1eb6-c17d-48e5-a4cd-3df23a42b975',
        'product_id' => '182',
        'before' => '1313x1313',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/182_03-6cfb6f82128f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826565016319/03-6cfb6f82128f.jpg',
        'expect_id' => '95279f4b-49ad-45c1-9db7-9b672cf178e4',
        'product_id' => '182',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/182_04-cf5d319cf761.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826565016319/04-cf5d319cf761.jpg',
        'expect_id' => 'ddfc6a7d-fb5b-45e7-b54a-1626a6ebf97b',
        'product_id' => '182',
        'before' => '1320x1320',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/182_05-dc111296faca.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826565016319/05-dc111296faca.jpg',
        'expect_id' => '3a3f5a52-0680-4ae6-ab7e-c17e494631a4',
        'product_id' => '182',
        'before' => '1328x1328',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/182_08-03a0a5dbe3c7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826565016319/08-03a0a5dbe3c7.jpg',
        'expect_id' => '1d90b019-389f-4056-b5df-7194b0e86a36',
        'product_id' => '182',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/182_09-2aa88fc35924.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826565016319/09-2aa88fc35924.jpg',
        'expect_id' => '9c6e550b-09ef-40e5-aab4-1c954043ddab',
        'product_id' => '182',
        'before' => '1498x1498',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/182_10-e97f933f09da.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826565016319/10-e97f933f09da.jpg',
        'expect_id' => '83682874-38a4-4ce6-ab92-d8909b53b5fb',
        'product_id' => '182',
        'before' => '1320x1320',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/181_02-29eb18cf99be.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826520269606/02-29eb18cf99be.jpg',
        'expect_id' => 'b3af661d-53ee-43fa-8df7-a35e05619f00',
        'product_id' => '181',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/181_03-7947570d23cf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826520269606/03-7947570d23cf.jpg',
        'expect_id' => 'bc68e933-273c-40ab-b09d-1dcd17d10140',
        'product_id' => '181',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/181_04-ab3c8c537466.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826520269606/04-ab3c8c537466.jpg',
        'expect_id' => 'b7c550d6-3efb-4d67-9bda-8757ea606512',
        'product_id' => '181',
        'before' => '1024x1024',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/179_02-444047fd22bc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826289398238/02-444047fd22bc.jpg',
        'expect_id' => 'd12f11f4-aaea-4951-897c-4ce11f8d5721',
        'product_id' => '179',
        'before' => '1500x1500',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/179_04-11bc58e96876.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826289398238/04-11bc58e96876.jpg',
        'expect_id' => '6c870f0e-ba3a-45af-b3b0-dd49679714ed',
        'product_id' => '179',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/179_05-8a819dc27b59.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826289398238/05-8a819dc27b59.jpg',
        'expect_id' => 'e3379629-c172-48e6-92ee-fe9e52bdc856',
        'product_id' => '179',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/177_07-edee9ccfa819.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/825455560351/07-edee9ccfa819.jpg',
        'expect_id' => 'f4153099-29c1-4625-a4f3-1ac06a9b4948',
        'product_id' => '177',
        'before' => '1080x1080',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/175_05-c24a58d6e103.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824598076786/05-c24a58d6e103.jpg',
        'expect_id' => '08c413bc-c4d3-4c73-843c-fc58c0c4965b',
        'product_id' => '175',
        'before' => '1024x1024',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/173_02-5c6348cc388c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824333824966/02-5c6348cc388c.jpg',
        'expect_id' => '10ce4b69-0986-42db-8fb6-113f89f7212f',
        'product_id' => '173',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/173_04-78c1f042c408.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824333824966/04-78c1f042c408.jpg',
        'expect_id' => 'a887a60d-d5f6-4f74-ae65-8e60de1a5659',
        'product_id' => '173',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/173_05-cb908143d96c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824333824966/05-cb908143d96c.jpg',
        'expect_id' => 'cf034ec6-e12a-4832-812e-26512a301b3b',
        'product_id' => '173',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/171_02-cdd8a4347da8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824136170000/02-cdd8a4347da8.jpg',
        'expect_id' => '12851bbf-a68a-4149-9747-01c9a4af156f',
        'product_id' => '171',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/171_04-f67aa6df87b8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824136170000/04-f67aa6df87b8.jpg',
        'expect_id' => 'e7a9497f-fe13-4f39-8086-f27a3643505f',
        'product_id' => '171',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/171_05-13c8b6159eb3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824136170000/05-13c8b6159eb3.jpg',
        'expect_id' => 'c27741f1-c73c-46fe-97f7-5a7d0993eb3c',
        'product_id' => '171',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/117_04-hd-7bb80d49a5b8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-yueya/730813804749/04-hd-7bb80d49a5b8.jpg',
        'expect_id' => '1cfeb644-2728-468f-8c55-eefca3944240',
        'product_id' => '117',
        'before' => '1600x1600',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave20-20260918/out/117_05-hd-cbbec04d9de2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-yueya/730813804749/05-hd-cbbec04d9de2.jpg',
        'expect_id' => 'e12e3060-2bb3-4521-a863-3ed6025dba71',
        'product_id' => '117',
        'before' => '1024x1024',
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
    $bytes = filesize($path);
    $bpp = ($bytes * 8) / ($w * $h);
    if ($bpp < 1.2) {
        fwrite(STDERR, "bpp fail {$path}: {$bpp}\n");
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
