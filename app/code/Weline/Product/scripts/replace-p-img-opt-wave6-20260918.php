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
$done = '/tmp/p-img-opt-wave6-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_10-13a09318c08e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/10-13a09318c08e.jpg',
        'expect_id' => 'ace29319-2776-455f-b240-e1cd6dd40adc',
        'product_id' => '477',
        'before' => '748x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_18-29fa56186ee4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/18-29fa56186ee4.jpg',
        'expect_id' => 'e2e2e17b-c0aa-4498-ba01-4fbce4b2385f',
        'product_id' => '477',
        'before' => '741x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_11-f9c72eefb49b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/11-f9c72eefb49b.jpg',
        'expect_id' => 'd524df78-afe8-4a1c-9181-d38e10ae8b27',
        'product_id' => '477',
        'before' => '740x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_13-3e53c166f99e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/13-3e53c166f99e.jpg',
        'expect_id' => '93a4fb78-8e06-4356-a041-230252f5090c',
        'product_id' => '477',
        'before' => '740x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_19-bc3c89a43481.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/19-bc3c89a43481.jpg',
        'expect_id' => 'b227121d-0135-4c9d-bfa7-2a2b3d6a0769',
        'product_id' => '477',
        'before' => '734x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_21-389d0570bbfb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/21-389d0570bbfb.jpg',
        'expect_id' => 'e63e786b-31bb-4139-93e5-c4009ac9d37b',
        'product_id' => '477',
        'before' => '731x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_25-f13adf2f7628.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/25-f13adf2f7628.jpg',
        'expect_id' => 'e261b948-9a91-4474-8971-6a24cb7bd370',
        'product_id' => '477',
        'before' => '726x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_24-bcc712fbd38c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/24-bcc712fbd38c.jpg',
        'expect_id' => '398585f9-3f05-4182-9b6f-641228b03efa',
        'product_id' => '477',
        'before' => '724x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_23-3dad3240c077.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/23-3dad3240c077.jpg',
        'expect_id' => '88a89bb2-d893-488c-9da1-cd1fadcdbb2d',
        'product_id' => '477',
        'before' => '708x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_22-dfd1fb9b3634.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/22-dfd1fb9b3634.jpg',
        'expect_id' => '326670e4-5804-493e-8906-4566a9803d99',
        'product_id' => '477',
        'before' => '693x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/477_17-fce187ce3a5a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/17-fce187ce3a5a.jpg',
        'expect_id' => '51819f71-1382-4139-8d63-d38f2173bc30',
        'product_id' => '477',
        'before' => '527x488',
    ],
    [
        'src' => '/tmp/p-img-opt-wave6-20260918/out/411_13-b9655e6a9237.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/13-b9655e6a9237.jpg',
        'expect_id' => '95051eb6-de2b-44e6-9388-6d9d96577eb0',
        'product_id' => '411',
        'before' => '660x748',
    ]
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");

foreach ($jobs as $j) {
    $path = $j['src'];
    if (!is_file($path)) { fwrite(STDERR, "missing {$path}\n"); exit(1); }
    $size = getimagesize($path);
    if (!is_array($size)) { fwrite(STDERR, "bad {$path}\n"); exit(1); }
    $w = (int)$size[0]; $h = (int)$size[1];
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.05) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h}\n"); exit(1);
    }
    $basename = basename($j['object_key']);
    $stream = fopen($path, 'rb');
    try {
        $desc = $library->replaceContent(
            $disk, $j['object_key'], $stream, $basename, 'image/jpeg', 'zh_Hans_CN', $access, $w, $h,
        );
    } finally { fclose($stream); }
    $aid = (string)($desc['asset_id'] ?? '');
    if ($aid !== '' && $aid !== $j['expect_id']) {
        fwrite(STDERR, "id drift {$basename}: expect {$j['expect_id']} got {$aid}\n"); exit(1);
    }
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['before']}\t{$w}x{$h}\t是\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$w}x{$h}\n";
}
fclose($fh);
echo "done\n";
