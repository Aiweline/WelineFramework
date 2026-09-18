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
$done = '/tmp/p-img-opt-wave4-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave4-20260918/out/237_06-08d415d93998.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/981174366560/06-08d415d93998.jpg',
        'expect_id' => '7e6bcca0-4766-4629-a652-542ece375896',
        'product_id' => '237',
        'before' => '1368x1800',
    ],
    [
        'src' => '/tmp/p-img-opt-wave4-20260918/out/197_02-3988e7a51da5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/833553496409/02-3988e7a51da5.jpg',
        'expect_id' => 'a8d280fc-4110-4762-80d1-a83f1968a731',
        'product_id' => '197',
        'before' => '800x1067',
    ],
    [
        'src' => '/tmp/p-img-opt-wave4-20260918/out/196_06-ae377821a183.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832571599410/06-ae377821a183.jpg',
        'expect_id' => 'da1323ff-4c1c-45a2-92d2-1a3337ddade5',
        'product_id' => '196',
        'before' => '790x1141',
    ],
    [
        'src' => '/tmp/p-img-opt-wave4-20260918/out/195_03-8be7eee0c08b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832107288112/03-8be7eee0c08b.jpg',
        'expect_id' => 'fe7af14a-b95a-4111-9344-b4ad9f56d692',
        'product_id' => '195',
        'before' => '799x1066',
    ],
    [
        'src' => '/tmp/p-img-opt-wave4-20260918/out/193_02-8b9135cc566f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831937917272/02-8b9135cc566f.jpg',
        'expect_id' => 'e18573cf-4ee3-41eb-8d2d-92e7a94702c3',
        'product_id' => '193',
        'before' => '750x1000',
    ],
    [
        'src' => '/tmp/p-img-opt-wave4-20260918/out/189_03-hd-89b1a03b9894.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/03-hd-89b1a03b9894.jpg',
        'expect_id' => 'cf50630e-8116-4805-986f-6ad61f52b4b0',
        'product_id' => '189',
        'before' => '1500x1953',
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
