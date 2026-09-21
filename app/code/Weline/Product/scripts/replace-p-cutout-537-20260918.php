<?php

declare(strict_types=1);

/**
 * #537 浮光跃金 · 黑底虚空抠图 → 浅灰棚景真补景 replaceContent
 * 禁 rembg / 纯色垫 / cover 空放大
 *
 * php app/code/Weline/Product/scripts/replace-p-cutout-537-20260918.php
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$work = '/tmp/p-cutout-537-20260918';
$outDir = $work . '/out';
$bakDir = $work . '/bak/20260918-182808';
$mediaRoot = dirname(__DIR__, 5) . '/pub/media';

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;

if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true) && !is_dir($bakDir)) {
    throw new RuntimeException('bak mkdir fail: ' . $bakDir);
}

$jobs = [
    [
        'src' => $outDir . '/537_01-1503609c2c07.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/01-1503609c2c07.jpg',
        'expect_id' => 'bb651236-fdb9-4571-9572-da39e9c22e30',
        'role' => 'main',
    ],
    [
        'src' => $outDir . '/537_02-01a916597ae1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/02-01a916597ae1.jpg',
        'expect_id' => '515f9777-78ae-4627-89e4-6db540759b9c',
        'role' => 'gallery',
    ],
    [
        'src' => $outDir . '/537_03-92c5aa230be3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/03-92c5aa230be3.jpg',
        'expect_id' => '9b11e885-1f24-4f2d-9527-13407da924d1',
        'role' => 'gallery',
    ],
    [
        'src' => $outDir . '/537_06-99740bf6ec33.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/06-99740bf6ec33.jpg',
        'expect_id' => '447b40eb-c253-4bdd-8891-535c387d1e49',
        'role' => 'gallery',
    ],
    [
        'src' => $outDir . '/537_08-68213b1eaefd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/08-68213b1eaefd.jpg',
        'expect_id' => '00e43c04-80f4-48ec-aa60-41721da77e12',
        'role' => 'gallery',
    ],
];

foreach ($jobs as $j) {
    if (!is_file($j['src'])) {
        fwrite(STDERR, "missing {$j['src']}\n");
        exit(1);
    }
    $info = @getimagesize($j['src']);
    if ($info === false) {
        fwrite(STDERR, "bad image {$j['src']}\n");
        exit(1);
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w < 800 || $h < 800 || $w !== $h) {
        fwrite(STDERR, "AR/size fail {$j['src']} {$w}x{$h}\n");
        exit(1);
    }

    $orig = $mediaRoot . '/' . $j['object_key'];
    $bakPath = $bakDir . '/' . basename($j['object_key']);
    if (is_file($orig) && !is_file($bakPath)) {
        if (!copy($orig, $bakPath)) {
            fwrite(STDERR, "bak fail {$orig}\n");
            exit(1);
        }
        echo "bak {$bakPath}\n";
    }

    $stream = fopen($j['src'], 'rb');
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
    if ($aid !== '' && strtolower($aid) !== strtolower($j['expect_id'])) {
        fwrite(STDERR, "id drift {$j['object_key']} got={$aid} expect={$j['expect_id']}\n");
        exit(1);
    }
    echo 'OK ' . $j['role'] . ' ' . basename($j['object_key']) . " {$w}x{$h} asset={$aid}\n";
}

echo 'done ' . count($jobs) . "\n";
