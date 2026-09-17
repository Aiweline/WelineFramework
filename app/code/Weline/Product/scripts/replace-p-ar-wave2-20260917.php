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
$out = '/tmp/p-ar-wave2-20260917/out';
$done = '/tmp/p-ar-wave2-20260917/done.tsv';

$jobs = [
    [
        'src' => $out . '/408_05-492732345a2e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703151897208/05-492732345a2e.jpg',
        'expect_id' => 'f3eda030-9286-459d-8adb-5bf5f4a95b10',
        'product_id' => '408',
        'before' => '631x800',
    ],
    [
        'src' => $out . '/409_05-227462b29b56.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703614051465/05-227462b29b56.jpg',
        'expect_id' => '3122fc71-f667-423a-936e-eed426552c7a',
        'product_id' => '409',
        'before' => '631x800',
    ],
    [
        'src' => $out . '/429_05-75791b4a6df0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705201701247/05-75791b4a6df0.jpg',
        'expect_id' => 'a55033d1-15e6-443c-8acd-581bb8ca4799',
        'product_id' => '429',
        'before' => '689x783',
    ],
    [
        'src' => $out . '/430_05-a590c47f5f9e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705532380012/05-a590c47f5f9e.jpg',
        'expect_id' => '7d8bcda9-e277-4c0d-89ae-99c3e80549b3',
        'product_id' => '430',
        'before' => '690x800',
    ],
    [
        'src' => $out . '/433_05-c828533ffd24.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705193029653/05-c828533ffd24.jpg',
        'expect_id' => '2bea250e-6a74-4888-9ef4-d8fd3d3e6276',
        'product_id' => '433',
        'before' => '608x780',
    ],
    [
        'src' => $out . '/452_01-10e6fae00cad.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/729216851376/01-10e6fae00cad.jpg',
        'expect_id' => 'a56e2c33-74f9-410b-97a6-ef6ddda47bd0',
        'product_id' => '452',
        'before' => '1046x1200',
    ],
    [
        'src' => $out . '/474_05-6e99f4fc7c8f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062240351232/05-6e99f4fc7c8f.jpg',
        'expect_id' => 'b5a5a3aa-e491-461b-9127-ba99ea2cbd44',
        'product_id' => '474',
        'before' => '525x800',
    ],
    [
        'src' => $out . '/475_05-082e11f3e1cd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062241323317/05-082e11f3e1cd.jpg',
        'expect_id' => 'fbba8956-d7ae-4c07-a994-9123c7b2b845',
        'product_id' => '475',
        'before' => '464x800',
    ],
    [
        'src' => $out . '/485_05-f52eb0a41689.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/699271252794/05-f52eb0a41689.jpg',
        'expect_id' => '004b69cd-c91d-4bc4-88eb-431e05fae3cc',
        'product_id' => '485',
        'before' => '608x779',
    ],
    [
        'src' => $out . '/490_05-f88a164b9a88.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705647832283/05-f88a164b9a88.jpg',
        'expect_id' => 'de31fd74-2cf7-4cf4-bc73-76904557df80',
        'product_id' => '490',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/529_04-976dcdd3fcd8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/951308519684/04-976dcdd3fcd8.jpg',
        'expect_id' => '8cb81572-d6d5-4188-93dd-b3143e398eb0',
        'product_id' => '529',
        'before' => '608x784',
    ],
    [
        'src' => $out . '/170_05-e54904652b71.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/823792675009/05-e54904652b71.jpg',
        'expect_id' => '97454e85-01c9-488c-8613-1e5bca8ec6ac',
        'product_id' => '170',
        'before' => '1200x1113',
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
