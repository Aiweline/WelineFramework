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
$out = '/tmp/p-ar-wave3-20260917/out';
$done = '/tmp/p-ar-wave3-20260917/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/180_04-61bc063e68bb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826516641778/04-61bc063e68bb.jpg',
        'expect_id' => 'dc2afcda-6144-41c5-85c7-bf6a4f8b54cb',
        'product_id' => '180',
        'before' => '1200x1056',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/185_04-373ef0e1a7bf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829246319270/04-373ef0e1a7bf.jpg',
        'expect_id' => '83bcc3ae-36df-4b0d-8f07-acc205e7babc',
        'product_id' => '185',
        'before' => '1156x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/192_06-d2d07cf84638.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831923117968/06-d2d07cf84638.jpg',
        'expect_id' => '4b305a80-d21f-4bf5-886d-29bd8df40b16',
        'product_id' => '192',
        'before' => '760x1000',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/205_04-d89e06f56265.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/889762716786/04-d89e06f56265.jpg',
        'expect_id' => '1ebeba3f-2cc2-4b0d-93bc-02b79439707a',
        'product_id' => '205',
        'before' => '1200x1068',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/208_06-e01b5968461b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/895489782952/06-e01b5968461b.jpg',
        'expect_id' => '2235d2d6-b759-4cf2-905e-e47d9732cf02',
        'product_id' => '208',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/220_01-1628f83ef459.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/914738156426/01-1628f83ef459.jpg',
        'expect_id' => 'bd49e5b5-93ce-42cb-b4ae-f3b0e25dff0e',
        'product_id' => '220',
        'before' => '912x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/221_06-3e735b392a03.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/915769494776/06-3e735b392a03.jpg',
        'expect_id' => 'aa0ad4bf-9ebe-4226-b7ba-eac6a33f226d',
        'product_id' => '221',
        'before' => '972x1094',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/239_05-7f5247b6c8f0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/993891093247/05-7f5247b6c8f0.jpg',
        'expect_id' => 'c25b949a-0303-48e6-81d6-c2cb233fd933',
        'product_id' => '239',
        'before' => '1200x1108',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/271_03-e6de2ce19290.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1051348698602/03-e6de2ce19290.jpg',
        'expect_id' => '711f7ec7-12f4-4534-b541-3bf1d15f3851',
        'product_id' => '271',
        'before' => '1706x1502',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/274_02-58de504ea0bc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1053171587264/02-58de504ea0bc.jpg',
        'expect_id' => 'efd3bdf5-9658-4441-8db3-4e0dd1691a53',
        'product_id' => '274',
        'before' => '1654x1706',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/289_06-4fe9033388c4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1067667287726/06-4fe9033388c4.jpg',
        'expect_id' => '11356e12-59b9-466c-ba79-a3358d9b2793',
        'product_id' => '289',
        'before' => '897x1067',
    ],
    [
        'src' => '/tmp/p-ar-wave3-20260917/out/335_05-cad2018e5b82.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702861516531/05-cad2018e5b82.jpg',
        'expect_id' => 'e12f53b9-b387-4e69-a0c9-ff10d75ea208',
        'product_id' => '335',
        'before' => '630x800',
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
