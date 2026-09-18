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
$out = '/tmp/p-ar-wave18-20260918/out';
$done = '/tmp/p-ar-wave18-20260918/done.tsv';

// wave18: 50 remaining non-square gallery/variant assets → 1:1 outpaint
$jobs = [
    [
        'src' => $out . '/477_02-ff85b29a99ea.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/02-ff85b29a99ea.jpg',
        'expect_id' => 'e67ba902-e50e-4924-8014-796be0d0d610',
        'product_id' => '477',
        'before' => '1000x988',
    ],
    [
        'src' => $out . '/378_02-cbae0c1054db.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/02-cbae0c1054db.jpg',
        'expect_id' => '159f9b92-edaf-41c2-a396-a8571bcb872a',
        'product_id' => '378',
        'before' => '737x750',
    ],
    [
        'src' => $out . '/508_10-a7e2ab0299dd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/10-a7e2ab0299dd.jpg',
        'expect_id' => '55892d05-9d66-40be-aa7e-88de6512bf61',
        'product_id' => '508',
        'before' => '373x603',
    ],
    [
        'src' => $out . '/500_04-a78f6ce13a15.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/04-a78f6ce13a15.jpg',
        'expect_id' => 'c245fced-39a0-4b13-8ee5-0ad4f15d2b82',
        'product_id' => '500',
        'before' => '689x1036',
    ],
    [
        'src' => $out . '/382_09-745d6b1126b9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/749119924927/09-745d6b1126b9.jpg',
        'expect_id' => '711ef8e7-d639-4d71-96b8-6df82d94dce3',
        'product_id' => '382',
        'before' => '534x449',
    ],
    [
        'src' => $out . '/400_06-ee61730a1802.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/06-ee61730a1802.jpg',
        'expect_id' => 'b1f55519-1522-4406-802b-8f6b7af71549',
        'product_id' => '400',
        'before' => '800x799',
    ],
    [
        'src' => $out . '/346_06-025b6524c2b7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703614631653/06-025b6524c2b7.jpg',
        'expect_id' => 'df71dba7-2706-4b8a-af57-9ceac607cb54',
        'product_id' => '346',
        'before' => '790x626',
    ],
    [
        'src' => $out . '/411_02-695eeb50093f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/02-695eeb50093f.jpg',
        'expect_id' => '9601da2c-1c95-4cb4-b5cf-8b61626ef9c2',
        'product_id' => '411',
        'before' => '738x750',
    ],
    [
        'src' => $out . '/504_02-ef49c1f2e38e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/02-ef49c1f2e38e.jpg',
        'expect_id' => 'f237b383-4aeb-45fd-b2b6-96a62d888a01',
        'product_id' => '504',
        'before' => '553x1000',
    ],
    [
        'src' => $out . '/432_06-9dc16c73982b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/670310368253/06-9dc16c73982b.jpg',
        'expect_id' => 'cd04b7d5-26bd-4a8b-9529-6f54f4b73290',
        'product_id' => '432',
        'before' => '792x800',
    ],
    [
        'src' => $out . '/511_02-8ece2202b5cd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771775599997/02-8ece2202b5cd.jpg',
        'expect_id' => 'fccab647-c2b0-4e9e-b08d-40e587d9c65a',
        'product_id' => '511',
        'before' => '1496x1486',
    ],
    [
        'src' => $out . '/189_04-hd-d8d7e7193d2a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/04-hd-d8d7e7193d2a.jpg',
        'expect_id' => '64f95dfd-ba79-4128-a698-46e745e8a68c',
        'product_id' => '189',
        'before' => '1345x1729',
    ],
    [
        'src' => $out . '/466_04-e9bf02e0257b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/04-e9bf02e0257b.jpg',
        'expect_id' => 'e4a4bec3-be25-446f-8f67-8cc099e188b2',
        'product_id' => '466',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/499_05-e82754ce3dde.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/05-e82754ce3dde.jpg',
        'expect_id' => 'f392e576-a0c3-427a-a156-f7cbccc6e7e7',
        'product_id' => '499',
        'before' => '1166x1555',
    ],
    [
        'src' => $out . '/532_10-f5becf99c01e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/871144491113/10-f5becf99c01e.jpg',
        'expect_id' => '13a9399b-a8e8-4105-a9c2-fc41c579360e',
        'product_id' => '532',
        'before' => '750x790',
    ],
    [
        'src' => $out . '/396_11-b45ef9828697.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855202285799/11-b45ef9828697.png',
        'expect_id' => '1fd9b820-f240-4e3d-bb3f-feff1b83459a',
        'product_id' => '396',
        'before' => '696x672',
    ],
    [
        'src' => $out . '/398_08-3e18f2f547d9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855439796678/08-3e18f2f547d9.jpg',
        'expect_id' => 'c4ba9c74-c2ec-4936-9c66-d73f923d306a',
        'product_id' => '398',
        'before' => '810x799',
    ],
    [
        'src' => $out . '/442_04-cba60645f16f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/04-cba60645f16f.jpg',
        'expect_id' => 'b63b1cd0-bdcb-42b8-a099-5d0ec05246c0',
        'product_id' => '442',
        'before' => '808x1000',
    ],
    [
        'src' => $out . '/497_08-ac00015d5a77.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/715367119667/08-ac00015d5a77.jpg',
        'expect_id' => '7e7f5281-5ced-4114-b2d5-74b37a395c6f',
        'product_id' => '497',
        'before' => '558x570',
    ],
    [
        'src' => $out . '/380_03-39c2aef59386.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746374029121/03-39c2aef59386.jpg',
        'expect_id' => '61a3579b-8e87-4943-8fbc-7d937cd1a9be',
        'product_id' => '380',
        'before' => '778x800',
    ],
    [
        'src' => $out . '/229_02-cdad510f60cf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/933709641123/02-cdad510f60cf.jpg',
        'expect_id' => 'bc88f2a1-31d0-4a8b-9a1e-73cb47863bff',
        'product_id' => '229',
        'before' => '1645x1920',
    ],
    [
        'src' => $out . '/241_04-51f08a2d6b71.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/996206615159/04-51f08a2d6b71.jpg',
        'expect_id' => 'd0904888-cb4b-4817-b3b4-9e7750993cbf',
        'product_id' => '241',
        'before' => '1200x1178',
    ],
    [
        'src' => $out . '/197_03-f683c17165d2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/833553496409/03-f683c17165d2.jpg',
        'expect_id' => '6d4afa2f-12d8-4e9f-be11-7ccc228ed281',
        'product_id' => '197',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/464_03-80020e704993.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1022640495788/03-80020e704993.jpg',
        'expect_id' => '2dff9eda-3904-464c-8c73-7472dec7c7ba',
        'product_id' => '464',
        'before' => '1000x996',
    ],
    [
        'src' => $out . '/535_06-9fc32f148d55.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/901203282084/06-9fc32f148d55.jpg',
        'expect_id' => '2cf95aa7-c269-4736-94de-fbcee1ff4445',
        'product_id' => '535',
        'before' => '608x730',
    ],
    [
        'src' => $out . '/252_04-789bd2efd9ba.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1012436385028/04-789bd2efd9ba.jpg',
        'expect_id' => 'e56a117e-bae6-4a5d-abd8-a5813c45a8ec',
        'product_id' => '252',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/537_04-89d8a45c51ec.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/04-89d8a45c51ec.jpg',
        'expect_id' => '7d49658a-2af9-487b-8a36-81e4356df2a1',
        'product_id' => '537',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/390_04-ad1565354e2c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770444426366/04-ad1565354e2c.jpg',
        'expect_id' => '2aa974f7-0bb1-485d-b5c8-34b3e9a4cf22',
        'product_id' => '390',
        'before' => '810x1064',
    ],
    [
        'src' => $out . '/403_02-3c1db94f250c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857385272130/02-3c1db94f250c.jpg',
        'expect_id' => 'ac7230f0-b15c-4106-a5db-60427915aaac',
        'product_id' => '403',
        'before' => '1066x1054',
    ],
    [
        'src' => $out . '/406_02-a66253bc1a03.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896845945793/02-a66253bc1a03.jpg',
        'expect_id' => '1befabc3-2989-42ca-a1e0-3ff2ed548ae5',
        'product_id' => '406',
        'before' => '800x793',
    ],
    [
        'src' => $out . '/445_08-d4a5bd400a26.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705647723560/08-d4a5bd400a26.jpg',
        'expect_id' => 'eea7a7e6-4616-41d8-84ef-8ba9a322bd94',
        'product_id' => '445',
        'before' => '608x798',
    ],
    [
        'src' => $out . '/512_05-2b2b0c115886.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771794189057/05-2b2b0c115886.jpg',
        'expect_id' => '6704d1f1-60b5-4335-b1f5-f024f4d091e3',
        'product_id' => '512',
        'before' => '1184x1183',
    ],
    [
        'src' => $out . '/521_04-3cb45c2ea37f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/818786567633/04-3cb45c2ea37f.jpg',
        'expect_id' => '6997f6a8-d456-4216-9891-57b03fc8dbf6',
        'product_id' => '521',
        'before' => '800x794',
    ],
    [
        'src' => $out . '/522_02-05edd740b5a2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819124077369/02-05edd740b5a2.jpg',
        'expect_id' => 'bf02a8d3-b04a-4610-a1ea-5d6e7a40a2a0',
        'product_id' => '522',
        'before' => '794x800',
    ],
    [
        'src' => $out . '/414_13-684e29dd4c8c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/1028223378473/13-684e29dd4c8c.jpg',
        'expect_id' => '60f1f6a4-e67d-47a3-8cf2-16b43e6018ff',
        'product_id' => '414',
        'before' => '833x834',
    ],
    [
        'src' => $out . '/515_05-3272763f0fed.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/813955763895/05-3272763f0fed.jpg',
        'expect_id' => '6e0412f7-504c-44bd-b65e-dae0ae3f0434',
        'product_id' => '515',
        'before' => '1200x1173',
    ],
    [
        'src' => $out . '/170_02-e25f2adcf9b0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/823792675009/02-e25f2adcf9b0.jpg',
        'expect_id' => '38ba1ec2-477d-451b-9b88-297fcc261089',
        'product_id' => '170',
        'before' => '766x1200',
    ],
    [
        'src' => $out . '/193_03-84f0ad842da9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831937917272/03-84f0ad842da9.jpg',
        'expect_id' => '9966a62a-9b77-46a6-ab08-337d38696697',
        'product_id' => '193',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/489_04-1def147ef39f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705643992653/04-1def147ef39f.jpg',
        'expect_id' => '4c5792c5-1b29-4fa0-a62d-55900e728e5d',
        'product_id' => '489',
        'before' => '990x1181',
    ],
    [
        'src' => $out . '/218_03-f97a2749b8ba.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/912814485918/03-f97a2749b8ba.jpg',
        'expect_id' => '92d0dfec-2be1-4912-ad16-5105a44168ae',
        'product_id' => '218',
        'before' => '1056x1073',
    ],
    [
        'src' => $out . '/244_03-3a0acec55115.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/997213594839/03-3a0acec55115.jpg',
        'expect_id' => '1bbbea19-cc28-40bf-b09e-2f2457a1060d',
        'product_id' => '244',
        'before' => '1200x1186',
    ],
    [
        'src' => $out . '/250_05-fccd46f19592.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1003899862373/05-fccd46f19592.jpg',
        'expect_id' => 'a8dc8210-f08b-41e3-ad55-bd4b8d329c13',
        'product_id' => '250',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/331_07-aba46cb47960.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703149373866/07-aba46cb47960.jpg',
        'expect_id' => '9996958e-1db8-422b-b5ba-e2af1c4c945c',
        'product_id' => '331',
        'before' => '739x608',
    ],
    [
        'src' => $out . '/360_07-90643cdb4869.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715071130913/07-90643cdb4869.jpg',
        'expect_id' => '84695e78-92cc-488c-8316-7f73c1adf6e4',
        'product_id' => '360',
        'before' => '1269x1155',
    ],
    [
        'src' => $out . '/373_07-cfb9e8146579.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/725522200081/07-cfb9e8146579.jpg',
        'expect_id' => '014cada1-e745-4d44-9748-94d03d7c91b6',
        'product_id' => '373',
        'before' => '790x742',
    ],
    [
        'src' => $out . '/379_05-d913c30e6b88.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746304492874/05-d913c30e6b88.jpg',
        'expect_id' => '77aa5831-4c37-4d8e-a84f-1d509cb40775',
        'product_id' => '379',
        'before' => '704x800',
    ],
    [
        'src' => $out . '/483_02-3b22220b430d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/639003693261/02-3b22220b430d.jpg',
        'expect_id' => '8d8f3aca-de0b-4a04-9327-79b0a3f8fe1a',
        'product_id' => '483',
        'before' => '798x789',
    ],
    [
        'src' => $out . '/526_04-d9a86ed47dfa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/836115959517/04-d9a86ed47dfa.jpg',
        'expect_id' => '5f6831da-e96f-41b6-b8db-3f9150a0075e',
        'product_id' => '526',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/374_02-fcf41b186256.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738673544725/02-fcf41b186256.jpg',
        'expect_id' => 'a227e83d-9f84-44dd-bce1-0ade1fc77084',
        'product_id' => '374',
        'before' => '738x750',
    ],
    [
        'src' => $out . '/405_02-214ba78056b0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896146359561/02-214ba78056b0.jpg',
        'expect_id' => 'c88f6bce-ab1a-4bde-937e-9c8111e0bb5d',
        'product_id' => '405',
        'before' => '1316x1299',
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
