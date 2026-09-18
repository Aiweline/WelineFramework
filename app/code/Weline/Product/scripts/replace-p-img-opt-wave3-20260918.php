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
$done = '/tmp/p-img-opt-wave3-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/382_15-26509041a557.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/749119924927/15-26509041a557.jpg',
        'expect_id' => 'b47d261a-84a4-49bd-a597-4825bc8c93b2',
        'product_id' => '382',
        'before' => '534x401',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/380_05-4b361ecb9b98.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746374029121/05-4b361ecb9b98.jpg',
        'expect_id' => '2a070f9f-bff1-4afb-8ec3-f1a5706b8ab4',
        'product_id' => '380',
        'before' => '608x785',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/378_03-58ec2f05b159.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/03-58ec2f05b159.jpg',
        'expect_id' => '8b111571-7b99-4dd3-a92e-b8a6b6ddec02',
        'product_id' => '378',
        'before' => '608x745',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/374_06-580a6255557e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738673544725/06-580a6255557e.jpg',
        'expect_id' => 'a8e589a7-47c2-436d-9d9a-6a4fb0922497',
        'product_id' => '374',
        'before' => '767x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/363_11-4c666a940b07.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706299882617/11-4c666a940b07.jpg',
        'expect_id' => '30b1f7c8-578a-4b7c-9c61-9672c77aa4d6',
        'product_id' => '363',
        'before' => '782x470',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/360_05-412ee3ffa690.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715071130913/05-412ee3ffa690.jpg',
        'expect_id' => 'fc20fc4a-e292-48f8-823e-869d85e350d2',
        'product_id' => '360',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/347_05-01ed5ab7f869.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703732796408/05-01ed5ab7f869.jpg',
        'expect_id' => 'b8688295-7ade-46f7-907d-4552d7bd9ce3',
        'product_id' => '347',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/342_07-9580688fab12.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703375786953/07-9580688fab12.jpg',
        'expect_id' => 'aeda9dd1-6400-451d-9611-5603f3b3b62b',
        'product_id' => '342',
        'before' => '608x796',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/331_05-0c293024e937.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703149373866/05-0c293024e937.jpg',
        'expect_id' => '04167de8-c791-4692-97bb-b21e1a0bb0cd',
        'product_id' => '331',
        'before' => '608x784',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/291_02-8a0940758247.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1070868426782/02-8a0940758247.jpg',
        'expect_id' => '00fe06c8-a8e7-4d0c-9140-3d32f86c3adb',
        'product_id' => '291',
        'before' => '1200x1056',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/252_03-62087b48acf6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1012436385028/03-62087b48acf6.jpg',
        'expect_id' => 'fe6c35de-17e5-43c1-a548-f45f27d07d83',
        'product_id' => '252',
        'before' => '1920x1690',
    ],
    [
        'src' => '/tmp/p-img-opt-wave3-20260918/out/250_03-0800d2b1b45b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1003899862373/03-0800d2b1b45b.jpg',
        'expect_id' => '259493c9-ca78-4b5d-9818-4ba269408e64',
        'product_id' => '250',
        'before' => '1920x1564',
    ]
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
