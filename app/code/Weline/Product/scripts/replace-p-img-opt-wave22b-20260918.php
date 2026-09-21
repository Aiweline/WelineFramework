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
$done = '/tmp/p-img-opt-wave22b-20260918/done.tsv';
$fail = '/tmp/p-img-opt-wave22b-20260918/fail.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave22b-20260918/out/185_detail-04-bb94267fd1db.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829246319270/detail-04-bb94267fd1db.jpg',
        'expect_id' => 'd7d225d9-0ee1-48cd-9b62-3f697e649c9f',
        'product_id' => '185',
        'before' => '750x1000',
        'src_bpp' => 1.052,
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave22b-20260918/out/185_detail-01-75cc454f8e6e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829246319270/detail-01-75cc454f8e6e.jpg',
        'expect_id' => 'dfd37e87-4087-4203-be4c-f99f4677eba8',
        'product_id' => '185',
        'before' => '750x1000',
        'src_bpp' => 1.002,
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave22b-20260918/out/181_detail-01-e54712405c2d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826520269606/detail-01-e54712405c2d.jpg',
        'expect_id' => '6ee541c4-f73f-4f3e-9f7f-40692c13f7c1',
        'product_id' => '181',
        'before' => '790x1145',
        'src_bpp' => 1.0,
        'why' => 'soft',
    ],
    [
        'src' => '/tmp/p-img-opt-wave22b-20260918/out/170_detail-03-e891f42c9e1e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/823792675009/detail-03-e891f42c9e1e.jpg',
        'expect_id' => '7605d4a8-6fc3-4765-a851-a65a7888aa78',
        'product_id' => '170',
        'before' => '900x1200',
        'src_bpp' => 1.101,
        'why' => 'soft',
    ],
];

$newFile = !is_file($done);
$fh = fopen($done, 'a');
if ($newFile) {
    fwrite($fh, "product_id\tasset_id\tobject_key\twhy\tbefore_wxh\tafter_wxh\tbpp\tempty_upscale\n");
}
$ff = fopen($fail, 'a');
$failed = 0;

foreach ($jobs as $j) {
    $path = $j['src'];
    $basename = basename($j['object_key']);
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        fwrite($ff, "{$j['object_key']}\tmissing\n");
        $failed++;
        continue;
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad {$path}\n");
        fwrite($ff, "{$j['object_key']}\tbad\n");
        $failed++;
        continue;
    }
    $w = (int)$size[0];
    $h = (int)$size[1];
    $bpp = filesize($path) * 8 / ($w * $h);
    if ($bpp < 1.2) {
        $im = imagecreatefromjpeg($path);
        if ($im === false) {
            fwrite(STDERR, "jpeg decode fail {$basename}\n");
            fwrite($ff, "{$j['object_key']}\tjpeg_decode\n");
            $failed++;
            continue;
        }
        imagejpeg($im, $path, 98);
        imagedestroy($im);
        clearstatcache(true, $path);
        $size = getimagesize($path);
        $w = (int)$size[0];
        $h = (int)$size[1];
        $bpp = filesize($path) * 8 / ($w * $h);
    }
    if ($bpp < 1.2 || $bpp <= (float)$j['src_bpp']) {
        fwrite(STDERR, "bpp gate {$basename}: {$w}x{$h} bpp={$bpp} src={$j['src_bpp']}\n");
        fwrite($ff, "{$j['object_key']}\tbpp {$bpp}\n");
        $failed++;
        continue;
    }
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
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    $aid = (string)($desc['asset_id'] ?? '');
    if ($aid !== '' && $aid !== $j['expect_id']) {
        fwrite(STDERR, "id drift {$basename}: expect {$j['expect_id']} got {$aid}\n");
        fwrite($ff, "{$j['object_key']}\tid_drift {$aid}\n");
        $failed++;
        continue;
    }
    $bppStr = number_format($bpp, 3, '.', '');
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['why']}\t{$j['before']}\t{$w}x{$h}\t{$bppStr}\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$w}x{$h} bpp={$bppStr}\n";
}
fclose($fh);
fclose($ff);
if ($failed > 0) {
    fwrite(STDERR, "failed {$failed}\n");
    exit(1);
}
echo "done\n";
