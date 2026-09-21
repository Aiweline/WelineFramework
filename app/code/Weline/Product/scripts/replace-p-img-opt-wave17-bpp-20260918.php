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

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave17-20260918/out/483_q98.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/639003693261/05-810b178a5b8d.jpg',
        'expect_id' => '',
        'product_id' => '483',
    ],
    [
        'src' => '/tmp/p-img-opt-wave17-20260918/out/448_q98.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706245191669/03-e0fedad0cbc8.jpg',
        'expect_id' => '',
        'product_id' => '448',
    ],
    [
        'src' => '/tmp/p-img-opt-wave17-20260918/out/439_q98.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/707628780493/05-d51f2708dc4c.jpg',
        'expect_id' => '',
        'product_id' => '439',
    ],
];

$tsv = file('/tmp/p-img-opt-wave17-20260918/jobs.tsv', FILE_IGNORE_NEW_LINES);
$map = [];
foreach ($tsv as $i => $line) {
    if ($i === 0 || $line === '') {
        continue;
    }
    $p = explode("\t", $line);
    $map[$p[2]] = $p[1];
}

foreach ($jobs as $j) {
    $path = $j['src'];
    $size = getimagesize($path);
    $w = (int)$size[0];
    $h = (int)$size[1];
    $bpp = filesize($path) * 8 / ($w * $h);
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.05 || $bpp < 1.2) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h} bpp={$bpp}\n");
        exit(1);
    }
    $expect = $map[$j['object_key']] ?? '';
    $stream = fopen($path, 'rb');
    try {
        $desc = $library->replaceContent(
            $disk,
            $j['object_key'],
            $stream,
            basename($j['object_key']),
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
    if ($expect !== '' && $aid !== '' && $aid !== $expect) {
        fwrite(STDERR, "id drift {$j['object_key']}: expect {$expect} got {$aid}\n");
        exit(1);
    }
    echo "OK #{$j['product_id']} {$w}x{$h} bpp=" . round($bpp, 3) . "\n";
}
echo "done\n";
