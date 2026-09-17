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
$out = '/tmp/p-ar-wave-20260917/out';
$done = '/tmp/p-ar-wave-20260917/done.tsv';

$jobs = [
    [
        'src' => $out . '/113_04-95511b7d8218.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-yueya/731150010223/04-95511b7d8218.jpg',
        'expect_id' => '656baad1-33ed-4872-a63f-346892624cdf',
        'product_id' => '113',
        'before' => '726x793',
    ],
    [
        'src' => $out . '/117_05-hd-cbbec04d9de2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-yueya/730813804749/05-hd-cbbec04d9de2.jpg',
        'expect_id' => 'e12e3060-2bb3-4521-a863-3ed6025dba71',
        'product_id' => '117',
        'before' => '1344x1578',
    ],
    [
        'src' => $out . '/240_05-7ad40bd86eed.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/994121037450/05-7ad40bd86eed.jpg',
        'expect_id' => '4381176e-3117-46d9-8d59-3729d9e48863',
        'product_id' => '240',
        'before' => '1500x1391',
    ],
    [
        'src' => $out . '/276_04-79a8a49902cb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1054033364080/04-79a8a49902cb.jpg',
        'expect_id' => 'cbbd2b49-fd70-4b64-8527-2d8d32e8a6b2',
        'product_id' => '276',
        'before' => '1920x1684',
    ],
    [
        'src' => $out . '/301_01-d021ecc0172b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1077963371242/01-d021ecc0172b.jpg',
        'expect_id' => 'cad7205c-c3c9-4323-bee0-5e29f7e419e5',
        'product_id' => '301',
        'before' => '1418x1349',
    ],
    [
        'src' => $out . '/319_05-ba1fff19134d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/693483394109/05-ba1fff19134d.jpg',
        'expect_id' => 'e03b4086-c268-42fb-a589-750ef3b7844c',
        'product_id' => '319',
        'before' => '608x785',
    ],
    [
        'src' => $out . '/325_05-00f2cc842ebf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702860344666/05-00f2cc842ebf.jpg',
        'expect_id' => 'dffa6f17-4854-44ee-9049-b6f8f381b26c',
        'product_id' => '325',
        'before' => '608x770',
    ],
    [
        'src' => $out . '/326_05-7ad7a0c0932e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702860848667/05-7ad7a0c0932e.jpg',
        'expect_id' => '9a69cb5e-91a1-49bb-8317-7793eba6a633',
        'product_id' => '326',
        'before' => '608x772',
    ],
    [
        'src' => $out . '/330_05-4884209cc25e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702862148463/05-4884209cc25e.jpg',
        'expect_id' => '8debde86-e0a6-4166-8ccc-bd2bed5f33e0',
        'product_id' => '330',
        'before' => '608x786',
    ],
    [
        'src' => $out . '/334_05-3390f36eb1b8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702860944679/05-3390f36eb1b8.jpg',
        'expect_id' => '2097820f-08dc-4c21-90d8-018c6c5f729a',
        'product_id' => '334',
        'before' => '710x761',
    ],
    [
        'src' => $out . '/353_05-e41813212665.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704910414749/05-e41813212665.jpg',
        'expect_id' => 'f827498e-f0ba-43d8-9170-968f36f112d9',
        'product_id' => '353',
        'before' => '755x800',
    ],
    [
        'src' => $out . '/367_05-80e3d98c7387.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703617507833/05-80e3d98c7387.jpg',
        'expect_id' => 'e48accab-08a9-47cb-919b-cdd3090fbf2f',
        'product_id' => '367',
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
