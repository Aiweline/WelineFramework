<?php

declare(strict_types=1);

/**
 * #190：用「无抠图 HD 实拍」替换 rembg 宣纸贴纸资产。
 * php app/code/Weline/Product/scripts/replace-190-nocut-hd-portraits.php
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

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
$base = trim((string)file_get_contents('/tmp/p190-BACKUP_DIR_LATEST.txt'));
$dir = rtrim($base, "/") . '/hd-portrait';

$jobs = [
    [
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g01-paper-3x4-7780bd83409f.jpg',
        'path' => $dir . '/g01-hd-3x4.jpg',
        'expect_id' => '67b0e52a-0b0b-4936-b232-d3630a62bfd4',
    ],
    [
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g02-paper-3x4-87501c694581.jpg',
        'path' => $dir . '/g02-hd-3x4.jpg',
        'expect_id' => '71ac856b-a770-4431-9abf-a425914b05d3',
    ],
    [
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g03-paper-3x4-7a1917061349.jpg',
        'path' => $dir . '/g03-hd-3x4.jpg',
        'expect_id' => 'c9d3efe4-c7ba-4d22-b1f6-4332111ee8b1',
    ],
    [
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g04-paper-3x4-d581a109b3bb.jpg',
        'path' => $dir . '/g04-hd-3x4.jpg',
        'expect_id' => 'c1272d10-a36c-4957-968d-73690dd5175f',
    ],
    [
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g05-paper-3x4-18994cdf6255.jpg',
        'path' => $dir . '/g05-hd-3x4.jpg',
        'expect_id' => 'cc7e295d-e376-4cd2-bf5f-18bb97181b13',
    ],
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
            1200,
            1600,
        );
    } finally {
        fclose($stream);
    }
    echo 'OK ' . $j['object_key'] . ' asset=' . ($desc['asset_id'] ?? '') . "\n";
}

echo "done\n";
