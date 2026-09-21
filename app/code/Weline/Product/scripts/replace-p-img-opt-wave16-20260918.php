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
$done = '/tmp/p-img-opt-wave16-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/542_06-80e08323b9ca.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824496857539/06-80e08323b9ca.jpg',
        'expect_id' => '6d69effa-3bfd-4e86-bbb2-e0d134c3f54f',
        'product_id' => '542',
        'before' => '900x900',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/542_02-c3eca29c5395.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824496857539/02-c3eca29c5395.jpg',
        'expect_id' => 'd444afe7-76ff-4507-acf0-e31698d21a98',
        'product_id' => '542',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/542_03-7e3db4ebde02.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824496857539/03-7e3db4ebde02.jpg',
        'expect_id' => 'ebb98f43-b5ca-4b5d-91c5-72535424fd5b',
        'product_id' => '542',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/540_04-edf33d414b1f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1069729626349/04-edf33d414b1f.jpg',
        'expect_id' => '87a972d3-2e3a-4d68-847a-f37d86b10a51',
        'product_id' => '540',
        'before' => '1600x1600',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/540_03-c601149a59a5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1069729626349/03-c601149a59a5.jpg',
        'expect_id' => '0b1888fc-83a5-4558-9369-166267763b11',
        'product_id' => '540',
        'before' => '1600x1600',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/538_05-5b05751d2380.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/982212210856/05-5b05751d2380.jpg',
        'expect_id' => '063856c0-f7db-473b-9382-91ee95368fd2',
        'product_id' => '538',
        'before' => '1024x1024',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/536_03-9988624750ac.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/904766368815/03-9988624750ac.jpg',
        'expect_id' => '8ca29764-780d-46ea-8ece-2eb88008729e',
        'product_id' => '536',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave16-20260918/out/536_06-cef24f83795e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/904766368815/06-cef24f83795e.jpg',
        'expect_id' => '1cd22143-6f15-439d-9682-4c50c4dc6788',
        'product_id' => '536',
        'before' => '900x900',
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
