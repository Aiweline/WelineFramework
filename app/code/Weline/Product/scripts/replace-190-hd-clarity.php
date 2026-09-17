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
$dir = '/tmp/p190-backup-20260916-hd-clarity2/out';

$jobs = [
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g01-paper-3x4-7780bd83409f.jpg', 'expect_id' => '67b0e52a-0b0b-4936-b232-d3630a62bfd4', 'w' => 1800, 'h' => 2400],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g02-paper-3x4-87501c694581.jpg', 'expect_id' => '71ac856b-a770-4431-9abf-a425914b05d3', 'w' => 1800, 'h' => 2400],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g03-paper-3x4-7a1917061349.jpg', 'expect_id' => 'c9d3efe4-c7ba-4d22-b1f6-4332111ee8b1', 'w' => 1800, 'h' => 2400],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g04-paper-3x4-d581a109b3bb.jpg', 'expect_id' => 'c1272d10-a36c-4957-968d-73690dd5175f', 'w' => 1800, 'h' => 2400],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-g05-paper-3x4-18994cdf6255.jpg', 'expect_id' => 'cc7e295d-e376-4cd2-bf5f-18bb97181b13', 'w' => 1800, 'h' => 2400],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-detail-03-mid-hd-3c12fc24dd2c.jpg', 'expect_id' => 'd05e6db3-7465-483b-8080-226315baaff2', 'w' => 1800, 'h' => 2400],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-detail-04-upper-hd-669013eff482.jpg', 'expect_id' => '2e811866-1c15-42a5-bca2-6e801ea9d93a', 'w' => 1800, 'h' => 2400],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-detail-06-back-macro-hd-94b966da5338.jpg', 'expect_id' => 'f1d6b71c-6ac6-4502-b030-178ca56498d9', 'w' => 2000, 'h' => 1800],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-detail-07-clean-hd-f69070b66241.jpg', 'expect_id' => 'e1008607-3741-4f19-a5ed-716390d159b5', 'w' => 1620, 'h' => 1620],
    ['object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/830664198949/suite-190-detail-08-clean-hd-7d844488273d.jpg', 'expect_id' => '9c26b17a-0ae1-4f88-80cc-7c05830a60be', 'w' => 1620, 'h' => 1620],
];

foreach ($jobs as $j) {
    $path = $dir . '/' . basename($j['object_key']);
    if (!is_file($path)) { fwrite(STDERR, "missing $path\n"); exit(1); }
    $stream = fopen($path, 'rb');
    try {
        $desc = $library->replaceContent($disk, $j['object_key'], $stream, basename($path), 'image/jpeg', 'zh_Hans_CN', $access, $j['w'], $j['h']);
    } finally { fclose($stream); }
    $aid = (string)($desc['asset_id'] ?? '');
    if ($aid !== '' && $aid !== $j['expect_id']) { fwrite(STDERR, "id drift {$j['object_key']}\n"); exit(1); }
    echo 'OK ' . basename($j['object_key']) . " {$j['w']}x{$j['h']}\n";
}
echo "done\n";
