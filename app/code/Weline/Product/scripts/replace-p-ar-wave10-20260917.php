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
$out = '/tmp/p-ar-wave10-20260917/out';
$done = '/tmp/p-ar-wave10-20260917/done.tsv';

$jobs = [
    [
        'src' => $out . '/412_05-2030cc91aa34.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896856645396/05-2030cc91aa34.jpg',
        'expect_id' => '294c6e48-2157-42ed-a751-95eca8cd0c75',
        'product_id' => '412',
        'before' => '608x794',
    ],
    [
        'src' => $out . '/181_04-ab3c8c537466.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826520269606/04-ab3c8c537466.jpg',
        'expect_id' => 'b7c550d6-3efb-4d67-9bda-8757ea606512',
        'product_id' => '181',
        'before' => '1056x1200',
    ],
    [
        'src' => $out . '/202_02-a6e06a6f3d66.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/846809244944/02-a6e06a6f3d66.jpg',
        'expect_id' => 'bc1f85cb-b0f9-4ce8-8d04-4f533d2f35c3',
        'product_id' => '202',
        'before' => '1200x1090',
    ],
    [
        'src' => $out . '/263_02-f7263169eb49.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1044138917001/02-f7263169eb49.jpg',
        'expect_id' => 'c992bf07-9915-46f5-ab52-cf1e1fe67213',
        'product_id' => '263',
        'before' => '1056x1200',
    ],
    [
        'src' => $out . '/346_05-8754c1001731.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703614631653/05-8754c1001731.jpg',
        'expect_id' => '70b65186-c1bb-42fd-91f3-d4223fd5e965',
        'product_id' => '346',
        'before' => '609x800',
    ],
    [
        'src' => $out . '/376_02-102b627794dd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/739090011198/02-102b627794dd.jpg',
        'expect_id' => '399c7caf-69d9-488c-b747-a3f8af3f3b3e',
        'product_id' => '376',
        'before' => '800x755',
    ],
    [
        'src' => $out . '/431_05-6f6e7eb880a3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705533300032/05-6f6e7eb880a3.jpg',
        'expect_id' => 'ba1fc7c4-9763-42aa-b4d5-6d7594f16a2b',
        'product_id' => '431',
        'before' => '624x768',
    ],
    [
        'src' => $out . '/467_03-0f988ced1e3d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1028460404734/03-0f988ced1e3d.jpg',
        'expect_id' => 'ce8f92ec-cf09-438a-b68d-93e3f53fcee6',
        'product_id' => '467',
        'before' => '1095x1439',
    ],
    [
        'src' => $out . '/540_05-8c6044d799c9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1069729626349/05-8c6044d799c9.jpg',
        'expect_id' => '9e9ca8e1-e92a-4305-8d4a-8458e69df793',
        'product_id' => '540',
        'before' => '1600x1408',
    ],
    [
        'src' => $out . '/201_06-e209fdc215a8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/846337210308/06-e209fdc215a8.jpg',
        'expect_id' => '69ea3d8d-4560-4338-8163-95433a616928',
        'product_id' => '201',
        'before' => '750x847',
    ],
    [
        'src' => $out . '/244_02-b861d4b286b0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/997213594839/02-b861d4b286b0.jpg',
        'expect_id' => '045745bf-a536-4f37-802d-9af11fb5a2fb',
        'product_id' => '244',
        'before' => '1200x1056',
    ],
    [
        'src' => $out . '/379_03-5220ae15d835.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746304492874/03-5220ae15d835.jpg',
        'expect_id' => '4d974fd3-7d0b-4719-b722-fccb1c368599',
        'product_id' => '379',
        'before' => '800x608',
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
