<?php

declare(strict_types=1);

/**
 * #200 凤鸣在竹 · 主图/画廊 AI 升清 replaceContent
 * method=GenerateImage reference remaster（禁空放大；bpp 提升）
 *
 * php app/code/Weline/Product/scripts/replace-200-hd-clarity.php
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

$bak = '/tmp/p200-backup-20260916-hd-clarity';
if (!is_dir($bak) && !mkdir($bak, 0775, true) && !is_dir($bak)) {
    throw new RuntimeException('bak mkdir fail');
}

$mediaRoot = dirname(__DIR__, 5) . '/pub/media';
$jobs = [
    [
        'src' => '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets/p200-01-hd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/01-4c23ce0589ff.jpg',
        'expect_id' => '8740b5b9-c6c6-4b52-8e95-dbf0491c71bb',
        'w' => 864,
        'h' => 1152,
    ],
    [
        'src' => '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets/p200-02-hd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/02-4758712ac970.jpg',
        'expect_id' => '1311d574-5381-42b6-9d10-127cbeca5d1a',
        'w' => 1024,
        'h' => 1024,
    ],
    [
        'src' => '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets/p200-04-hd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/04-27a56271ba71.jpg',
        'expect_id' => '4a27beee-7bb0-41b2-93de-f3fed18d47e7',
        'w' => 1024,
        'h' => 1024,
    ],
    [
        'src' => '/Users/weline/.cursor/projects/Users-weline-Project-Official/assets/p200-07-hd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/837125453122/07-748e46ce0c12.jpg',
        'expect_id' => '71b936e9-5cf9-47e2-885a-3c66bf1cd76f',
        'w' => 1024,
        'h' => 1024,
    ],
];

foreach ($jobs as $j) {
    if (!is_file($j['src'])) {
        fwrite(STDERR, "missing {$j['src']}\n");
        exit(1);
    }
    $orig = $mediaRoot . '/' . $j['object_key'];
    $bakPath = $bak . '/' . basename($j['object_key']);
    if (is_file($orig) && !is_file($bakPath)) {
        copy($orig, $bakPath);
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
            $j['w'],
            $j['h'],
        );
    } finally {
        fclose($stream);
    }
    $aid = (string)($desc['asset_id'] ?? '');
    if ($aid !== '' && strtolower($aid) !== strtolower($j['expect_id'])) {
        fwrite(STDERR, "id drift {$j['object_key']} {$aid}\n");
        exit(1);
    }
    echo 'OK ' . basename($j['object_key']) . " {$j['w']}x{$j['h']}\n";
}
echo "done\n";
