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
$path = '/tmp/p-img-opt-wave20-detail-20260918/out/179_01-7a830bac9b80.jpg';
$key = 'catalog/hanfu/1688/factory-huazhaoji-cx/826289398238/01-7a830bac9b80.jpg';
$expect = '674acbea-e55e-429a-bc09-ea23154326ab';
$size = getimagesize($path);
$w = (int)$size[0];
$h = (int)$size[1];
if ($w !== 636 || $h !== 1024) {
    fwrite(STDERR, "unexpected {$w}x{$h}\n");
    exit(1);
}
$stream = fopen($path, 'rb');
try {
    $desc = $library->replaceContent($disk, $key, $stream, basename($key), 'image/jpeg', 'zh_Hans_CN', $access, $w, $h);
} finally {
    fclose($stream);
}
$aid = (string)($desc['asset_id'] ?? '');
if ($aid !== '' && $aid !== $expect) {
    fwrite(STDERR, "id drift got {$aid}\n");
    exit(1);
}
echo "restored {$w}x{$h} asset={$aid}\n";
