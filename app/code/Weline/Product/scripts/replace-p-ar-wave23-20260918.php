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
$out = '/tmp/p-ar-wave23-20260918/out';
$done = '/tmp/p-ar-wave23-20260918/done.tsv';
$mediaRoot = dirname(__DIR__, 5) . '/pub/media/';

// wave23: 34 remaining non-square gallery/variant/main assets → 1:1 outpaint
$jobs = [
    [
        'src' => $out . '/477_09-fe110892e3f8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/09-fe110892e3f8.jpg',
        'expect_id' => '582becda-f5d6-43cc-b765-390f546de88f',
        'product_id' => '477',
        'before' => '738x608',
    ],
    [
        'src' => $out . '/378_11-f8a1e9802048.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/11-f8a1e9802048.jpg',
        'expect_id' => '6ad82dd2-d278-4b89-b596-e07e35161323',
        'product_id' => '378',
        'before' => '705x750',
    ],
    [
        'src' => $out . '/508_19-9e9e8963784f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/19-9e9e8963784f.jpg',
        'expect_id' => '9572c5c7-2b7e-40c8-aa57-739b76d61694',
        'product_id' => '508',
        'before' => '469x580',
    ],
    [
        'src' => $out . '/411_11-f8a1e9802048.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/11-f8a1e9802048.jpg',
        'expect_id' => '400d16a9-0ef0-4847-bfbb-2847a66252b8',
        'product_id' => '411',
        'before' => '705x750',
    ],
    [
        'src' => $out . '/511_10-980fcb525895.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771775599997/10-980fcb525895.jpg',
        'expect_id' => '45e7beea-98b9-42c9-a15f-76a61ac53e67',
        'product_id' => '511',
        'before' => '525x520',
    ],
    [
        'src' => $out . '/504_13-9e6db4363ff4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/13-9e6db4363ff4.jpg',
        'expect_id' => '40758069-49b7-400a-955b-950f023af72e',
        'product_id' => '504',
        'before' => '997x944',
    ],
    [
        'src' => $out . '/500_09-beb45fc21aaa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/09-beb45fc21aaa.jpg',
        'expect_id' => '980159ad-fd55-4ef2-a7ce-5508f9749a1e',
        'product_id' => '500',
        'before' => '580x743',
    ],
    [
        'src' => $out . '/400_14-b5f0cc03559e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/14-b5f0cc03559e.jpg',
        'expect_id' => '6f54a49f-737a-436c-b3b0-f947ee4dbb5f',
        'product_id' => '400',
        'before' => '534x440',
    ],
    [
        'src' => $out . '/492_13-c066b973738c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/712515408993/13-c066b973738c.jpg',
        'expect_id' => '791c0a3a-0308-4b8b-8cd1-381c41b8d382',
        'product_id' => '492',
        'before' => '1919x1861',
    ],
    [
        'src' => $out . '/193_05-4957fcaa6e6b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831937917272/05-4957fcaa6e6b.jpg',
        'expect_id' => '1e5f4698-958b-45d3-ad9c-63f6b8df5c40',
        'product_id' => '193',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/204_05-fb45f023641d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/863290574599/05-fb45f023641d.jpg',
        'expect_id' => '29dbdb85-b65c-4aa8-9a71-eeffcd5b3331',
        'product_id' => '204',
        'before' => '1200x1166',
    ],
    [
        'src' => $out . '/189_01-hd-833f7c71d9f4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/01-hd-833f7c71d9f4.jpg',
        'expect_id' => '1bc24667-6c56-4273-91c1-d37491d30a95',
        'product_id' => '189',
        'before' => '1500x1928',
    ],
    [
        'src' => $out . '/260_01-4abbed568431.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1042443716530/01-4abbed568431.jpg',
        'expect_id' => '4dd26cc3-8f65-46f2-b63a-ddacd8aed85a',
        'product_id' => '260',
        'before' => '912x1056',
    ],
    [
        'src' => $out . '/291_01-4da532dce116.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1070868426782/01-4da532dce116.jpg',
        'expect_id' => '68f0d9b8-c224-4125-88b4-b1685b583930',
        'product_id' => '291',
        'before' => '1200x1056',
    ],
    [
        'src' => $out . '/347_01-2e746ad1bbd1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703732796408/01-2e746ad1bbd1.jpg',
        'expect_id' => '86205b12-a3fb-4dd6-a9bc-da0ea6ba4fb7',
        'product_id' => '347',
        'before' => '753x800',
    ],
    [
        'src' => $out . '/406_01-4b90b92219c5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896845945793/01-4b90b92219c5.jpg',
        'expect_id' => '87a02e2c-4cdf-41bf-b000-cb4e7c30d85a',
        'product_id' => '406',
        'before' => '800x792',
    ],
    [
        'src' => $out . '/441_01-bc24ccb39aaa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708115286585/01-bc24ccb39aaa.jpg',
        'expect_id' => '1deb8497-f1c6-46dc-bc10-81e2ab9ab707',
        'product_id' => '441',
        'before' => '1222x1500',
    ],
    [
        'src' => $out . '/442_01-38f55277f2ff.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/01-38f55277f2ff.jpg',
        'expect_id' => '002fec67-bb82-4aa4-bab6-e6c062e73125',
        'product_id' => '442',
        'before' => '808x1000',
    ],
    [
        'src' => $out . '/447_05-cb8f427e50ca.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706028294343/05-cb8f427e50ca.jpg',
        'expect_id' => 'a581137f-5bab-4e55-a252-7db67122cf83',
        'product_id' => '447',
        'before' => '733x893',
    ],
    [
        'src' => $out . '/449_05-90349afbac1c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706374570152/05-90349afbac1c.jpg',
        'expect_id' => '93911cb0-c970-45e1-8c4c-4a7fdec8bbd7',
        'product_id' => '449',
        'before' => '800x794',
    ],
    [
        'src' => $out . '/451_05-e0c78274f0f5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706669287038/05-e0c78274f0f5.jpg',
        'expect_id' => '02143233-b110-41c6-80c0-431d00a2fbda',
        'product_id' => '451',
        'before' => '783x786',
    ],
    [
        'src' => $out . '/459_01-c7925ac25959.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1007764993817/01-c7925ac25959.jpg',
        'expect_id' => 'b66a5ace-b51f-475f-8489-586c66fb73c5',
        'product_id' => '459',
        'before' => '760x1000',
    ],
    [
        'src' => $out . '/462_05-7b88abca9d64.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1011253087702/05-7b88abca9d64.jpg',
        'expect_id' => '987c4f61-6c53-4e45-baf7-8e43b39e2511',
        'product_id' => '462',
        'before' => '793x800',
    ],
    [
        'src' => $out . '/464_01-d3cef85c89e5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1022640495788/01-d3cef85c89e5.jpg',
        'expect_id' => '1b5bc8c9-c2c5-4101-b4ab-534f63af72b0',
        'product_id' => '464',
        'before' => '1000x988',
    ],
    [
        'src' => $out . '/466_01-789365c379f3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/01-789365c379f3.jpg',
        'expect_id' => '62e619fc-4583-45f3-9eb7-5d8967f7ca92',
        'product_id' => '466',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/467_05-256f9198f80c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1028460404734/05-256f9198f80c.jpg',
        'expect_id' => 'ebc50563-0282-4169-800f-14847821a59f',
        'product_id' => '467',
        'before' => '900x1066',
    ],
    [
        'src' => $out . '/489_01-b897be6d9fdc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705643992653/01-b897be6d9fdc.jpg',
        'expect_id' => '9136ee96-26e9-473c-aed2-fdbe5892061b',
        'product_id' => '489',
        'before' => '1253x1500',
    ],
    [
        'src' => $out . '/499_14-5ba137a57e7c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/14-5ba137a57e7c.jpg',
        'expect_id' => 'a6d3a83b-c3cc-4923-8e46-1d3885d61077',
        'product_id' => '499',
        'before' => '401x457',
    ],
    [
        'src' => $out . '/515_01-d847a2f75877.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/813955763895/01-d847a2f75877.jpg',
        'expect_id' => 'd43d3d71-0388-4d45-b3fa-cab640716f70',
        'product_id' => '515',
        'before' => '1200x1193',
    ],
    [
        'src' => $out . '/518_01-df9d6c44b503.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/814270917745/01-df9d6c44b503.jpg',
        'expect_id' => '6049ff70-382d-4d6a-97b9-9d1501470d10',
        'product_id' => '518',
        'before' => '1000x966',
    ],
    [
        'src' => $out . '/519_04-c26262ef7f81.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/814281301448/04-c26262ef7f81.jpg',
        'expect_id' => '28be490d-fb69-4cbd-b8f6-87b5d2ebd125',
        'product_id' => '519',
        'before' => '1200x1182',
    ],
    [
        'src' => $out . '/525_01-71ce77944b81.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/834116329033/01-71ce77944b81.jpg',
        'expect_id' => '0c5cbce2-9b83-4026-af2b-4046a07232f2',
        'product_id' => '525',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/526_01-d3fc2b2f9e82.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/836115959517/01-d3fc2b2f9e82.jpg',
        'expect_id' => '378c30f8-0c1d-4d32-882d-eedac98fdde7',
        'product_id' => '526',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/535_01-218f3375ef87.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/901203282084/01-218f3375ef87.jpg',
        'expect_id' => '915cd2b8-2fa4-4b4c-9ff3-da03cddbaa4c',
        'product_id' => '535',
        'before' => '608x789',
    ],
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\tpub_media_wxh\n");

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
    $pub = $mediaRoot . $j['object_key'];
    $pubSize = is_file($pub) ? getimagesize($pub) : false;
    if (!is_array($pubSize) || (int)$pubSize[0] !== 1024 || (int)$pubSize[1] !== 1024) {
        $got = is_array($pubSize) ? ($pubSize[0] . 'x' . $pubSize[1]) : 'missing';
        fwrite(STDERR, "pub/media gate fail {$pub}: {$got}\n");
        exit(1);
    }
    $after = "{$w}x{$h}";
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['before']}\t{$after}\t是\t否\t1024x1024\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$after} pub=1024x1024 asset={$aid}\n";
}
fclose($fh);
echo "done\n";
