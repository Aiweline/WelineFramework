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
$done = '/tmp/p-img-opt-wave8-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave8-20260918/out/195_05-010802d79f85.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832107288112/05-010802d79f85.jpg',
        'expect_id' => 'a5c3dbab-e020-4790-8261-fd2c80c70ad5',
        'product_id' => '195',
        'before' => '900x1200',
    ],
    [
        'src' => '/tmp/p-img-opt-wave8-20260918/out/194_02-02bfbc4eab4c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831939753369/02-02bfbc4eab4c.jpg',
        'expect_id' => 'b376b8e8-5b28-4a9d-877d-d6cde18fb682',
        'product_id' => '194',
        'before' => '900x1200',
    ],
    [
        'src' => '/tmp/p-img-opt-wave8-20260918/out/194_03-9319f0f12797.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831939753369/03-9319f0f12797.jpg',
        'expect_id' => '7c8f72b3-5bde-4a33-b4a1-1a4457a187c9',
        'product_id' => '194',
        'before' => '864x1152',
    ],
    [
        'src' => '/tmp/p-img-opt-wave8-20260918/out/194_06-2f4b7a66fd85.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831939753369/06-2f4b7a66fd85.jpg',
        'expect_id' => 'ebc37a73-d59c-4422-85fa-937763b74ef4',
        'product_id' => '194',
        'before' => '900x1200',
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
