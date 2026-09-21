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
$done = '/tmp/p-img-opt-wave21-20260918/done.tsv';
$batch = '/tmp/p-img-opt-wave21-20260918/batch.tsv';

if (!is_file($batch)) {
    fwrite(STDERR, "missing batch\n");
    exit(1);
}

$jobs = [];
$lines = file($batch, FILE_IGNORE_NEW_LINES);
foreach ($lines as $line) {
    if ($line === '' || str_starts_with($line, 'product_id')) {
        continue;
    }
    $c = explode("\t", $line);
    if (count($c) < 6) {
        fwrite(STDERR, "bad batch line {$line}\n");
        exit(1);
    }
    $jobs[] = [
        'product_id' => $c[0],
        'expect_id' => $c[1],
        'object_key' => $c[2],
        'before' => $c[3],
        'src_bpp' => (float)$c[4],
        'src' => $c[5],
        'why' => 'soft_small',
    ];
}

$newFile = !is_file($done);
$fh = fopen($done, $newFile ? 'w' : 'a');
if ($newFile) {
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
    $bpp = filesize($path) * 8 / ($w * $h);
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.05 || $bpp < 1.2 || $bpp <= (float)$j['src_bpp']) {
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
    echo "OK #{$j['product_id']} {$basename} {$j['why']} {$j['before']} -> {$w}x{$h} bpp=" . round($bpp, 3) . "\n";
}
fclose($fh);
echo "done\n";
