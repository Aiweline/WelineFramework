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
$out = '/tmp/p-ar-wave6-20260917/out';
$done = '/tmp/p-ar-wave6-20260917/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/228_05-8f19f54c7ca4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/928182857946/05-8f19f54c7ca4.jpg',
        'expect_id' => 'ec9000f0-3468-45d4-ad82-4b7ee296794a',
        'product_id' => '228',
        'before' => '1200x976',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/251_03-e0006fa1cae1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1005432264732/03-e0006fa1cae1.jpg',
        'expect_id' => '9dd3d2af-bf21-4937-b050-67cef50576ec',
        'product_id' => '251',
        'before' => '1056x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/303_07-9946806b99e0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1039910431877/07-9946806b99e0.jpg',
        'expect_id' => 'd202a29c-efed-42ae-8576-dfb365017d6d',
        'product_id' => '303',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/345_05-7752a8696b38.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703613351823/05-7752a8696b38.jpg',
        'expect_id' => '587e579c-ba77-4cb0-8ae5-f7ad6dbd93b2',
        'product_id' => '345',
        'before' => '704x772',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/348_05-7ed155e63661.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703734696852/05-7ed155e63661.jpg',
        'expect_id' => 'a9683868-f62d-4da9-a4c3-86eecf4e568a',
        'product_id' => '348',
        'before' => '730x800',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/354_02-06e4375889b1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706077621955/02-06e4375889b1.jpg',
        'expect_id' => '25f59509-4b0b-44af-8fa1-911ddaa7d708',
        'product_id' => '354',
        'before' => '800x756',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/359_05-6f36f763becb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706524551613/05-6f36f763becb.jpg',
        'expect_id' => 'e6d70f5e-c23c-498a-ab90-71d39ff81534',
        'product_id' => '359',
        'before' => '754x796',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/361_05-c0d8516e959f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715071322877/05-c0d8516e959f.jpg',
        'expect_id' => '4f80ec60-7eac-4133-a490-20a8afbc564a',
        'product_id' => '361',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/372_05-5c5ab7d50ea3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715935194508/05-5c5ab7d50ea3.jpg',
        'expect_id' => '95c373cc-62f3-459a-b931-e742e6c288d1',
        'product_id' => '372',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/386_05-575054bb6cd1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/756217260589/05-575054bb6cd1.jpg',
        'expect_id' => 'ad2367b1-0b6c-4904-bc55-704bd90f144d',
        'product_id' => '386',
        'before' => '608x787',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/397_05-41321198de47.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855437132797/05-41321198de47.jpg',
        'expect_id' => 'ca37bf34-706e-4df9-832a-f202b4c30be5',
        'product_id' => '397',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-ar-wave6-20260917/out/416_05-e9ea58315ca2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702864828198/05-e9ea58315ca2.jpg',
        'expect_id' => 'c85eb696-568f-45a1-b2d8-ac92da38a9e8',
        'product_id' => '416',
        'before' => '608x800',
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
