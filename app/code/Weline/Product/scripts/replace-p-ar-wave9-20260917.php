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
$out = '/tmp/p-ar-wave9-20260917/out';
$done = '/tmp/p-ar-wave9-20260917/done.tsv';

$jobs = [
    [
        'src' => $out . '/399_05-390cac6a52fa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856673111455/05-390cac6a52fa.jpg',
        'expect_id' => 'db38c7cb-bacd-499a-9769-c7195f761057',
        'product_id' => '399',
        'before' => '812x1056',
    ],
    [
        'src' => $out . '/401_05-e399f8186290.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857132385621/05-e399f8186290.jpg',
        'expect_id' => 'c484f956-46de-4556-8322-54f16b757d87',
        'product_id' => '401',
        'before' => '812x1061',
    ],
    [
        'src' => $out . '/410_05-a533af14e59d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/737496623609/05-a533af14e59d.jpg',
        'expect_id' => '8babe214-07ea-41b8-874f-f37d2358e6c8',
        'product_id' => '410',
        'before' => '608x794',
    ],
    [
        'src' => $out . '/446_05-6364b27b422d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705654067146/05-6364b27b422d.jpg',
        'expect_id' => 'e6219255-ebd0-44ce-8530-06c8b297bdcd',
        'product_id' => '446',
        'before' => '1056x1200',
    ],
    [
        'src' => $out . '/507_05-d08432d58117.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/746099485547/05-d08432d58117.jpg',
        'expect_id' => '34d7e962-f363-4ef4-b920-fecdcc275555',
        'product_id' => '507',
        'before' => '912x1182',
    ],
    [
        'src' => $out . '/514_05-95b74ba6d100.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/794175727367/05-95b74ba6d100.jpg',
        'expect_id' => 'd2d2662a-ae92-4679-84f8-a972d4034356',
        'product_id' => '514',
        'before' => '912x1181',
    ],
    [
        'src' => $out . '/538_05-5b05751d2380.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/982212210856/05-5b05751d2380.jpg',
        'expect_id' => '063856c0-f7db-473b-9382-91ee95368fd2',
        'product_id' => '538',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/351_05-34245dcd412b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704252254823/05-34245dcd412b.jpg',
        'expect_id' => 'b4872352-06eb-4c90-bdfb-c127969a887d',
        'product_id' => '351',
        'before' => '640x800',
    ],
    [
        'src' => $out . '/349_05-c6375cac0745.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704017453098/05-c6375cac0745.jpg',
        'expect_id' => 'b3d6ca2e-f702-430e-9790-f99ac690160c',
        'product_id' => '349',
        'before' => '695x800',
    ],
    [
        'src' => $out . '/387_05-7f98ce96d948.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/756334865646/05-7f98ce96d948.jpg',
        'expect_id' => 'a19a241f-e39c-4b85-bc27-0d7b8cdef277',
        'product_id' => '387',
        'before' => '608x776',
    ],
    [
        'src' => $out . '/385_05-bb654cf34fe1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752565253405/05-bb654cf34fe1.jpg',
        'expect_id' => 'e7e4345d-6573-4cbc-a19c-8e39eb682ef1',
        'product_id' => '385',
        'before' => '922x1123',
    ],
    [
        'src' => $out . '/381_05-72b962db523c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746554787038/05-72b962db523c.jpg',
        'expect_id' => '057150a1-4771-4b78-99fa-0fc12ec8c9a5',
        'product_id' => '381',
        'before' => '608x787',
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
