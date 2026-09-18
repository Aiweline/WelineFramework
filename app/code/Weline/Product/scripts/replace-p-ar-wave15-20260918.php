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
$out = '/tmp/p-ar-wave15-20260918/out';
$done = '/tmp/p-ar-wave15-20260918/done.tsv';

$jobs = [
    [
        'src' => $out . '/398_05-915a17aa79f5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855439796678/05-915a17aa79f5.jpg',
        'expect_id' => 'b516c40d-a15d-48e6-8c20-af2845423d12',
        'product_id' => '398',
        'before' => '608x793',
    ],
    [
        'src' => $out . '/414_11-3a5dab3a797d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/1028223378473/11-3a5dab3a797d.jpg',
        'expect_id' => 'b1e71bb7-675b-4d36-ae4f-06be43df91f9',
        'product_id' => '414',
        'before' => '1473x1250',
    ],
    [
        'src' => $out . '/193_02-8b9135cc566f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831937917272/02-8b9135cc566f.jpg',
        'expect_id' => 'e18573cf-4ee3-41eb-8d2d-92e7a94702c3',
        'product_id' => '193',
        'before' => '750x1000',
    ],
    [
        'src' => $out . '/195_02-e1eb86e4fac0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832107288112/02-e1eb86e4fac0.jpg',
        'expect_id' => '18700613-6095-4976-9d3f-7b49533d5699',
        'product_id' => '195',
        'before' => '1125x1500',
    ],
    [
        'src' => $out . '/197_02-3988e7a51da5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/833553496409/02-3988e7a51da5.jpg',
        'expect_id' => 'a8d280fc-4110-4762-80d1-a83f1968a731',
        'product_id' => '197',
        'before' => '800x1067',
    ],
    [
        'src' => $out . '/441_02-e39d26c7ca61.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708115286585/02-e39d26c7ca61.jpg',
        'expect_id' => 'efd6aba6-87c1-4fef-9481-6c2e38745fd6',
        'product_id' => '441',
        'before' => '1217x1500',
    ],
    [
        'src' => $out . '/252_02-88fd33049004.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1012436385028/02-88fd33049004.jpg',
        'expect_id' => 'bdd38ed9-3dbb-47cd-bc63-946264f75ce0',
        'product_id' => '252',
        'before' => '1240x1174',
    ],
    [
        'src' => $out . '/526_02-e789f44987aa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/836115959517/02-e789f44987aa.jpg',
        'expect_id' => '9fbc1f77-e5ad-444d-8c97-b73c915f83fb',
        'product_id' => '526',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/250_02-39ed2c54c87d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1003899862373/02-39ed2c54c87d.jpg',
        'expect_id' => '12d88ba2-2ea8-42db-bdc6-a12285d67e0e',
        'product_id' => '250',
        'before' => '1920x1800',
    ],
    [
        'src' => $out . '/374_03-b255d6e64508.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738673544725/03-b255d6e64508.jpg',
        'expect_id' => 'd0f39b14-125d-4371-8162-aafaff7669d5',
        'product_id' => '374',
        'before' => '660x750',
    ],
    [
        'src' => $out . '/445_05-e6c1d03bf44f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705647723560/05-e6c1d03bf44f.jpg',
        'expect_id' => '78721a98-e67e-4e6a-8853-bc72e6d11bc5',
        'product_id' => '445',
        'before' => '707x737',
    ],
    [
        'src' => $out . '/531_02-49a9db0d4b69.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/861289256056/02-49a9db0d4b69.jpg',
        'expect_id' => 'f0093640-e9f7-41f4-86e6-e1896c350fd0',
        'product_id' => '531',
        'before' => '939x1066',
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
