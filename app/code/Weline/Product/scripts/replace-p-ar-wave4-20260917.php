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
$out = '/tmp/p-ar-wave4-20260917/out';
$done = '/tmp/p-ar-wave4-20260917/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/337_05-bfd9c040ba45.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703150917659/05-bfd9c040ba45.jpg',
        'expect_id' => '6f63bb13-2ef0-4aef-8f95-775f8d254e87',
        'product_id' => '337',
        'before' => '608x780',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/338_05-f8685895c62b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703151581541/05-f8685895c62b.jpg',
        'expect_id' => 'a9403608-fade-411a-8b48-63dad9b0c79c',
        'product_id' => '338',
        'before' => '608x753',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/341_05-e5e313b0602e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703156385151/05-e5e313b0602e.jpg',
        'expect_id' => 'e65cb67f-c33c-4166-a44d-227a973a99e6',
        'product_id' => '341',
        'before' => '608x765',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/343_05-1d352073d433.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703381854224/05-1d352073d433.jpg',
        'expect_id' => '1f2893be-66f0-429d-b254-574f3ed787f7',
        'product_id' => '343',
        'before' => '608x792',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/371_05-2aea2b133cd6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715280471085/05-2aea2b133cd6.jpg',
        'expect_id' => '3034fe91-9b7e-4994-b975-c8f3d086f5ec',
        'product_id' => '371',
        'before' => '608x785',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/393_05-fb12159cbfc7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/844856094796/05-fb12159cbfc7.jpg',
        'expect_id' => '69bc09f0-396e-4dfa-8b9d-e2a0ce37546f',
        'product_id' => '393',
        'before' => '760x950',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/404_05-ecfc33845895.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/892686216009/05-ecfc33845895.jpg',
        'expect_id' => '90f6711b-b306-4ea6-9708-265f20d7701a',
        'product_id' => '404',
        'before' => '812x1066',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/420_05-4b66fb83efd9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-jianzhou/590403504960/05-4b66fb83efd9.jpg',
        'expect_id' => '9875c4f6-006b-4378-9435-0c9912e72200',
        'product_id' => '420',
        'before' => '608x778',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/424_05-fc5d2cab67d4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-jianzhou/621692197989/05-fc5d2cab67d4.jpg',
        'expect_id' => '27ed2463-9c4a-409a-ab6c-cf32e53c3d30',
        'product_id' => '424',
        'before' => '608x797',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/425_05-f4932a6157df.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-jianzhou/632915566180/05-f4932a6157df.jpg',
        'expect_id' => '83ee508d-aff4-47d2-9df8-1a22f45af031',
        'product_id' => '425',
        'before' => '608x768',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/428_05-9f585ec32e3d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/704976283158/05-9f585ec32e3d.jpg',
        'expect_id' => 'c6a35eca-4bbe-4e6a-8427-6122184ee182',
        'product_id' => '428',
        'before' => '608x792',
    ],
    [
        'src' => '/tmp/p-ar-wave4-20260917/out/439_05-d51f2708dc4c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/707628780493/05-d51f2708dc4c.jpg',
        'expect_id' => '84e76f66-712e-40df-9029-0f2dd32ed56a',
        'product_id' => '439',
        'before' => '608x796',
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
