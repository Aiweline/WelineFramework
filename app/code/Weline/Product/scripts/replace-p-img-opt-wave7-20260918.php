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
$done = '/tmp/p-img-opt-wave7-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/378_16-06709a40e32e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/16-06709a40e32e.jpg',
        'expect_id' => '4dcf2387-bcbf-4704-af82-10dea719a399',
        'product_id' => '378',
        'before' => '634x736',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/378_14-350449b9a154.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/14-350449b9a154.jpg',
        'expect_id' => '5996f86b-8e5a-40e6-87cf-95ddedd297f7',
        'product_id' => '378',
        'before' => '650x740',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/378_01-b6ed5bb46579.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/01-b6ed5bb46579.jpg',
        'expect_id' => 'f1696100-6e4f-4636-8f4a-b8a41cff2d8b',
        'product_id' => '378',
        'before' => '660x750',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/378_13-b9655e6a9237.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/13-b9655e6a9237.jpg',
        'expect_id' => 'e03aeb84-839f-49e6-b3e8-e170534aadcb',
        'product_id' => '378',
        'before' => '660x748',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/302_02-08dac72e3d19.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/02-08dac72e3d19.jpg',
        'expect_id' => '914fef0b-6763-4459-b12d-8c17f89559d1',
        'product_id' => '302',
        'before' => '1024x1536',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/302_09-d0c60e5fe952.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1037477114572/09-d0c60e5fe952.jpg',
        'expect_id' => '51190457-fcaf-433e-a86a-8c8ddc1041ab',
        'product_id' => '302',
        'before' => '704x800',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/200_03-c444c6fce848.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/03-c444c6fce848.jpg',
        'expect_id' => '9ebbd243-6dc2-41aa-acc5-30516679afda',
        'product_id' => '200',
        'before' => '864x1152',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/200_08-d0809b245068.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/08-d0809b245068.jpg',
        'expect_id' => '0cdfcfaf-838c-4afa-9298-58861311e4e0',
        'product_id' => '200',
        'before' => '864x1152',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/200_09-29febeb2413a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/09-29febeb2413a.jpg',
        'expect_id' => 'cfdb6a0c-77d8-4e1c-aba7-21131cdc0f64',
        'product_id' => '200',
        'before' => '864x1152',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/196_03-d7f7d65fbf87.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832571599410/03-d7f7d65fbf87.jpg',
        'expect_id' => 'e187c1cf-5393-484d-abfb-1ecf151cdcaf',
        'product_id' => '196',
        'before' => '1200x1200',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/196_04-4cb741c20efe.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832571599410/04-4cb741c20efe.jpg',
        'expect_id' => '0d430c6b-6c9a-42ef-8a0f-b2d8ebd04f92',
        'product_id' => '196',
        'before' => '1200x1200',
    ],
    [
        'src' => '/tmp/p-img-opt-wave7-20260918/out/196_05-ab5c9b143cbd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832571599410/05-ab5c9b143cbd.jpg',
        'expect_id' => 'bd847af5-079b-4c97-b865-6ab0980addcc',
        'product_id' => '196',
        'before' => '1200x1200',
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
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['before']}\t{$w}x{$h}\t是\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$w}x{$h}\n";
}
fclose($fh);
echo "done\n";
