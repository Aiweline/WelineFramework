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
$out = '/tmp/p-ar-wave7-20260917/out';
$done = '/tmp/p-ar-wave7-20260917/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/426_07-0a9eb9de3c40.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/704899052920/07-0a9eb9de3c40.jpg',
        'expect_id' => 'f2fe192b-fc48-4571-9fff-d6251aa7eea7',
        'product_id' => '426',
        'before' => '746x790',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/434_05-13442d962d5e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705421798362/05-13442d962d5e.jpg',
        'expect_id' => '5705fca9-7fc2-4c5e-b1ba-20df2792045d',
        'product_id' => '434',
        'before' => '1100x1425',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/437_05-0b5a1cbb8c43.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706146997638/05-0b5a1cbb8c43.jpg',
        'expect_id' => '4d30a1b1-958c-490e-8b5f-c1b2ec35f5b8',
        'product_id' => '437',
        'before' => '802x933',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/464_05-c756a808e0d5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1022640495788/05-c756a808e0d5.jpg',
        'expect_id' => '7e00f45e-ba9c-43de-bd28-e3f51794569f',
        'product_id' => '464',
        'before' => '1000x956',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/465_05-bb64b768f7a4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024199809660/05-bb64b768f7a4.jpg',
        'expect_id' => '36256b57-8073-484e-a965-efa7706f85a0',
        'product_id' => '465',
        'before' => '608x785',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/468_05-2c775e296f1f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1031798237076/05-2c775e296f1f.jpg',
        'expect_id' => 'af64c031-b4bd-4a00-90c5-91804f5087dc',
        'product_id' => '468',
        'before' => '608x783',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/495_05-bc1c944b9e28.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/715181166033/05-bc1c944b9e28.jpg',
        'expect_id' => '760e7132-b627-4b10-a361-0b0c230f6482',
        'product_id' => '495',
        'before' => '912x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/524_05-2e19c4f14366.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819244136219/05-2e19c4f14366.jpg',
        'expect_id' => '2771e53a-93d4-4bb5-857f-c60b88f76f43',
        'product_id' => '524',
        'before' => '608x780',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/534_05-a7856b5d1b6e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/900275160913/05-a7856b5d1b6e.jpg',
        'expect_id' => '053ed9be-cbaf-4d8d-9d43-e8ccbcbb1f8b',
        'product_id' => '534',
        'before' => '1200x1161',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/175_05-c24a58d6e103.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824598076786/05-c24a58d6e103.jpg',
        'expect_id' => '08c413bc-c4d3-4c73-843c-fc58c0c4965b',
        'product_id' => '175',
        'before' => '1199x1056',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/392_04-0d42d09228f5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/829018718081/04-0d42d09228f5.jpg',
        'expect_id' => 'de234db0-5f1b-4176-a717-943d91986d40',
        'product_id' => '392',
        'before' => '812x1017',
    ],
    [
        'src' => '/tmp/p-ar-wave7-20260917/out/432_05-9f18771d9383.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/670310368253/05-9f18771d9383.jpg',
        'expect_id' => '0bf5d95c-d1fa-456a-b511-d5d671ef714e',
        'product_id' => '432',
        'before' => '621x784',
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
