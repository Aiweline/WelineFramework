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
$done = '/tmp/p-img-opt-wave13-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/239_01-eaeb6d66e1fc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/993891093247/01-eaeb6d66e1fc.jpg',
        'expect_id' => '25cc0e8e-52b1-40f7-a4e8-92a3d52b0906',
        'product_id' => '239',
        'before' => '1600x1600',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/238_01-2a25c079e210.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/982905812562/01-2a25c079e210.jpg',
        'expect_id' => 'fb3fbd6c-c7c9-4b08-9ca6-530c7a42fb4e',
        'product_id' => '238',
        'before' => '1706x1706',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/237_01-772d154db9af.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/981174366560/01-772d154db9af.jpg',
        'expect_id' => '3c13aaa8-8cfd-470a-bb77-d79ae379dd99',
        'product_id' => '237',
        'before' => '1920x1920',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/234_01-6f3f4a3ff35d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/974096088277/01-6f3f4a3ff35d.jpg',
        'expect_id' => 'a62ae955-75fb-45b5-afac-caba165f808f',
        'product_id' => '234',
        'before' => '1277x1277',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/232_01-11fa82718c3d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/971379629225/01-11fa82718c3d.jpg',
        'expect_id' => 'e5d6cc05-8f7a-4889-9b4b-fc85641b8ff0',
        'product_id' => '232',
        'before' => '1600x1600',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/228_01-a1bef65b58ee.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/928182857946/01-a1bef65b58ee.jpg',
        'expect_id' => '65522306-4108-46c5-843c-a0b29b878688',
        'product_id' => '228',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/226_01-b1c77e3ca4c8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/923825717296/01-b1c77e3ca4c8.jpg',
        'expect_id' => 'ec14e2d3-43c7-4fd1-bf68-23c87292d2c9',
        'product_id' => '226',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/221_01-4cf48a894481.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/915769494776/01-4cf48a894481.jpg',
        'expect_id' => '9f8be992-33fa-4bbe-9312-5b5515d81754',
        'product_id' => '221',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/218_01-b77cda7c9d46.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/912814485918/01-b77cda7c9d46.jpg',
        'expect_id' => '76e001f4-d288-42f1-92d0-38b015485378',
        'product_id' => '218',
        'before' => '1024x1024',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/217_01-7fdf8ae4a870.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/908548254710/01-7fdf8ae4a870.jpg',
        'expect_id' => '4ede6c59-22a1-40c2-a025-98c0889346ed',
        'product_id' => '217',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/216_01-727f9d911c0c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/902222090528/01-727f9d911c0c.jpg',
        'expect_id' => 'dbc42d80-d290-44c0-8cf2-8770580acf5a',
        'product_id' => '216',
        'before' => '1200x1200',
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave13-20260918/out/210_01-87cab5142eb4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/897341085402/01-87cab5142eb4.jpg',
        'expect_id' => '37df786a-5e77-475e-826b-97b2fbcba8fe',
        'product_id' => '210',
        'before' => '1200x1200',
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
