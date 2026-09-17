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
$out = '/tmp/p-ar-wave8-20260917/out';
$done = '/tmp/p-ar-wave8-20260917/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/440_05-4c7b50b51191.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708035977500/05-4c7b50b51191.jpg',
        'expect_id' => '757a1cb5-ccca-4d66-a710-a2f3825d0c4d',
        'product_id' => '440',
        'before' => '757x670',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/450_05-87b7d60a45a0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706651907975/05-87b7d60a45a0.jpg',
        'expect_id' => '6b4133c4-9713-4ef8-8acc-7b4ab7e13176',
        'product_id' => '450',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/478_08-f3fa35f9fa45.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1063052834788/08-f3fa35f9fa45.jpg',
        'expect_id' => '39b05c6d-568d-4524-90b1-5eada367ea4c',
        'product_id' => '478',
        'before' => '763x895',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/506_08-17511f4c1b5a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740828206849/08-17511f4c1b5a.jpg',
        'expect_id' => 'db42c9c8-dc3b-49bc-889b-399e27daae82',
        'product_id' => '506',
        'before' => '1200x800',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/510_05-e1442ce801ae.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/754086834164/05-e1442ce801ae.jpg',
        'expect_id' => '4632c1db-81c0-41dc-a301-5d7c02d06132',
        'product_id' => '510',
        'before' => '912x1180',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/365_05-7b7b7252939a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706523563772/05-7b7b7252939a.jpg',
        'expect_id' => 'e00b1e17-2c75-4ffa-8faf-2e898f5dda82',
        'product_id' => '365',
        'before' => '608x800',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/391_05-edfed01566d0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/790194450246/05-edfed01566d0.jpg',
        'expect_id' => 'd4161d18-a0cf-4a21-85e3-fd89f8ab029b',
        'product_id' => '391',
        'before' => '810x1018',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/491_05-0995f943c80e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/706372115146/05-0995f943c80e.jpg',
        'expect_id' => '3dbab209-5080-46a6-837e-88f4dd14efd0',
        'product_id' => '491',
        'before' => '608x780',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/493_06-3b108cfcf2cf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/714967577719/06-3b108cfcf2cf.jpg',
        'expect_id' => '191752bf-05a6-41ee-9bac-063989624cda',
        'product_id' => '493',
        'before' => '750x718',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/521_05-12dc44e2d2a2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/818786567633/05-12dc44e2d2a2.jpg',
        'expect_id' => '57dc2d13-d8cc-4f59-b343-7b7cb1b12d57',
        'product_id' => '521',
        'before' => '608x790',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/344_05-64eb7ff1b166.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703612667993/05-64eb7ff1b166.jpg',
        'expect_id' => '9f5b5475-d6bf-475d-b9c9-8a62114fbd0b',
        'product_id' => '344',
        'before' => '704x793',
    ],
    [
        'src' => '/tmp/p-ar-wave8-20260917/out/377_05-3e42ae4a597f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743497349861/05-3e42ae4a597f.jpg',
        'expect_id' => 'db82d44a-5c51-434c-9975-20e35c9ee02b',
        'product_id' => '377',
        'before' => '912x1160',
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
