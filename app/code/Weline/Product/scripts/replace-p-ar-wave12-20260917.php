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
$out = '/tmp/p-ar-wave12-20260917/out';
$done = '/tmp/p-ar-wave12-20260917/done.tsv';

$jobs = [
    [
        'src' => $out . '/373_05-babc090a6871.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/725522200081/05-babc090a6871.jpg',
        'expect_id' => 'e57c4a67-eb22-4ad0-8b9c-fcc2ecefdc48',
        'product_id' => '373',
        'before' => '623x750',
    ],
    [
        'src' => $out . '/497_07-bd23af1ce330.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/715367119667/07-bd23af1ce330.jpg',
        'expect_id' => 'f36ba989-3a23-4c3f-96d4-4b8bc27ae646',
        'product_id' => '497',
        'before' => '333x559',
    ],
    [
        'src' => $out . '/522_05-bef44e3a266e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819124077369/05-bef44e3a266e.jpg',
        'expect_id' => '511da7ca-56c5-4484-8791-6a8c552eaf6e',
        'product_id' => '522',
        'before' => '800x774',
    ],
    [
        'src' => $out . '/388_08-9d0ca27ccbc2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/769349326765/08-9d0ca27ccbc2.jpg',
        'expect_id' => 'ede30007-f52b-4794-9fa3-85c444b5927e',
        'product_id' => '388',
        'before' => '608x657',
    ],
    [
        'src' => $out . '/218_02-2c7d6cc3bc48.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/912814485918/02-2c7d6cc3bc48.jpg',
        'expect_id' => '049c84f2-e979-44b7-84df-8ecea5c1a757',
        'product_id' => '218',
        'before' => '1056x1200',
    ],
    [
        'src' => $out . '/227_02-6bafd4b0a8aa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/925302638410/02-6bafd4b0a8aa.jpg',
        'expect_id' => '5768b50d-0d96-4a2c-8a36-ff0171980b8f',
        'product_id' => '227',
        'before' => '1056x1200',
    ],
    [
        'src' => $out . '/260_02-ee811d4e8a07.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1042443716530/02-ee811d4e8a07.jpg',
        'expect_id' => 'dca88521-7e6b-4d33-843e-33ebb4794807',
        'product_id' => '260',
        'before' => '1056x1200',
    ],
    [
        'src' => $out . '/272_02-988a8514531d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1051635342005/02-988a8514531d.jpg',
        'expect_id' => '81e99935-ae3d-41b8-8bdd-8435828a4f90',
        'product_id' => '272',
        'before' => '1200x1116',
    ],
    [
        'src' => $out . '/436_04-484d4fa8f03d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706013682914/04-484d4fa8f03d.jpg',
        'expect_id' => '53296f74-5ec9-40b4-b525-12409f95a9ef',
        'product_id' => '436',
        'before' => '730x960',
    ],
    [
        'src' => $out . '/447_02-c8e1f02ed02d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706028294343/02-c8e1f02ed02d.jpg',
        'expect_id' => '3351bf4b-8f33-4bfb-aad6-c3fe83866132',
        'product_id' => '447',
        'before' => '960x924',
    ],
    [
        'src' => $out . '/460_03-6c1165208445.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1008408346289/03-6c1165208445.jpg',
        'expect_id' => '5b83f92b-a631-4898-8622-46f3c9430c7d',
        'product_id' => '460',
        'before' => '946x1000',
    ],
    [
        'src' => $out . '/518_04-9bb8428f6250.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/814270917745/04-9bb8428f6250.jpg',
        'expect_id' => '013b648c-11f1-4404-b8de-8ada856269bc',
        'product_id' => '518',
        'before' => '1000x949',
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
