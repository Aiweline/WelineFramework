<?php
declare(strict_types=1);

/**
 * wave25 非方图：剥框后真 outpaint → 1024² replaceContent
 * 禁 cover / 色垫 / rembg / 空放大
 *
 * php app/code/Weline/Product/scripts/replace-p-ar-wave25-20260918.php
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
$work = '/tmp/p-ar-wave25-20260918';
$out = $work . '/out';
$done = $work . '/done.tsv';
$jobsTsv = $work . '/jobs.tsv';
$mediaRoot = dirname(__DIR__, 5) . '/pub/media/';
$bakDir = $work . '/bak/' . date('Ymd-His');

if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true) && !is_dir($bakDir)) {
    fwrite(STDERR, "bak mkdir fail {$bakDir}\n");
    exit(1);
}

$fhJobs = fopen($jobsTsv, 'r');
$header = fgetcsv($fhJobs, 0, "\t");
if ($header === false) {
    fwrite(STDERR, "empty jobs\n");
    exit(1);
}
$col = array_flip($header);
$allJobs = [];
while (($row = fgetcsv($fhJobs, 0, "\t")) !== false) {
    if (count($row) < 3) {
        continue;
    }
    $allJobs[] = [
        'product_id' => $row[$col['product_id']],
        'asset_id' => $row[$col['asset_id']],
        'object_key' => $row[$col['object_key']],
        'w' => $row[$col['w']],
        'h' => $row[$col['h']],
        'src_name' => $row[$col['src_name']],
    ];
}
fclose($fhJobs);

if (count($allJobs) !== 13) {
    fwrite(STDERR, 'expected 13 jobs, got ' . count($allJobs) . "\n");
    exit(1);
}

$unique = [];
foreach ($allJobs as $j) {
    $ok = $j['object_key'];
    if (isset($unique[$ok])) {
        continue;
    }
    $src = $out . '/' . $j['src_name'];
    $unique[$ok] = [
        'src' => $src,
        'object_key' => $ok,
        'expect_id' => $j['asset_id'],
        'product_id' => $j['product_id'],
        'before' => $j['w'] . 'x' . $j['h'],
    ];
}

$replaced = [];
foreach ($unique as $ok => $j) {
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
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.02) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h}\n");
        exit(1);
    }
    $orig = $mediaRoot . $j['object_key'];
    $bakPath = $bakDir . '/' . str_replace('/', '__', $j['object_key']);
    if (is_file($orig) && !is_file($bakPath)) {
        if (!copy($orig, $bakPath)) {
            fwrite(STDERR, "bak fail {$orig}\n");
            exit(1);
        }
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
    $pub = $mediaRoot . $j['object_key'];
    $pubSize = is_file($pub) ? getimagesize($pub) : false;
    if (!is_array($pubSize) || (int)$pubSize[0] !== 1024 || (int)$pubSize[1] !== 1024) {
        $got = is_array($pubSize) ? ($pubSize[0] . 'x' . $pubSize[1]) : 'missing';
        fwrite(STDERR, "pub/media gate fail {$pub}: {$got}\n");
        exit(1);
    }
    $after = "{$w}x{$h}";
    $replaced[$ok] = [
        'after' => $after,
        'asset_id' => $j['expect_id'],
        'before' => $j['before'],
        'product_id' => $j['product_id'],
    ];
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$after} pub=1024x1024 asset={$aid}\n";
}

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\tpub_media_wxh\tsrc_name\n");
foreach ($allJobs as $j) {
    $ok = $j['object_key'];
    $r = $replaced[$ok];
    fwrite(
        $fh,
        "{$j['product_id']}\t{$j['asset_id']}\t{$ok}\t{$j['w']}x{$j['h']}\t{$r['after']}\t是\t否\t1024x1024\t{$j['src_name']}\n"
    );
}
fclose($fh);
echo 'done unique=' . count($unique) . ' rows=' . count($allJobs) . " bak={$bakDir}\n";
