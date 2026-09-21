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

$root = '/tmp/p-img-opt-wave17-20260918';
$manifest = $root . '/manifest.tsv';
$donePath = $root . '/done.tsv';
$failPath = $root . '/fail.tsv';

if (!is_file($manifest)) {
    fwrite(STDERR, "missing manifest\n");
    exit(1);
}

$doneKeys = [];
$doneLines = ["product_id\tasset_id\tobject_key\twhy\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale"];
if (is_file($donePath)) {
    $existing = file($donePath, FILE_IGNORE_NEW_LINES);
    if (is_array($existing) && isset($existing[0])) {
        $doneLines = [$existing[0]];
        foreach (array_slice($existing, 1) as $ln) {
            if ($ln === '') {
                continue;
            }
            $cols = explode("\t", $ln);
            if (isset($cols[2]) && $cols[2] !== '') {
                $doneKeys[$cols[2]] = true;
                $doneLines[] = $ln;
            }
        }
    }
}

$failLines = ["object_key\treason"];
if (is_file($failPath)) {
    $existingFail = file($failPath, FILE_IGNORE_NEW_LINES);
    if (is_array($existingFail) && isset($existingFail[0])) {
        $failLines = [$existingFail[0]];
        foreach (array_slice($existingFail, 1) as $ln) {
            if ($ln !== '') {
                $failLines[] = $ln;
            }
        }
    }
}

$rows = file($manifest, FILE_IGNORE_NEW_LINES);
if (!is_array($rows)) {
    fwrite(STDERR, "bad manifest\n");
    exit(1);
}

$replaced = 0;
$skipped = 0;
foreach (array_slice($rows, 1) as $ln) {
    if ($ln === '') {
        continue;
    }
    $cols = explode("\t", $ln);
    if (count($cols) < 7) {
        fwrite(STDERR, "bad row {$ln}\n");
        exit(1);
    }
    [$pid, $expectId, $objectKey, $why, $before, $srcBpp, $path] = $cols;
    if (isset($doneKeys[$objectKey])) {
        echo "SKIP {$objectKey}\n";
        $skipped++;
        continue;
    }
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        $failLines[] = "{$objectKey}\tmissing";
        continue;
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad {$path}\n");
        $failLines[] = "{$objectKey}\tbad_image";
        continue;
    }
    $w = (int)$size[0];
    $h = (int)$size[1];
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.05) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h}\n");
        $failLines[] = "{$objectKey}\tsize {$w}x{$h}";
        continue;
    }
    $bytes = filesize($path);
    $bpp = ($bytes * 8) / ($w * $h);
    if ($bpp <= (float)$srcBpp) {
        fwrite(STDERR, "bpp fail {$objectKey}: {$bpp} <= {$srcBpp}\n");
        $failLines[] = "{$objectKey}\tbpp {$bpp}";
        continue;
    }
    $basename = basename($objectKey);
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        fwrite(STDERR, "open fail {$path}\n");
        $failLines[] = "{$objectKey}\topen";
        continue;
    }
    try {
        $desc = $library->replaceContent(
            $disk,
            $objectKey,
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
    if ($aid !== '' && $aid !== $expectId) {
        fwrite(STDERR, "id drift {$basename}: expect {$expectId} got {$aid}\n");
        $failLines[] = "{$objectKey}\tid_drift {$aid}";
        continue;
    }
    $doneLines[] = "{$pid}\t{$expectId}\t{$objectKey}\t{$why}\t{$before}\t{$w}x{$h}\t是\t否";
    $doneKeys[$objectKey] = true;
    $replaced++;
    echo "OK #{$pid} {$basename} {$why} {$before} -> {$w}x{$h} bpp {$bpp}\n";
}

file_put_contents($donePath, implode("\n", $doneLines) . "\n");
file_put_contents($failPath, implode("\n", $failLines) . "\n");
echo "replaced={$replaced} skipped={$skipped}\n";
