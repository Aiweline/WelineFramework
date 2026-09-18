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
$out = '/tmp/p-ar-wave13-20260917/out';
$done = '/tmp/p-ar-wave13-20260917/done.tsv';

$jobs = [
    [
        'src' => $out . '/375_05-8194b9771773.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738962342070/05-8194b9771773.jpg',
        'expect_id' => '60fe4719-bb58-4971-9d1a-ef8d91a38921',
        'product_id' => '375',
        'before' => '800x762',
    ],
    [
        'src' => $out . '/403_04-e399f8186290.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857385272130/04-e399f8186290.jpg',
        'expect_id' => '7a9bafde-4444-48ee-9053-9c0d7b28d593',
        'product_id' => '403',
        'before' => '812x1061',
    ],
    [
        'src' => $out . '/459_02-4a5f56ba8fac.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1007764993817/02-4a5f56ba8fac.jpg',
        'expect_id' => 'a322bfc3-40af-4cc5-9cd1-5b41c7f083fe',
        'product_id' => '459',
        'before' => '880x1000',
    ],
    [
        'src' => $out . '/525_02-55ca499c1abc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/834116329033/02-55ca499c1abc.jpg',
        'expect_id' => '7d69dfc7-916b-4454-8be4-eede3dd4ebae',
        'product_id' => '525',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/535_05-641ff7f87d0a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/901203282084/05-641ff7f87d0a.jpg',
        'expect_id' => '4da4ecb3-feea-42e2-af93-53a3b23cff69',
        'product_id' => '535',
        'before' => '608x747',
    ],
    [
        'src' => $out . '/350_05-14f1e3b9645b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704019401867/05-14f1e3b9645b.jpg',
        'expect_id' => 'f36f55e7-7f42-45d8-ae7c-b87288108524',
        'product_id' => '350',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/383_05-d0e077e9a5d5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752469198811/05-d0e077e9a5d5.jpg',
        'expect_id' => 'f17fb801-4847-4d6d-8973-e8db8febc03b',
        'product_id' => '383',
        'before' => '570x742',
    ],
    [
        'src' => $out . '/384_05-7afb019a71c2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752544903797/05-7afb019a71c2.jpg',
        'expect_id' => 'd038f164-6528-434e-9dfc-20a17447d7a2',
        'product_id' => '384',
        'before' => '570x750',
    ],
    [
        'src' => $out . '/405_05-c78b98dd942e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896146359561/05-c78b98dd942e.jpg',
        'expect_id' => '2f345e19-c058-4957-a009-48c6bea91279',
        'product_id' => '405',
        'before' => '570x734',
    ],
    [
        'src' => $out . '/362_05-832cdd3b160a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715078490005/05-832cdd3b160a.jpg',
        'expect_id' => '01e6ff38-e2d7-4ec5-9278-5e1f4a97190b',
        'product_id' => '362',
        'before' => '626x800',
    ],
    [
        'src' => $out . '/364_05-faed6d4b02f7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706301642313/05-faed6d4b02f7.jpg',
        'expect_id' => '57dead51-c685-4e9c-bfd1-f4ee894efe96',
        'product_id' => '364',
        'before' => '608x787',
    ],
    [
        'src' => $out . '/368_05-8aaf434014c8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/737369802795/05-8aaf434014c8.jpg',
        'expect_id' => '3b953086-99ba-4876-a28f-010570a10f85',
        'product_id' => '368',
        'before' => '608x789',
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
