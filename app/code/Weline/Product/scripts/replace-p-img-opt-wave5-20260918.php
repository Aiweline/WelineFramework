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
$done = '/tmp/p-img-opt-wave5-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/500_01-197459b8f52b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/01-197459b8f52b.jpg',
        'expect_id' => '40132905-f71f-42b5-a82b-16b0e1d8ccbe',
        'product_id' => '500',
        'before' => '642x1036',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/477_20-9169529988a4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/20-9169529988a4.jpg',
        'expect_id' => 'ad5dcf91-df2d-472a-becd-de33282186ee',
        'product_id' => '477',
        'before' => '764x608',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/411_14-350449b9a154.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/14-350449b9a154.jpg',
        'expect_id' => 'afd7a221-a8a4-437b-a41a-25ee8dedfd93',
        'product_id' => '411',
        'before' => '650x740',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/378_15-5c04640397ba.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/15-5c04640397ba.jpg',
        'expect_id' => '80282e85-cf10-4ab3-9dd3-3d9b2ad89edd',
        'product_id' => '378',
        'before' => '608x722',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/321_05-3654193d0d1b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819215460099/05-3654193d0d1b.jpg',
        'expect_id' => '63538aed-d378-456d-8584-eae1fc8bdfea',
        'product_id' => '321',
        'before' => '897x1040',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/302_01-70ab52b4f4dc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/01-70ab52b4f4dc.jpg',
        'expect_id' => '4feabb91-dfb3-4dfb-895f-3b0c6940a6e7',
        'product_id' => '302',
        'before' => '1200x1056',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/200_01-4c23ce0589ff.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/01-4c23ce0589ff.jpg',
        'expect_id' => '8740b5b9-c6c6-4b52-8e95-dbf0491c71bb',
        'product_id' => '200',
        'before' => '864x1152',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/196_01-bbf708639abf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832571599410/01-bbf708639abf.jpg',
        'expect_id' => 'cadfed63-acba-48b7-8f2b-27ce240b674a',
        'product_id' => '196',
        'before' => '799x1066',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/195_01-047583249673.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832107288112/01-047583249673.jpg',
        'expect_id' => '36e463b4-42e9-48c7-9b70-19667cc98792',
        'product_id' => '195',
        'before' => '799x1066',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/194_01-ac95c0f1186f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831939753369/01-ac95c0f1186f.jpg',
        'expect_id' => '393193c8-e4f1-4524-a5cd-898e59176410',
        'product_id' => '194',
        'before' => '799x1066',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/193_01-45b84d5ce6dc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831937917272/01-45b84d5ce6dc.jpg',
        'expect_id' => '3dc9d4d4-33eb-4830-b43d-a110d6db56cf',
        'product_id' => '193',
        'before' => '1000x1333',
    ],
    [
        'src' => '/tmp/p-img-opt-wave5-20260918/out/477_12-a3799828be04.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/12-a3799828be04.jpg',
        'expect_id' => '1b0f2b0b-363b-49d3-8995-2181c3c6552b',
        'product_id' => '477',
        'before' => '759x608',
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
    $outpaint = (str_contains($path, '195_01') || str_contains($path, '196_01')) ? '否(已1:1同步)' : '是';
    $after = "{$w}x{$h}";
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['before']}\t{$after}\t{$outpaint}\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$after} asset={$aid}\n";
}
fclose($fh);
echo "done\n";
