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
$work = '/tmp/p-ar-wave24-20260918';
$out = $work . '/out';
$done = $work . '/done.tsv';
$jobsTsv = $work . '/jobs.tsv';
$mediaRoot = dirname(__DIR__, 5) . '/pub/media/';

// wave24: 39 job rows / 13 unique object_keys → 1:1 outpaint replace
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

if (count($allJobs) !== 39) {
    fwrite(STDERR, 'expected 39 jobs, got ' . count($allJobs) . "\n");
    exit(1);
}

// Unique replace by object_key (first row wins for expect_id / before)
$unique = [];
foreach ($allJobs as $j) {
    $ok = $j['object_key'];
    if (isset($unique[$ok])) {
        continue;
    }
    $base = basename($ok);
    $src = $out . '/' . $j['product_id'] . '_' . $base;
    if (!is_file($src)) {
        $alt = $out . '/' . $j['src_name'];
        if (is_file($alt)) {
            $src = $alt;
        }
    }
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
echo 'done unique=' . count($unique) . ' rows=' . count($allJobs) . "\n";
