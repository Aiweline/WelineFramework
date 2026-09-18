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
$out = '/tmp/p-ar-wave19-20260918/out';
$done = '/tmp/p-ar-wave19-20260918/done.tsv';

// wave19: 50 remaining non-square gallery/variant assets → 1:1 outpaint
$jobs = [
    [
        'src' => $out . '/477_03-5ff412026f8a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/03-5ff412026f8a.jpg',
        'expect_id' => '58e141d0-7bab-4e8f-afbc-d8ed86e8609b',
        'product_id' => '477',
        'before' => '997x988',
    ],
    [
        'src' => $out . '/378_07-e6f0c0ea6257.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/07-e6f0c0ea6257.jpg',
        'expect_id' => '018472bd-bed4-4247-be2a-5ef731a8d97d',
        'product_id' => '378',
        'before' => '620x713',
    ],
    [
        'src' => $out . '/508_14-4e83094467fb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/14-4e83094467fb.jpg',
        'expect_id' => 'aa6fd42f-ec6e-478a-8984-7670f657b36d',
        'product_id' => '508',
        'before' => '446x437',
    ],
    [
        'src' => $out . '/500_05-d4f63ffe0419.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/05-d4f63ffe0419.jpg',
        'expect_id' => 'cd916884-3ef3-432d-b59e-bf5cc5a637bd',
        'product_id' => '500',
        'before' => '570x741',
    ],
    [
        'src' => $out . '/382_10-889dade3208a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/749119924927/10-889dade3208a.jpg',
        'expect_id' => '0a186770-425f-4e19-bea3-8d159ba3c647',
        'product_id' => '382',
        'before' => '534x414',
    ],
    [
        'src' => $out . '/400_07-698e9c3263cb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/07-698e9c3263cb.jpg',
        'expect_id' => '8044f479-42e1-42a5-bef7-17bcb8c287af',
        'product_id' => '400',
        'before' => '800x799',
    ],
    [
        'src' => $out . '/411_07-e6f0c0ea6257.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/07-e6f0c0ea6257.jpg',
        'expect_id' => 'cb8cb48e-026d-41ac-b6d8-0434320aacd9',
        'product_id' => '411',
        'before' => '620x713',
    ],
    [
        'src' => $out . '/504_06-801fa51ac7c8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/06-801fa51ac7c8.jpg',
        'expect_id' => 'aa42bb53-7b9d-459c-be96-93ae6c8ac6a5',
        'product_id' => '504',
        'before' => '800x792',
    ],
    [
        'src' => $out . '/511_03-1150da0aa156.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771775599997/03-1150da0aa156.jpg',
        'expect_id' => '13c09bbd-b5fa-4ee8-a680-2f1fbf089c70',
        'product_id' => '511',
        'before' => '727x734',
    ],
    [
        'src' => $out . '/189_05-hd-d2d810579a4a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/05-hd-d2d810579a4a.jpg',
        'expect_id' => '1bd809a8-3f2c-4afa-9d6c-4f5b5f43cdf1',
        'product_id' => '189',
        'before' => '1448x1861',
    ],
    [
        'src' => $out . '/466_05-ec86021c56bf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/05-ec86021c56bf.jpg',
        'expect_id' => 'ce5a832f-813d-460b-8e45-bab24b89ad23',
        'product_id' => '466',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/432_07-ad8e87558264.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/670310368253/07-ad8e87558264.jpg',
        'expect_id' => 'bec9a0bb-5c85-4342-be66-fc8dcf21a8a3',
        'product_id' => '432',
        'before' => '778x800',
    ],
    [
        'src' => $out . '/499_09-c6bc31b39baf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/09-c6bc31b39baf.jpg',
        'expect_id' => '1fda2b32-cac1-46bd-804b-cac3a3880914',
        'product_id' => '499',
        'before' => '540x469',
    ],
    [
        'src' => $out . '/442_05-7b61a36e7d20.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/05-7b61a36e7d20.jpg',
        'expect_id' => '309ad9ba-40a8-4774-95a5-99cd15456d48',
        'product_id' => '442',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/380_13-77ea3a79e106.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746374029121/13-77ea3a79e106.jpg',
        'expect_id' => '61b05980-fb86-4917-8b6b-459faea877b6',
        'product_id' => '380',
        'before' => '773x800',
    ],
    [
        'src' => $out . '/229_03-26b61004ff93.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/933709641123/03-26b61004ff93.jpg',
        'expect_id' => '1a811f79-bbb9-4ee6-9655-8889b6be032f',
        'product_id' => '229',
        'before' => '1547x1920',
    ],
    [
        'src' => $out . '/241_05-929ca06ff6c3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/996206615159/05-929ca06ff6c3.jpg',
        'expect_id' => '7d2a2632-6ac2-4b7d-a649-da17e15df825',
        'product_id' => '241',
        'before' => '1200x1195',
    ],
    [
        'src' => $out . '/464_06-f4bde1a190a5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1022640495788/06-f4bde1a190a5.jpg',
        'expect_id' => 'ebfa70fd-c8f3-4df5-94c3-29aba91a6cb2',
        'product_id' => '464',
        'before' => '800x794',
    ],
    [
        'src' => $out . '/396_12-15b2be7d041b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855202285799/12-15b2be7d041b.png',
        'expect_id' => 'e2dcfc52-08fb-4a45-aefd-8b01111877e6',
        'product_id' => '396',
        'before' => '711x677',
    ],
    [
        'src' => $out . '/398_10-e15d51c743f5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855439796678/10-e15d51c743f5.jpg',
        'expect_id' => '8b12b9f9-abdb-4ec2-ad5d-3b031ca77fb0',
        'product_id' => '398',
        'before' => '834x734',
    ],
    [
        'src' => $out . '/497_09-d057286b773a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/715367119667/09-d057286b773a.jpg',
        'expect_id' => 'ea96417b-4d60-45f0-b3a3-7912c75e0162',
        'product_id' => '497',
        'before' => '563x568',
    ],
    [
        'src' => $out . '/537_05-2787cbd88794.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/05-2787cbd88794.jpg',
        'expect_id' => 'ac18e265-a38c-4596-bf41-7c91d81c86d1',
        'product_id' => '537',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/390_05-ed6ee983c753.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770444426366/05-ed6ee983c753.jpg',
        'expect_id' => '86d69997-c03b-4af8-83d5-2353a03cfa4f',
        'product_id' => '390',
        'before' => '810x1038',
    ],
    [
        'src' => $out . '/403_03-acb9ea79b683.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857385272130/03-acb9ea79b683.jpg',
        'expect_id' => '8740a06f-366f-41fd-91ad-e9aa77a67597',
        'product_id' => '403',
        'before' => '1066x1044',
    ],
    [
        'src' => $out . '/406_03-60300ac30dfa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896845945793/03-60300ac30dfa.jpg',
        'expect_id' => '19268301-112c-49bb-816f-969fdc4e9fc0',
        'product_id' => '406',
        'before' => '800x781',
    ],
    [
        'src' => $out . '/512_12-d8613d67e45d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771794189057/12-d8613d67e45d.jpg',
        'expect_id' => '746c84ee-3c47-419b-a3b5-4bb8774ed554',
        'product_id' => '512',
        'before' => '1383x1406',
    ],
    [
        'src' => $out . '/521_06-b6e9230a4ce7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/818786567633/06-b6e9230a4ce7.jpg',
        'expect_id' => 'b64de558-4d5b-4667-b729-259806391c66',
        'product_id' => '521',
        'before' => '800x792',
    ],
    [
        'src' => $out . '/522_06-2fcf555cdf07.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819124077369/06-2fcf555cdf07.jpg',
        'expect_id' => '8c5d3464-4dfc-4bff-9e3b-fa13db929d60',
        'product_id' => '522',
        'before' => '794x800',
    ],
    [
        'src' => $out . '/515_08-67aae817b54b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/813955763895/08-67aae817b54b.jpg',
        'expect_id' => '0c3c4d2a-e35b-4531-836c-3471d4bd377d',
        'product_id' => '515',
        'before' => '759x800',
    ],
    [
        'src' => $out . '/170_03-e37e0648f64c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/823792675009/03-e37e0648f64c.jpg',
        'expect_id' => '9c177501-9c62-4112-b66c-5ca11da95e03',
        'product_id' => '170',
        'before' => '737x1200',
    ],
    [
        'src' => $out . '/197_04-3d77ba0e10c3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/833553496409/04-3d77ba0e10c3.jpg',
        'expect_id' => '7399d9f4-09b5-4f30-a2a3-ef55000dbd51',
        'product_id' => '197',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/489_05-029c168672db.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705643992653/05-029c168672db.jpg',
        'expect_id' => 'caece91e-64ca-4ef4-a58a-eb3a18135886',
        'product_id' => '489',
        'before' => '1140x1485',
    ],
    [
        'src' => $out . '/218_05-2579de1f3087.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/912814485918/05-2579de1f3087.jpg',
        'expect_id' => 'fb7e6714-fba4-4cbe-b4cc-55d24cbfbd62',
        'product_id' => '218',
        'before' => '1056x1052',
    ],
    [
        'src' => $out . '/244_04-b87217f3b7fd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/997213594839/04-b87217f3b7fd.jpg',
        'expect_id' => 'bc1bb182-5c6f-4563-8269-83efcc8d92a7',
        'product_id' => '244',
        'before' => '1200x1076',
    ],
    [
        'src' => $out . '/250_07-79a67dca31a0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1003899862373/07-79a67dca31a0.jpg',
        'expect_id' => 'ee8a7514-d04d-4397-ae7c-ff7db324ad78',
        'product_id' => '250',
        'before' => '1440x1415',
    ],
    [
        'src' => $out . '/252_06-8c317cf3c16c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1012436385028/06-8c317cf3c16c.jpg',
        'expect_id' => '78cac4c1-67e0-4c47-80f8-98daac9e6cde',
        'product_id' => '252',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/457_04-89374e265973.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/713616804778/04-89374e265973.jpg',
        'expect_id' => 'eeb7aa8a-ec5e-420d-9a10-f792f3b30753',
        'product_id' => '457',
        'before' => '974x1279',
    ],
    [
        'src' => $out . '/483_04-8134e9f5ab5e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/639003693261/04-8134e9f5ab5e.jpg',
        'expect_id' => '1b38b0a3-5864-47ac-8fdc-71aa0dad40f7',
        'product_id' => '483',
        'before' => '795x794',
    ],
    [
        'src' => $out . '/525_05-65427a8e6d41.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/834116329033/05-65427a8e6d41.jpg',
        'expect_id' => '6d802207-e1dd-49a7-9b4f-e003532963bc',
        'product_id' => '525',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/526_06-e7503acfeffb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/836115959517/06-e7503acfeffb.jpg',
        'expect_id' => '4c1f3bd6-e278-4087-9e15-f0a3bf307d8b',
        'product_id' => '526',
        'before' => '654x800',
    ],
    [
        'src' => $out . '/531_05-fc7c9b6e9238.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/861289256056/05-fc7c9b6e9238.jpg',
        'expect_id' => 'bde7ed87-0285-4323-ad3b-76c5ecbbe569',
        'product_id' => '531',
        'before' => '939x1066',
    ],
    [
        'src' => $out . '/533_06-8f0bd01e249e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/871651029693/06-8f0bd01e249e.jpg',
        'expect_id' => '2e547523-b06a-4f5a-a2a7-32afb6c08a35',
        'product_id' => '533',
        'before' => '746x750',
    ],
    [
        'src' => $out . '/179_07-fe98930af45b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826289398238/07-fe98930af45b.jpg',
        'expect_id' => '162a6c5a-70d1-4621-beaf-4339881b230c',
        'product_id' => '179',
        'before' => '532x536',
    ],
    [
        'src' => $out . '/201_07-82067a2ae561.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/846337210308/07-82067a2ae561.jpg',
        'expect_id' => 'bcd991f9-caad-4951-848e-736910d53575',
        'product_id' => '201',
        'before' => '750x843',
    ],
    [
        'src' => $out . '/226_07-0bc281c39392.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/923825717296/07-0bc281c39392.jpg',
        'expect_id' => 'b4cd4044-0a10-4398-b13c-ba8cb977374f',
        'product_id' => '226',
        'before' => '792x800',
    ],
    [
        'src' => $out . '/237_07-f7bdc4f7aa80.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/981174366560/07-f7bdc4f7aa80.jpg',
        'expect_id' => '4e38d688-0cce-4359-bed7-18d641c83e0d',
        'product_id' => '237',
        'before' => '1460x1920',
    ],
    [
        'src' => $out . '/258_06-d8fb92c20e5a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1038424713943/06-d8fb92c20e5a.jpg',
        'expect_id' => 'ee07d7b2-244b-4d8d-bb4b-4b3828ab9682',
        'product_id' => '258',
        'before' => '1186x1200',
    ],
    [
        'src' => $out . '/263_06-a8b39ccfc27b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1044138917001/06-a8b39ccfc27b.jpg',
        'expect_id' => '08f00aee-42f7-41d6-812a-80c485f22a56',
        'product_id' => '263',
        'before' => '734x790',
    ],
    [
        'src' => $out . '/285_09-eb8ccbd85c44.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1060318347426/09-eb8ccbd85c44.jpg',
        'expect_id' => 'f057be38-f0c2-435c-8fff-12a5d2b5e4ed',
        'product_id' => '285',
        'before' => '800x778',
    ],
    [
        'src' => $out . '/297_06-6795e1f43210.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1075352832335/06-6795e1f43210.jpg',
        'expect_id' => '4d637549-9fcc-4166-847c-42e726beab4a',
        'product_id' => '297',
        'before' => '1706x1704',
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
