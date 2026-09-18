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
$out = '/tmp/p-ar-wave22-20260918/out';
$done = '/tmp/p-ar-wave22-20260918/done.tsv';
$mediaRoot = dirname(__DIR__, 5) . '/pub/media/';

// wave22: 50 remaining non-square gallery/variant/main assets → 1:1 outpaint
$jobs = [
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/477_08-13743c1d9b27.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/08-13743c1d9b27.jpg',
        'expect_id' => '037b4d63-1587-4b1d-bb37-1b8878239201',
        'product_id' => '477',
        'before' => '769x608',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/378_10-25358b261b98.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/10-25358b261b98.jpg',
        'expect_id' => '4131221a-1b52-454e-bedc-54bb9ffe6484',
        'product_id' => '378',
        'before' => '621x708',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/508_18-b6d8dd8779a7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/18-b6d8dd8779a7.jpg',
        'expect_id' => 'e1fdf705-92b3-4f0b-9743-d19d3d6ff076',
        'product_id' => '508',
        'before' => '518x606',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/411_10-25358b261b98.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/10-25358b261b98.jpg',
        'expect_id' => '14989607-b641-4d41-9bd3-b42179a667c4',
        'product_id' => '411',
        'before' => '621x708',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/511_09-d6aca9c0d083.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771775599997/09-d6aca9c0d083.jpg',
        'expect_id' => 'bfe46215-74a0-4bb5-bf45-bcd6c6a0c0fb',
        'product_id' => '511',
        'before' => '1486x1500',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/500_08-97fa412f60fb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/08-97fa412f60fb.jpg',
        'expect_id' => 'fb710c31-8aee-4a38-8a69-8cc3f780c95d',
        'product_id' => '500',
        'before' => '697x1036',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/504_10-655ccdf5edea.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/10-655ccdf5edea.jpg',
        'expect_id' => '737174dc-f21a-4598-8277-cfe50eef75cd',
        'product_id' => '504',
        'before' => '997x970',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/400_12-3eb5e447a024.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/12-3eb5e447a024.jpg',
        'expect_id' => 'fb738045-3baa-4d0e-811c-d88f37d3d876',
        'product_id' => '400',
        'before' => '534x423',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/189_08-hd-9e30f8237d06.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/08-hd-9e30f8237d06.jpg',
        'expect_id' => '985dc958-46c9-4036-b708-a6f71f2b2e13',
        'product_id' => '189',
        'before' => '1201x1544',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/466_09-62087b48acf6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/09-62087b48acf6.jpg',
        'expect_id' => 'dec64791-582d-4ec9-bdfd-519bc0946748',
        'product_id' => '466',
        'before' => '1000x1333',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/229_01-5ec06772d455.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/933709641123/01-5ec06772d455.jpg',
        'expect_id' => '4ace7139-1979-4883-aaa4-2f0df019e846',
        'product_id' => '229',
        'before' => '1669x1920',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/442_08-404319a49533.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/08-404319a49533.jpg',
        'expect_id' => 'ce09fa1f-7ddc-4910-a4c9-5506648df4be',
        'product_id' => '442',
        'before' => '808x1000',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/375_07-860f1500b019.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738962342070/07-860f1500b019.jpg',
        'expect_id' => 'cbc22725-cc67-4fed-a911-07ea3a88227c',
        'product_id' => '375',
        'before' => '741x608',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/405_07-781874c2d94e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896146359561/07-781874c2d94e.jpg',
        'expect_id' => '8660968d-a658-41ea-93a2-a1edc2364185',
        'product_id' => '405',
        'before' => '733x653',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/436_01-a9442b686202.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706013682914/01-a9442b686202.jpg',
        'expect_id' => '89453bf8-5c67-427b-be1f-cfd7794a662d',
        'product_id' => '436',
        'before' => '960x845',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/492_12-34ecc2434b4b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/712515408993/12-34ecc2434b4b.jpg',
        'expect_id' => '62947d8d-80c3-4e03-a6b7-4eddd860a3f2',
        'product_id' => '492',
        'before' => '1919x1861',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/528_10-686338f500c7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/853856286725/10-686338f500c7.jpg',
        'expect_id' => 'bc1cb36f-8d13-4178-b425-08866f430bdc',
        'product_id' => '528',
        'before' => '332x370',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/193_04-482656143952.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/831937917272/04-482656143952.jpg',
        'expect_id' => '97a790be-d8fe-4cd9-87e4-e7e3edf10df4',
        'product_id' => '193',
        'before' => '1000x1333',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/204_03-8d1f471c232a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/863290574599/03-8d1f471c232a.jpg',
        'expect_id' => 'a98e30e5-9dbe-4f7f-985e-986fa2612483',
        'product_id' => '204',
        'before' => '1200x1168',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/260_03-7ba3774e232e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1042443716530/03-7ba3774e232e.jpg',
        'expect_id' => '9962d55a-6593-4a87-b5d0-7284d74941db',
        'product_id' => '260',
        'before' => '1131x1190',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/291_05-4b9716e960c6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1070868426782/05-4b9716e960c6.jpg',
        'expect_id' => 'fa8b8b43-9628-4fb0-bef0-1259e7cf1d4c',
        'product_id' => '291',
        'before' => '1200x1079',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/347_03-ead1435bcecd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703732796408/03-ead1435bcecd.jpg',
        'expect_id' => '6429ccc2-32bf-43b8-a827-c7f46cb77827',
        'product_id' => '347',
        'before' => '786x793',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/406_07-e9a8ed2df1bb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896845945793/07-e9a8ed2df1bb.jpg',
        'expect_id' => 'f307d21c-e79a-422d-86f4-4d1b405cdd3e',
        'product_id' => '406',
        'before' => '800x789',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/441_05-6829607ad9b2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708115286585/05-6829607ad9b2.jpg',
        'expect_id' => '9453d8d9-bf4e-42fe-88e3-f903ddc9a1a9',
        'product_id' => '441',
        'before' => '1000x1333',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/518_05-f993ffe7ab49.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/814270917745/05-f993ffe7ab49.jpg',
        'expect_id' => '387fd952-9910-412a-9f2d-ad746ca78f73',
        'product_id' => '518',
        'before' => '1000x918',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/170_01-d7818c832664.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/823792675009/01-d7818c832664.jpg',
        'expect_id' => '56683de2-5bf5-46fe-9f7f-f1c50859ee27',
        'product_id' => '170',
        'before' => '904x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/181_05-6ea3a8cfae5d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/826520269606/05-6ea3a8cfae5d.jpg',
        'expect_id' => '4ad6cbe8-3f78-4be9-a1dc-851d6632ffa0',
        'product_id' => '181',
        'before' => '1200x1162',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/186_05-b0dbb830e5c7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829407318846/05-b0dbb830e5c7.jpg',
        'expect_id' => 'c1c777f1-9403-4ab2-97e6-01b325c01b4a',
        'product_id' => '186',
        'before' => '1189x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/197_01-7f821cad1907.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/833553496409/01-7f821cad1907.jpg',
        'expect_id' => '58695ae1-4882-46d7-a24f-456743678292',
        'product_id' => '197',
        'before' => '1000x1333',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/202_03-4f94ea5ab89a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/846809244944/03-4f94ea5ab89a.jpg',
        'expect_id' => '088f620a-ebc1-4f19-9e6a-e04e4615bdf2',
        'product_id' => '202',
        'before' => '1200x1070',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/211_01-202e308d3d7a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/899003672291/01-202e308d3d7a.jpg',
        'expect_id' => 'fad25c6c-d593-4ed3-ad83-811c2f28d13e',
        'product_id' => '211',
        'before' => '1200x1197',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/227_01-8bf90976f1c8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/925302638410/01-8bf90976f1c8.jpg',
        'expect_id' => 'a98ff39e-8505-430c-97f5-b344b1c60a68',
        'product_id' => '227',
        'before' => '1056x1187',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/241_01-0715537a96d6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/996206615159/01-0715537a96d6.jpg',
        'expect_id' => 'b8d767e1-bfab-4282-a401-632559b052d2',
        'product_id' => '241',
        'before' => '1200x1190',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/250_01-7787c8126ed7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1003899862373/01-7787c8126ed7.jpg',
        'expect_id' => '118ed463-a71f-458c-8bec-f8c53ed39368',
        'product_id' => '250',
        'before' => '1000x1333',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/252_01-99d7a8e2f145.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1012436385028/01-99d7a8e2f145.jpg',
        'expect_id' => 'efb1c53b-ed49-431b-879f-0c39de6cf049',
        'product_id' => '252',
        'before' => '1000x1333',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/256_04-aa7e0038e25e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1032584184940/04-aa7e0038e25e.jpg',
        'expect_id' => 'fa9807a4-e3f6-4d53-9cb2-2c750528c71f',
        'product_id' => '256',
        'before' => '1200x1187',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/272_04-d9619da8252e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1051635342005/04-d9619da8252e.jpg',
        'expect_id' => 'd03bdbed-a382-4796-aa0f-015b40c2a218',
        'product_id' => '272',
        'before' => '1200x1056',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/304_04-87d45cc7dc30.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1041362147250/04-87d45cc7dc30.jpg',
        'expect_id' => '2c701f29-3ea2-4a00-bbd8-35cf7d2bd004',
        'product_id' => '304',
        'before' => '1500x1478',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/323_05-4228d1bf8882.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1050246114965/05-4228d1bf8882.jpg',
        'expect_id' => 'ec29ca75-875d-488e-8707-972c067a3b21',
        'product_id' => '323',
        'before' => '1200x1183',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/335_02-f898675d790b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702861516531/02-f898675d790b.jpg',
        'expect_id' => '64c69d4e-1cf1-429f-8ed1-85676fc62436',
        'product_id' => '335',
        'before' => '790x800',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/336_05-32ef14ca278d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702862148451/05-32ef14ca278d.jpg',
        'expect_id' => 'e5b8200f-f755-4023-a775-8722534b90fd',
        'product_id' => '336',
        'before' => '785x790',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/337_03-29077a1571c3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703150917659/03-29077a1571c3.jpg',
        'expect_id' => 'b594f075-9692-4fbd-bb61-3d1e8f4e72cd',
        'product_id' => '337',
        'before' => '781x800',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/354_05-28cbd2051b4d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706077621955/05-28cbd2051b4d.jpg',
        'expect_id' => 'ece3c846-1df1-4add-acd0-3f4656508018',
        'product_id' => '354',
        'before' => '774x776',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/374_01-2f0b620194b9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738673544725/01-2f0b620194b9.jpg',
        'expect_id' => 'f6b5736b-2289-4a35-beab-e1ea9a524999',
        'product_id' => '374',
        'before' => '750x734',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/376_01-cab10695c8ef.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/739090011198/01-cab10695c8ef.jpg',
        'expect_id' => '042287be-ec3d-4fd7-aab2-1457fee2d9c3',
        'product_id' => '376',
        'before' => '800x782',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/402_05-65c66f8e70da.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857135725475/05-65c66f8e70da.jpg',
        'expect_id' => 'd4b98879-c305-4e8a-bc7b-a412839d1be2',
        'product_id' => '402',
        'before' => '812x1038',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/435_05-79b137bbc26a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705813333122/05-79b137bbc26a.jpg',
        'expect_id' => 'fe9323f7-4d03-4b23-8d83-0410a9b0eb58',
        'product_id' => '435',
        'before' => '797x786',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/438_05-218518a25db8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706603439030/05-218518a25db8.jpg',
        'expect_id' => '3b8fb373-68a3-4901-80c6-52ab0e396572',
        'product_id' => '438',
        'before' => '1140x1500',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/440_04-d10fe8a82f70.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708035977500/04-d10fe8a82f70.jpg',
        'expect_id' => '1ccfb9cd-4d55-4d3f-8aeb-ab11b8a8aa55',
        'product_id' => '440',
        'before' => '770x763',
    ],
    [
        'src' => '/tmp/p-ar-wave22-20260918/out/443_05-e74b0d6ef8e2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708123722007/05-e74b0d6ef8e2.jpg',
        'expect_id' => '3f5c8ef7-a628-4a87-9e05-ad6b19755899',
        'product_id' => '443',
        'before' => '800x795',
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
