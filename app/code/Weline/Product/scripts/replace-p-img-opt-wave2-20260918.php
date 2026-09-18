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
$out = '/tmp/p-img-opt-wave2-20260918/out';
$done = '/tmp/p-img-opt-wave2-20260918/done.tsv';

$jobs = [
    [
        'src' => $out . '/477_26-40d67e1af3cb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/26-40d67e1af3cb.jpg',
        'expect_id' => 'f5cb4e77-52b5-4ae7-9492-4970ecc4c847',
        'product_id' => '477',
        'before' => '287x172',
    ],
    [
        'src' => $out . '/466_01-789365c379f3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/01-789365c379f3.jpg',
        'expect_id' => '62e619fc-4583-45f3-9eb7-5d8967f7ca92',
        'product_id' => '466',
        'before' => '1903x1581',
    ],
    [
        'src' => $out . '/457_07-e963d2c940c3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/713616804778/07-e963d2c940c3.jpg',
        'expect_id' => 'c9c2625d-677e-4eeb-b0da-89ded92dd852',
        'product_id' => '457',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/445_06-063ef30330ed.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705647723560/06-063ef30330ed.jpg',
        'expect_id' => '8fa0840d-e211-4d67-b4fc-f0f3d050a7ed',
        'product_id' => '445',
        'before' => '608x798',
    ],
    [
        'src' => $out . '/442_02-1fd1fab21f62.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/02-1fd1fab21f62.jpg',
        'expect_id' => '271cd8fc-8a85-4a79-adb8-73b24a2cad3a',
        'product_id' => '442',
        'before' => '808x1000',
    ],
    [
        'src' => $out . '/441_03-fbabcbe23867.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708115286585/03-fbabcbe23867.jpg',
        'expect_id' => '40bc7d7c-7274-46a1-bfa5-57c1ea19e98c',
        'product_id' => '441',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/414_15-d331159bec31.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/1028223378473/15-d331159bec31.jpg',
        'expect_id' => 'ce93037f-21ea-4f22-af30-23b3f936dbfc',
        'product_id' => '414',
        'before' => '1510x1250',
    ],
    [
        'src' => $out . '/411_05-2e5952d3582f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/05-2e5952d3582f.jpg',
        'expect_id' => '0ea20273-e26d-48d0-a6cc-cc790465b4c5',
        'product_id' => '411',
        'before' => '570x742',
    ],
    [
        'src' => $out . '/400_13-604c2b8fa19e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/13-604c2b8fa19e.jpg',
        'expect_id' => '56a159df-cc4e-4423-b316-c9ecf0acdd20',
        'product_id' => '400',
        'before' => '534x413',
    ],
    [
        'src' => $out . '/398_05-915a17aa79f5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855439796678/05-915a17aa79f5.jpg',
        'expect_id' => 'b516c40d-a15d-48e6-8c20-af2845423d12',
        'product_id' => '398',
        'before' => '608x793',
    ],
    [
        'src' => $out . '/396_05-896ba1d201fa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855202285799/05-896ba1d201fa.jpg',
        'expect_id' => '77bdbdd6-f79b-4c26-86a7-fb2d5693dd93',
        'product_id' => '396',
        'before' => '608x794',
    ],
    [
        'src' => $out . '/390_06-ec4706d1a24a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770444426366/06-ec4706d1a24a.jpg',
        'expect_id' => 'c3e22fa2-ae60-486b-9bbf-b2ff140bdd61',
        'product_id' => '390',
        'before' => '608x800',
    ],
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");

foreach ($jobs as $j) {
    $path = $j['src'];
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        exit(1);
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad image {$path}\n");
        exit(1);
    }
    $w = (int)$size[0];
    $h = (int)$size[1];
    if ($w < 1024 || $h < 1024 || abs(($w / $h) - 1.0) > 0.05) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h}\n");
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
    $after = "{$w}x{$h}";
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['before']}\t{$after}\t是\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$after} asset={$aid}\n";
}
fclose($fh);
echo "done\n";
