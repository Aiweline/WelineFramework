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
$out = '/tmp/p-ar-wave14-20260918/out';
$done = '/tmp/p-ar-wave14-20260918/done.tsv';

$jobs = [
    [
        'src' => $out . '/528_04-bf92a28f11b9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/853856286725/04-bf92a28f11b9.jpg',
        'expect_id' => 'd9d57d3b-804c-444a-9aa6-63abd49ae911',
        'product_id' => '528',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/363_05-37b8b0c2649d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706299882617/05-37b8b0c2649d.jpg',
        'expect_id' => '2d81363b-3b8f-49c1-bda8-45e91cc050ab',
        'product_id' => '363',
        'before' => '664x800',
    ],
    [
        'src' => $out . '/504_11-b32dbee3a5cc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/11-b32dbee3a5cc.jpg',
        'expect_id' => 'b70c7af7-cfe9-4aa7-85cd-db01aca4ca64',
        'product_id' => '504',
        'before' => '997x942',
    ],
    [
        'src' => $out . '/380_02-6c298e37964a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746374029121/02-6c298e37964a.jpg',
        'expect_id' => '11946585-3925-4683-9c57-42d79bf60aed',
        'product_id' => '380',
        'before' => '714x800',
    ],
    [
        'src' => $out . '/291_02-8a0940758247.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1070868426782/02-8a0940758247.jpg',
        'expect_id' => '00fe06c8-a8e7-4d0c-9140-3d32f86c3adb',
        'product_id' => '291',
        'before' => '1200x1056',
    ],
    [
        'src' => $out . '/237_02-66d90cfedc60.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/981174366560/02-66d90cfedc60.jpg',
        'expect_id' => 'f8779c73-297d-4d80-b5dc-357676f14a9f',
        'product_id' => '237',
        'before' => '1460x1920',
    ],
    [
        'src' => $out . '/331_05-0c293024e937.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703149373866/05-0c293024e937.jpg',
        'expect_id' => '04167de8-c791-4692-97bb-b21e1a0bb0cd',
        'product_id' => '331',
        'before' => '608x784',
    ],
    [
        'src' => $out . '/347_02-0a829b9ef18c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703732796408/02-0a829b9ef18c.jpg',
        'expect_id' => '92ee8055-aa62-4a2f-a0d0-6437c39cf2a9',
        'product_id' => '347',
        'before' => '723x800',
    ],
    [
        'src' => $out . '/515_03-22ea0210c07b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/813955763895/03-22ea0210c07b.jpg',
        'expect_id' => 'd13a595a-fc77-4c4f-a369-4286d67b8861',
        'product_id' => '515',
        'before' => '1056x1200',
    ],
    [
        'src' => $out . '/523_02-250583ce3475.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819243236248/02-250583ce3475.jpg',
        'expect_id' => '3413f0b0-9e6b-4e1b-a7fb-08b5fc0701dc',
        'product_id' => '523',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/342_05-a9e31991f464.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703375786953/05-a9e31991f464.jpg',
        'expect_id' => '26c32635-e802-42e2-8f48-c1edd429c51d',
        'product_id' => '342',
        'before' => '713x798',
    ],
    [
        'src' => $out . '/360_02-3d90a8ca1b60.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715071130913/02-3d90a8ca1b60.jpg',
        'expect_id' => 'aae9959b-b06f-4b3c-8e8b-8e499fc8e244',
        'product_id' => '360',
        'before' => '675x800',
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
