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
$out = '/tmp/p-ar-wave11-20260917/out';
$done = '/tmp/p-ar-wave11-20260917/done.tsv';

$jobs = [
    [
        'src' => $out . '/402_03-ceff0f0353b1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857135725475/03-ceff0f0353b1.jpg',
        'expect_id' => '53869958-c1b2-429a-82ab-00ac56725bfd',
        'product_id' => '402',
        'before' => '963x1066',
    ],
    [
        'src' => $out . '/483_05-810b178a5b8d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/639003693261/05-810b178a5b8d.jpg',
        'expect_id' => '7230acc9-1eb6-4840-841e-9da4a747a1dd',
        'product_id' => '483',
        'before' => '608x796',
    ],
    [
        'src' => $out . '/204_08-c71d24c3b123.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/863290574599/08-c71d24c3b123.jpg',
        'expect_id' => '74e1da28-2611-4801-b13e-b47e40708337',
        'product_id' => '204',
        'before' => '1163x1200',
    ],
    [
        'src' => $out . '/389_05-13cbbdcae565.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770377252676/05-13cbbdcae565.jpg',
        'expect_id' => '822e2a4f-9832-4557-a2d3-fcd573513ca1',
        'product_id' => '389',
        'before' => '655x800',
    ],
    [
        'src' => $out . '/423_04-88a25f0ceac2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-jianzhou/621388692111/04-88a25f0ceac2.jpg',
        'expect_id' => '7f3b6c37-f1e3-4d8f-b050-19db020c0acc',
        'product_id' => '423',
        'before' => '608x772',
    ],
    [
        'src' => $out . '/438_03-a9e7440726a4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706603439030/03-a9e7440726a4.jpg',
        'expect_id' => 'cd49fa15-c765-49ec-9a0f-e362c568345f',
        'product_id' => '438',
        'before' => '1266x1500',
    ],
    [
        'src' => $out . '/358_05-c55de2d39603.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706523563773/05-c55de2d39603.jpg',
        'expect_id' => 'c86cdeed-8bf5-46a5-80d5-22dea881aea7',
        'product_id' => '358',
        'before' => '772x800',
    ],
    [
        'src' => $out . '/369_05-7126672ed99b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/737371662599/05-7126672ed99b.jpg',
        'expect_id' => 'b318e7bd-db39-40ba-8808-e1f7c8308007',
        'product_id' => '369',
        'before' => '608x776',
    ],
    [
        'src' => $out . '/370_08-a013e1b6d90c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715276723549/08-a013e1b6d90c.jpg',
        'expect_id' => 'e9bc7ed4-741f-4642-adc6-ce0cb9978e47',
        'product_id' => '370',
        'before' => '645x711',
    ],
    [
        'src' => $out . '/352_05-0bc9734db3fa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704247394800/05-0bc9734db3fa.jpg',
        'expect_id' => '2233cdbd-fac3-424d-afa7-555efdf4e3d8',
        'product_id' => '352',
        'before' => '608x784',
    ],
    [
        'src' => $out . '/395_05-944fbc92f451.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/846776779959/05-944fbc92f451.jpg',
        'expect_id' => '4a640afc-ad77-4b18-94dc-d8d8e19b5b43',
        'product_id' => '395',
        'before' => '811x1029',
    ],
    [
        'src' => $out . '/532_09-b3484028d409.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/871144491113/09-b3484028d409.jpg',
        'expect_id' => '4326fdc7-a69b-49ff-849f-e734d20a0728',
        'product_id' => '532',
        'before' => '750x790',
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
