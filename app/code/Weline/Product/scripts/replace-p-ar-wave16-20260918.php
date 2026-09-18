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
$out = '/tmp/p-ar-wave16-20260918/out';
$done = '/tmp/p-ar-wave16-20260918/done.tsv';

// 18 jobs; #411/#378 share generated pixels (same basename) but distinct object_keys
$jobs = [
    [
        'src' => $out . '/396_05-896ba1d201fa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855202285799/05-896ba1d201fa.jpg',
        'expect_id' => '77bdbdd6-f79b-4c26-86a7-fb2d5693dd93',
        'product_id' => '396',
        'before' => '608x794',
    ],
    [
        'src' => $out . '/499_06-dcb2d433ab2b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/06-dcb2d433ab2b.jpg',
        'expect_id' => '95d612aa-2aee-4695-9cda-9f0a7b65f675',
        'product_id' => '499',
        'before' => '800x872',
    ],
    [
        'src' => $out . '/492_11-f5eb90460608.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/712515408993/11-f5eb90460608.jpg',
        'expect_id' => '093a8c96-5180-43f9-8757-5b7c97deeb8c',
        'product_id' => '492',
        'before' => '702x647',
    ],
    [
        'src' => $out . '/196_02-202744668b7b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/832571599410/02-202744668b7b.jpg',
        'expect_id' => '3898ad06-b98e-4b6c-a527-bd5a3196d6a4',
        'product_id' => '196',
        'before' => '1125x1500',
    ],
    [
        'src' => $out . '/457_02-7b8aabff8bae.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/713616804778/02-7b8aabff8bae.jpg',
        'expect_id' => '3bcec0e6-c942-4567-bac3-6fbceaff99a7',
        'product_id' => '457',
        'before' => '974x1280',
    ],
    [
        'src' => $out . '/489_02-9d0f30a50afd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705643992653/02-9d0f30a50afd.jpg',
        'expect_id' => '6b0659b9-1953-4af8-93be-0a87bfd6d91a',
        'product_id' => '489',
        'before' => '1222x1500',
    ],
    [
        'src' => $out . '/390_02-473a8ace0a1e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770444426366/02-473a8ace0a1e.jpg',
        'expect_id' => 'a68b590c-4bf3-4435-8921-b09969b46d47',
        'product_id' => '390',
        'before' => '810x1064',
    ],
    [
        'src' => $out . '/537_02-01a916597ae1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/02-01a916597ae1.jpg',
        'expect_id' => '515f9777-78ae-4627-89e4-6db540759b9c',
        'product_id' => '537',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/400_05-13217d2658c2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/05-13217d2658c2.jpg',
        'expect_id' => '70a28d73-1035-4a9f-83ce-e7a80f97dbf2',
        'product_id' => '400',
        'before' => '812x1020',
    ],
    [
        'src' => $out . '/382_05-f2dff5b55c89.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/749119924927/05-f2dff5b55c89.jpg',
        'expect_id' => 'ddbfae0a-5437-42d9-afa5-2a86e02a6645',
        'product_id' => '382',
        'before' => '916x1187',
    ],
    [
        'src' => $out . '/508_11-65f76acfa95a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/11-65f76acfa95a.jpg',
        'expect_id' => '713d6c0d-f562-4605-a0ef-4e4d8dd49db0',
        'product_id' => '508',
        'before' => '543x404',
    ],
    [
        'src' => $out . '/189_02-hd-d076e13cca8d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/02-hd-d076e13cca8d.jpg',
        'expect_id' => '79d088ee-c4e9-43fa-a033-48096022f1f0',
        'product_id' => '189',
        'before' => '1159x1490',
    ],
    [
        'src' => $out . '/442_02-1fd1fab21f62.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/02-1fd1fab21f62.jpg',
        'expect_id' => '271cd8fc-8a85-4a79-adb8-73b24a2cad3a',
        'product_id' => '442',
        'before' => '808x1000',
    ],
    [
        'src' => $out . '/466_02-ba2f25d5c9d6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/02-ba2f25d5c9d6.jpg',
        'expect_id' => 'd3b0ab72-74d7-47e2-80a8-0d62f150eabd',
        'product_id' => '466',
        'before' => '1668x1565',
    ],
    [
        'src' => $out . '/500_02-f35e9f93a6cd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/02-f35e9f93a6cd.jpg',
        'expect_id' => '26e17b80-2a89-4578-bf9c-ae6808f54648',
        'product_id' => '500',
        'before' => '584x768',
    ],
    [
        'src' => $out . '/411_03-58ec2f05b159.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/03-58ec2f05b159.jpg',
        'expect_id' => 'd1e26682-180a-40b8-8d0a-955d059e6771',
        'product_id' => '411',
        'before' => '608x745',
    ],
    [
        'src' => $out . '/378_03-58ec2f05b159.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/03-58ec2f05b159.jpg',
        'expect_id' => '8b111571-7b99-4dd3-a92e-b8a6b6ddec02',
        'product_id' => '378',
        'before' => '608x745',
    ],
    [
        'src' => $out . '/477_04-0b83e094d861.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/04-0b83e094d861.jpg',
        'expect_id' => '1520836d-3913-44d3-b4f8-ed9e38c9bffc',
        'product_id' => '477',
        'before' => '822x982',
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
