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
$out = '/tmp/p-ar-wave21-20260918/out';
$done = '/tmp/p-ar-wave21-20260918/done.tsv';

// wave21: 50 remaining non-square gallery/variant assets → 1:1 outpaint
$jobs = [
    [
        'src' => $out . '/477_07-c2b3ccae4a48.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/07-c2b3ccae4a48.jpg',
        'expect_id' => '505b3c0c-29f7-4167-9765-3aa370fad188',
        'product_id' => '477',
        'before' => '746x608',
    ],
    [
        'src' => $out . '/378_09-0ca75bf40e8e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/09-0ca75bf40e8e.jpg',
        'expect_id' => 'c0d2c337-d68d-41f7-a0fb-6369c39f37d9',
        'product_id' => '378',
        'before' => '650x741',
    ],
    [
        'src' => $out . '/508_17-b5ba0657e955.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/17-b5ba0657e955.jpg',
        'expect_id' => '5b7823e0-c3a8-4e59-8d1b-c4f535a9f65e',
        'product_id' => '508',
        'before' => '520x477',
    ],
    [
        'src' => $out . '/500_07-acfd46b15807.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/07-acfd46b15807.jpg',
        'expect_id' => 'dcf1ed1d-c5f6-4ef7-969b-268bf663a2c3',
        'product_id' => '500',
        'before' => '642x1036',
    ],
    [
        'src' => $out . '/411_09-0ca75bf40e8e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/09-0ca75bf40e8e.jpg',
        'expect_id' => '29d6185d-21a7-43b8-83fc-e8dd0a7d5828',
        'product_id' => '411',
        'before' => '650x741',
    ],
    [
        'src' => $out . '/504_09-e83af78a2289.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/09-e83af78a2289.jpg',
        'expect_id' => '9ef9c4ae-1ade-466d-8ec6-445673171403',
        'product_id' => '504',
        'before' => '997x974',
    ],
    [
        'src' => $out . '/511_05-1bf047c1c14f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771775599997/05-1bf047c1c14f.jpg',
        'expect_id' => '20d5eabc-a85e-406d-b124-cacce8b2e4bc',
        'product_id' => '511',
        'before' => '1484x1500',
    ],
    [
        'src' => $out . '/400_11-403efd09f156.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/11-403efd09f156.jpg',
        'expect_id' => 'ed3a86e1-326b-425c-a56c-f6da383ba106',
        'product_id' => '400',
        'before' => '534x419',
    ],
    [
        'src' => $out . '/189_07-hd-1c2ecd8f2d36.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/07-hd-1c2ecd8f2d36.jpg',
        'expect_id' => '0cf3549d-f59e-4df2-a437-0326ca6a34ab',
        'product_id' => '189',
        'before' => '1432x1841',
    ],
    [
        'src' => $out . '/466_07-8c317cf3c16c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/07-8c317cf3c16c.jpg',
        'expect_id' => 'f69a49fc-e891-4c05-8f54-4b7e113f8d6d',
        'product_id' => '466',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/229_06-715bf8058c9b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/933709641123/06-715bf8058c9b.jpg',
        'expect_id' => '8d3f1032-df21-4db7-a0fc-e5dd91f9acbf',
        'product_id' => '229',
        'before' => '1683x1920',
    ],
    [
        'src' => $out . '/442_07-294f9bda65fb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/07-294f9bda65fb.jpg',
        'expect_id' => '030b5a7a-619f-4897-8b06-ac5f7cabdd63',
        'product_id' => '442',
        'before' => '808x1000',
    ],
    [
        'src' => $out . '/382_14-9397accd9d50.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/749119924927/14-9397accd9d50.jpg',
        'expect_id' => 'b848ee82-9e05-4c85-a8d9-78f6ff166bbb',
        'product_id' => '382',
        'before' => '534x481',
    ],
    [
        'src' => $out . '/406_06-5d93fb4b5770.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896845945793/06-5d93fb4b5770.jpg',
        'expect_id' => '4ffbf1fe-c422-4e23-9b91-a6983d03af8f',
        'product_id' => '406',
        'before' => '800x792',
    ],
    [
        'src' => $out . '/241_07-e07768b44b2b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/996206615159/07-e07768b44b2b.jpg',
        'expect_id' => '1fc88325-2262-4b4e-8968-39601d2be96b',
        'product_id' => '241',
        'before' => '800x791',
    ],
    [
        'src' => $out . '/537_01-1503609c2c07.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/01-1503609c2c07.jpg',
        'expect_id' => 'bb651236-fdb9-4571-9572-da39e9c22e30',
        'product_id' => '537',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/170_06-64d317d929b6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/823792675009/06-64d317d929b6.jpg',
        'expect_id' => 'b59b0512-2ca6-48db-94c1-5fb39d9a7bc0',
        'product_id' => '170',
        'before' => '743x1064',
    ],
    [
        'src' => $out . '/370_09-ab89b7574971.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715276723549/09-ab89b7574971.jpg',
        'expect_id' => '3d17dd55-5e96-4bd8-a75c-c82120b5e1a3',
        'product_id' => '370',
        'before' => '614x713',
    ],
    [
        'src' => $out . '/374_07-860f1500b019.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738673544725/07-860f1500b019.jpg',
        'expect_id' => 'aea65cb2-ee72-4228-8d2c-aa814237cd81',
        'product_id' => '374',
        'before' => '741x608',
    ],
    [
        'src' => $out . '/375_04-40238c3c400b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738962342070/04-40238c3c400b.jpg',
        'expect_id' => 'fb1db04b-fc30-411d-bf8b-e98d1cf59038',
        'product_id' => '375',
        'before' => '800x791',
    ],
    [
        'src' => $out . '/376_06-df3a57814951.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/739090011198/06-df3a57814951.jpg',
        'expect_id' => '2bf195bf-b37c-4276-8d7f-cba88efa5492',
        'product_id' => '376',
        'before' => '621x708',
    ],
    [
        'src' => $out . '/380_16-6ceafab1a616.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746374029121/16-6ceafab1a616.jpg',
        'expect_id' => '582e6a6c-cac8-4227-b395-f2ed92da86d7',
        'product_id' => '380',
        'before' => '796x800',
    ],
    [
        'src' => $out . '/388_15-69652923eafa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/769349326765/15-69652923eafa.jpg',
        'expect_id' => 'e7a1be0b-d331-4722-bb62-3f705c7f2d8c',
        'product_id' => '388',
        'before' => '608x628',
    ],
    [
        'src' => $out . '/390_08-b364d846240f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770444426366/08-b364d846240f.jpg',
        'expect_id' => 'b4a07863-16fd-40e7-8ebc-b8cf71848c2f',
        'product_id' => '390',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/395_09-ab8c088acd71.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/846776779959/09-ab8c088acd71.jpg',
        'expect_id' => '710f8973-aa2e-4797-853c-d1bcf48a2086',
        'product_id' => '395',
        'before' => '608x664',
    ],
    [
        'src' => $out . '/403_07-0e4131fa1e38.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857385272130/07-0e4131fa1e38.jpg',
        'expect_id' => 'd48e3aee-675e-47cf-80a5-8e7ee71b1c08',
        'product_id' => '403',
        'before' => '762x612',
    ],
    [
        'src' => $out . '/405_04-00c04449b4d4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896146359561/04-00c04449b4d4.jpg',
        'expect_id' => '9d72f9b1-1d6d-4db0-aacb-07f5178d3ca4',
        'product_id' => '405',
        'before' => '1379x1346',
    ],
    [
        'src' => $out . '/414_14-709aee353af6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/1028223378473/14-709aee353af6.jpg',
        'expect_id' => '005a7e7c-25db-4dc8-ab9b-18e4febf5547',
        'product_id' => '414',
        'before' => '1483x1250',
    ],
    [
        'src' => $out . '/427_07-1aadbca4358f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/704908236604/07-1aadbca4358f.jpg',
        'expect_id' => '82f11bb0-5766-40cc-ab2b-2478cacb5d80',
        'product_id' => '427',
        'before' => '629x645',
    ],
    [
        'src' => $out . '/431_06-d533a2113eed.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705533300032/06-d533a2113eed.jpg',
        'expect_id' => '668edb1d-ecf8-4850-ad93-83cbd6951cc4',
        'product_id' => '431',
        'before' => '705x731',
    ],
    [
        'src' => $out . '/432_08-0b108dbf59cf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/670310368253/08-0b108dbf59cf.jpg',
        'expect_id' => 'd4e210b8-a6b1-49a1-a39a-5dc0339eac57',
        'product_id' => '432',
        'before' => '778x800',
    ],
    [
        'src' => $out . '/436_03-1b5363fe5312.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706013682914/03-1b5363fe5312.jpg',
        'expect_id' => '652f4eb9-4e6d-428c-ac71-507c8fb83c83',
        'product_id' => '436',
        'before' => '915x893',
    ],
    [
        'src' => $out . '/455_01-a6d5a9960201.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/793852679404/01-a6d5a9960201.jpg',
        'expect_id' => '59d3dc16-e1f7-4205-919d-f7ca53d50871',
        'product_id' => '455',
        'before' => '798x785',
    ],
    [
        'src' => $out . '/457_06-59c6e249043a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/713616804778/06-59c6e249043a.jpg',
        'expect_id' => '2cc00fb8-ce0d-4a2f-80d5-bfd787773135',
        'product_id' => '457',
        'before' => '925x1200',
    ],
    [
        'src' => $out . '/460_06-837b90e8018a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1008408346289/06-837b90e8018a.jpg',
        'expect_id' => '51c65dc8-7b95-40fb-a65f-9d39e6491ec9',
        'product_id' => '460',
        'before' => '608x730',
    ],
    [
        'src' => $out . '/472_05-0cf53e2459d9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1058646448616/05-0cf53e2459d9.jpg',
        'expect_id' => '4b730656-d94d-4c54-9d6a-1213df1da9ee',
        'product_id' => '472',
        'before' => '1600x1561',
    ],
    [
        'src' => $out . '/507_07-344ab0afe669.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/746099485547/07-344ab0afe669.jpg',
        'expect_id' => 'd01f6f97-7bdd-4e36-91bb-e6c6182f7bb0',
        'product_id' => '507',
        'before' => '1365x1366',
    ],
    [
        'src' => $out . '/521_08-92df23f84f4d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/818786567633/08-92df23f84f4d.jpg',
        'expect_id' => 'fad2e006-b0be-4aff-8a96-53bfbdea22d1',
        'product_id' => '521',
        'before' => '800x794',
    ],
    [
        'src' => $out . '/522_11-0f063c767b1a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819124077369/11-0f063c767b1a.jpg',
        'expect_id' => 'e6275851-c9c4-4f95-9e1d-46c20d9efd01',
        'product_id' => '522',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/523_08-780876447169.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819243236248/08-780876447169.jpg',
        'expect_id' => 'b4b9b789-cab0-4c22-9f57-0c4c075d5330',
        'product_id' => '523',
        'before' => '849x900',
    ],
    [
        'src' => $out . '/528_05-eb72117ce60b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/853856286725/05-eb72117ce60b.jpg',
        'expect_id' => '3997374e-ea23-4971-a504-573bf3d38180',
        'product_id' => '528',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/540_06-4493345953c8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1069729626349/06-4493345953c8.jpg',
        'expect_id' => 'e2134021-cbb2-43ed-a27e-cf7927b613f6',
        'product_id' => '540',
        'before' => '1200x1073',
    ],
    [
        'src' => $out . '/331_08-de1437d874c4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703149373866/08-de1437d874c4.jpg',
        'expect_id' => '7cda66e3-fd29-44e7-88b2-185d72dc8fd4',
        'product_id' => '331',
        'before' => '602x681',
    ],
    [
        'src' => $out . '/352_10-e2b985d4294e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704247394800/10-e2b985d4294e.jpg',
        'expect_id' => '4fbbe5f1-477a-414a-8faf-28d2476df329',
        'product_id' => '352',
        'before' => '1200x1061',
    ],
    [
        'src' => $out . '/360_08-5fb6ef8dd338.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715071130913/08-5fb6ef8dd338.jpg',
        'expect_id' => '6afb6921-6cd3-4021-8b3b-f060dda720fd',
        'product_id' => '360',
        'before' => '1211x1206',
    ],
    [
        'src' => $out . '/373_11-441f5ec9aa31.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/725522200081/11-441f5ec9aa31.jpg',
        'expect_id' => '7c5e56b0-7405-4274-8589-07439b9e4b74',
        'product_id' => '373',
        'before' => '1200x1184',
    ],
    [
        'src' => $out . '/379_06-5fd31dbcefe3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746304492874/06-5fd31dbcefe3.jpg',
        'expect_id' => 'd0f52f84-da68-4701-bd89-5ec1dc439076',
        'product_id' => '379',
        'before' => '796x800',
    ],
    [
        'src' => $out . '/383_08-04b9e0819daa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752469198811/08-04b9e0819daa.jpg',
        'expect_id' => '9c28db02-a1cb-4501-806a-62b0d0e62ef6',
        'product_id' => '383',
        'before' => '679x710',
    ],
    [
        'src' => $out . '/384_07-04b9e0819daa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752544903797/07-04b9e0819daa.jpg',
        'expect_id' => '21025191-30d6-4c2a-ab09-77d3128638b9',
        'product_id' => '384',
        'before' => '679x710',
    ],
    [
        'src' => $out . '/423_05-760f9ab433c3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-jianzhou/621388692111/05-760f9ab433c3.jpg',
        'expect_id' => '35375490-66d7-48b8-ac2b-9430e522d11f',
        'product_id' => '423',
        'before' => '608x750',
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
