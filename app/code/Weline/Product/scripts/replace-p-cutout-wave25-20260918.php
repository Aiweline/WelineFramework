<?php
declare(strict_types=1);

/**
 * wave25 黑底：真虚空 → 浅灰棚景补景 replaceContent；深棚实拍 skip
 * 禁 rembg / 纯色垫 / 空放大
 *
 * php app/code/Weline/Product/scripts/replace-p-cutout-wave25-20260918.php
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
$work = '/tmp/p-cutout-wave25-20260918';
$out = $work . '/out';
$done = $work . '/done.tsv';
$jobsTsv = $work . '/jobs.tsv';
$mediaRoot = dirname(__DIR__, 5) . '/pub/media/';
$bakDir = $work . '/bak/' . date('Ymd-His');

if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true) && !is_dir($bakDir)) {
    fwrite(STDERR, "bak mkdir fail {$bakDir}\n");
    exit(1);
}

/** src_name => skip 原因；未列出的才允许补景 */
$skip = [
    '515_1_01-d847a2f75877.jpg' => '深棚实拍：人物站立且有牡丹道具，不是纯黑虚空',
    '515_2_06-b1c4b0dc8d77.jpg' => '深棚实拍：可见地面、花石道具、人物站立',
    '515_3_05-3272763f0fed.jpg' => '深棚实拍：可见地面反光与投影、人物站立',
    '515_4_03-22ea0210c07b.jpg' => '深棚实拍：可见地面与脚部光影、人物站立',
    '234_5_04-b4c101a13cc4.jpg' => '深棚实拍：布景人员与侧栏，不是虚空抠图',
    '221_6_05-2970401cc004.jpg' => '深棚实拍：竹林有地有人',
    '427_7_07-1aadbca4358f.jpg' => '深棚实拍：木地板、花盆与灯笼、人物站立',
    '370_8_04-a18e9e091ea0.jpg' => '深棚实拍：反光地面与石道具',
    '192_9_01-bb580a4267bc.jpg' => '深棚实拍：地毯、花瓶桌案与人物站立',
    '370_10_01-37d05648b044.jpg' => '深棚实拍：地面花瓣与石道具',
    '192_11_06-d2d07cf84638.jpg' => '深棚实拍：地毯、花瓶桌案与人物站立',
    '522_12_06-2fcf555cdf07.jpg' => '深棚实拍：地面、桌案鸟笼松树与人物站立',
    '185_13_04-373ef0e1a7bf.jpg' => '深棚实拍：地面花瓣、石与植物、人物站立',
    '461_16_01-98653219b65f.jpg' => '深棚实拍：可见地面与红门道具、人物站立',
];

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

if (count($allJobs) !== 17) {
    fwrite(STDERR, 'expected 17 jobs, got ' . count($allJobs) . "\n");
    exit(1);
}

$lines = [];
$replaced = 0;
$skipped = 0;
foreach ($allJobs as $j) {
    $srcName = $j['src_name'];
    $before = $j['w'] . 'x' . $j['h'];
    if (isset($skip[$srcName])) {
        $skipped++;
        $reason = $skip[$srcName];
        echo "SKIP #{$j['product_id']} {$srcName} {$reason}\n";
        $lines[] = "{$j['product_id']}\t{$j['asset_id']}\t{$j['object_key']}\tskip\t{$reason}\t{$before}\t-\t否\t-\t{$srcName}";
        continue;
    }

    $path = $out . '/' . $srcName;
    if (!is_file($path)) {
        fwrite(STDERR, "missing void out {$path}\n");
        exit(1);
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad image {$path}\n");
        exit(1);
    }
    $w = (int)$size[0];
    $h = (int)$size[1];
    if ($w < 1024 || $h < 1024 || $w !== $h) {
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
    if ($aid !== '' && strtolower($aid) !== strtolower($j['asset_id'])) {
        fwrite(STDERR, "id drift {$basename}: expect {$j['asset_id']} got {$aid}\n");
        exit(1);
    }
    $pub = $mediaRoot . $j['object_key'];
    $pubSize = is_file($pub) ? getimagesize($pub) : false;
    if (!is_array($pubSize) || (int)$pubSize[0] < 1024 || (int)$pubSize[1] < 1024 || (int)$pubSize[0] !== (int)$pubSize[1]) {
        $got = is_array($pubSize) ? ($pubSize[0] . 'x' . $pubSize[1]) : 'missing';
        fwrite(STDERR, "pub/media gate fail {$pub}: {$got}\n");
        exit(1);
    }
    $after = $pubSize[0] . 'x' . $pubSize[1];
    $replaced++;
    echo "OK #{$j['product_id']} {$basename} {$before} -> {$after} asset={$aid}\n";
    $lines[] = "{$j['product_id']}\t{$j['asset_id']}\t{$j['object_key']}\treplace\t真虚空浅灰棚景补景\t{$before}\t{$after}\t否\t{$after}\t{$srcName}";
}

if ($replaced !== 3 || $skipped !== 14) {
    fwrite(STDERR, "count mismatch replace={$replaced} skip={$skipped}\n");
    exit(1);
}

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\taction\treason\tbefore_wxh\tafter_wxh\tempty_upscale\tpub_media_wxh\tsrc_name\n");
fwrite($fh, implode("\n", $lines) . "\n");
fclose($fh);
echo "done replace={$replaced} skip={$skipped} bak={$bakDir}\n";
