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
$done = '/tmp/p-img-opt-wave18-20260918/done.tsv';
$batchPath = $argv[1] ?? '';
if ($batchPath === '' || !is_file($batchPath)) {
    fwrite(STDERR, "usage: php replace-p-img-opt-wave18-20260918.php batch.tsv\n");
    exit(1);
}

$jobs = [];
$in = fopen($batchPath, 'rb');
while (($row = fgetcsv($in, 0, "\t")) !== false) {
    if (count($row) < 5 || $row[0] === 'product_id') {
        continue;
    }
    $jobs[] = [
        'src' => '/tmp/p-img-opt-wave18-20260918/out/' . $row[4],
        'object_key' => $row[2],
        'expect_id' => $row[1],
        'product_id' => $row[0],
        'before' => $row[3],
        'why' => 'soft',
    ];
}
fclose($in);
if ($jobs === []) {
    fwrite(STDERR, "empty batch {$batchPath}\n");
    exit(1);
}

$fh = fopen($done, is_file($done) ? 'a' : 'w');
if (!is_file($done) || filesize($done) === 0) {
    fwrite($fh, "product_id\tasset_id\tobject_key\twhy\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");
}

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
    $bytes = filesize($path);
    $bpp = $bytes * 8 / ($w * $h);
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.05 || $bpp < 1.2) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h} bpp={$bpp}\n");
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
    echo "OK #{$j['product_id']} {$basename} {$j['why']} {$j['before']} -> {$w}x{$h} bpp=" . round($bpp, 3) . " asset={$aid}\n";
}
fclose($fh);
echo "done\n";
