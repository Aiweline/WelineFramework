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
$done = '/tmp/p-img-opt-wave14-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/209_01-da99039c5cac.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/895677880318/01-da99039c5cac.jpg',
        'expect_id' => '9af780a2-a7ab-4e99-b2a5-69af9d1bb2ef',
        'product_id' => '209',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/208_01-c0d64c5ca1eb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/895489782952/01-c0d64c5ca1eb.jpg',
        'expect_id' => '8b9cd764-4ece-4cc2-a1aa-0b0d55a1fa2e',
        'product_id' => '208',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/207_01-67219dfa2598.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/892551858483/01-67219dfa2598.jpg',
        'expect_id' => 'a51c7c08-3a04-44e4-b7ff-2d668f29af51',
        'product_id' => '207',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/205_01-b545c3d0a085.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/889762716786/01-b545c3d0a085.jpg',
        'expect_id' => 'a88a5cc2-810d-4e87-88fe-b1fa95861726',
        'product_id' => '205',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/202_01-43ab46c75ab8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/846809244944/01-43ab46c75ab8.jpg',
        'expect_id' => 'c82d2c31-bc6b-4a93-8ca2-d4c892cec7a4',
        'product_id' => '202',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/192_01-bb580a4267bc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831923117968/01-bb580a4267bc.jpg',
        'expect_id' => 'c2a8ecd4-ae47-40d3-97ad-804169d6b361',
        'product_id' => '192',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/190_01-hd-6cadd5714042.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/01-hd-6cadd5714042.jpg',
        'expect_id' => 'c3817a73-0858-4270-8601-032471022ca7',
        'product_id' => '190',
        'before' => '2400x2400',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/186_01-46f797d43601.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829407318846/01-46f797d43601.jpg',
        'expect_id' => '6a5b14e8-8f36-42a5-9f2c-a74e2837ce5c',
        'product_id' => '186',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/185_01-c5cab5d97f3c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829246319270/01-c5cab5d97f3c.jpg',
        'expect_id' => '098a2018-f868-4f40-8543-0cbb7c1db0a1',
        'product_id' => '185',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/181_01-7ec7e579ad9b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826520269606/01-7ec7e579ad9b.jpg',
        'expect_id' => '3d0d4db6-b3a8-466d-bba9-7816779bd6b3',
        'product_id' => '181',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/180_01-79150e217663.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826516641778/01-79150e217663.jpg',
        'expect_id' => '092721d1-4e35-49db-aa04-a16f749cf682',
        'product_id' => '180',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave14-20260918/out/179_01-7a830bac9b80.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826289398238/01-7a830bac9b80.jpg',
        'expect_id' => '674acbea-e55e-429a-bc09-ea23154326ab',
        'product_id' => '179',
        'before' => '1500x1500',
        'why' => 'soft',
    ],
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\twhy\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");

foreach ($jobs as $j) {
    $path = $j['src'];
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        exit(1);
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad {$path}\n");
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
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['why']}\t{$j['before']}\t{$w}x{$h}\t是\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['why']} {$j['before']} -> {$w}x{$h}\n";
}
fclose($fh);
echo "done\n";
