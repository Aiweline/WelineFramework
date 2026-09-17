<?php

declare(strict_types=1);

/**
 * #190：用内容延展（blur-fill）替换假灰条 paper-pad 资产。
 * php app/code/Weline/Product/scripts/replace-190-content-extend-assets.php
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(
    ScopeIdentity::global(),
    'zh_Hans_CN',
    null,
    ['catalog_maintenance'],
    'metadata_edit',
);
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
$base = '/tmp/p190-backup-20260915-231612/imgs-extend';

$jobs = [
    [
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g02-paper-3x2-175371eb4a98.jpg',
        'path' => $base . '/g02-extend-3x2.jpg',
        'w' => 2400,
        'h' => 1600,
        'expect_id' => 'b88c4ad2-9e27-439c-b9dd-403faa4cadb8',
    ],
    [
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g04-paper-3x2-ff518a51550d.jpg',
        'path' => $base . '/g04-portrait-extend-3x2.jpg',
        'w' => 2400,
        'h' => 1600,
        'expect_id' => 'ca3d1bbd-3bce-4ea4-a0e0-7b53e36ee923',
    ],
    // detail-03/04：已改竖排真裁（1200×1600），勿再用 landscape 灰条/延展覆盖
];

foreach ($jobs as $j) {
    if (!is_file($j['path'])) {
        fwrite(STDERR, "missing {$j['path']}\n");
        exit(1);
    }
    $stream = fopen($j['path'], 'rb');
    if ($stream === false) {
        fwrite(STDERR, "open fail {$j['path']}\n");
        exit(1);
    }
    try {
        $desc = $library->replaceContent(
            $disk,
            $j['object_key'],
            $stream,
            basename($j['path']),
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
    $sha = (string)($desc['sha256'] ?? '');
    echo "OK {$j['object_key']}\n  asset={$aid}\n  sha={$sha}\n";
    if ($aid !== '' && strtolower($aid) !== strtolower($j['expect_id'])) {
        fwrite(STDERR, "WARN asset id changed: expect {$j['expect_id']} got {$aid}\n");
    }
}

echo "done\n";
