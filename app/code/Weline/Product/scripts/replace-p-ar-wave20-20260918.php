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
$out = '/tmp/p-ar-wave20-20260918/out';
$done = '/tmp/p-ar-wave20-20260918/done.tsv';

// wave20: 50 remaining non-square gallery/variant assets → 1:1 outpaint
$jobs = [
    [
        'src' => $out . '/477_06-2801bc80ec0f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/06-2801bc80ec0f.jpg',
        'expect_id' => 'd7568b83-102d-4274-8fdc-c9617e527ab0',
        'product_id' => '477',
        'before' => '750x608',
    ],
    [
        'src' => $out . '/378_08-f9cac49f617d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/08-f9cac49f617d.jpg',
        'expect_id' => 'e14c03c8-4f94-4a6a-8ba3-faa995b0c1c4',
        'product_id' => '378',
        'before' => '664x739',
    ],
    [
        'src' => $out . '/508_16-9d1f26153d74.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/16-9d1f26153d74.jpg',
        'expect_id' => 'f1ba914c-d420-4dbc-a0ab-ec02a31ca26e',
        'product_id' => '508',
        'before' => '488x470',
    ],
    [
        'src' => $out . '/500_06-4bc38bbf9b2b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/06-4bc38bbf9b2b.jpg',
        'expect_id' => '32dbd1a3-756f-49f7-a959-59d28bda2e70',
        'product_id' => '500',
        'before' => '697x1036',
    ],
    [
        'src' => $out . '/511_04-90512d211d9a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/771775599997/04-90512d211d9a.jpg',
        'expect_id' => 'ab68d195-9537-4625-90df-24f500a1619c',
        'product_id' => '511',
        'before' => '1496x1486',
    ],
    [
        'src' => $out . '/400_10-9df9c2dd29f3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/10-9df9c2dd29f3.jpg',
        'expect_id' => 'f8b39e02-24d6-48ac-a96d-f2a9a8bbee35',
        'product_id' => '400',
        'before' => '534x425',
    ],
    [
        'src' => $out . '/411_08-f9cac49f617d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/08-f9cac49f617d.jpg',
        'expect_id' => '95feaf68-f4b5-45f1-ab04-400af5cb00f5',
        'product_id' => '411',
        'before' => '664x739',
    ],
    [
        'src' => $out . '/504_08-61f2e5308664.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/08-61f2e5308664.jpg',
        'expect_id' => '206886fa-4cc9-48c0-9b07-2f7e5d56472c',
        'product_id' => '504',
        'before' => '800x787',
    ],
    [
        'src' => $out . '/189_06-hd-e9926210e31b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/06-hd-e9926210e31b.jpg',
        'expect_id' => 'c82deb85-e9be-4c69-8058-9e046dc95b92',
        'product_id' => '189',
        'before' => '1448x1861',
    ],
    [
        'src' => $out . '/466_06-854054a4a8ba.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/06-854054a4a8ba.jpg',
        'expect_id' => '20aa523a-4839-45bc-b02b-93b05489bf59',
        'product_id' => '466',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/382_13-4c37adf65fd1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/749119924927/13-4c37adf65fd1.jpg',
        'expect_id' => 'b7ca4d3b-5372-4fb6-8ecc-de35ab2a8d6b',
        'product_id' => '382',
        'before' => '534x478',
    ],
    [
        'src' => $out . '/442_06-045423fb77b1.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/06-045423fb77b1.jpg',
        'expect_id' => '5db49d09-cc6f-4c97-95b9-98ecd3f78226',
        'product_id' => '442',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/229_04-bb457a068315.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/933709641123/04-bb457a068315.jpg',
        'expect_id' => 'eb58438a-0077-47ca-b35e-ab5949a5e56e',
        'product_id' => '229',
        'before' => '1743x1920',
    ],
    [
        'src' => $out . '/241_06-f4bde1a190a5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/996206615159/06-f4bde1a190a5.jpg',
        'expect_id' => '79c9c83a-5cac-488b-bc83-508d930df613',
        'product_id' => '241',
        'before' => '800x794',
    ],
    [
        'src' => $out . '/499_10-e480363513c6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/10-e480363513c6.jpg',
        'expect_id' => '4510e0b2-ff5c-48cb-9b42-b72d6e7f3ff3',
        'product_id' => '499',
        'before' => '800x803',
    ],
    [
        'src' => $out . '/537_07-8788117bd96a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/07-8788117bd96a.jpg',
        'expect_id' => '16639fbb-221c-436f-a543-50291897e54e',
        'product_id' => '537',
        'before' => '752x800',
    ],
    [
        'src' => $out . '/380_15-f2aed2a8f4a3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/746374029121/15-f2aed2a8f4a3.jpg',
        'expect_id' => '520c9832-b83b-44c7-8974-e4021b63e670',
        'product_id' => '380',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/390_07-bcdd235eaf52.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770444426366/07-bcdd235eaf52.jpg',
        'expect_id' => '90b294c4-8ad0-4078-b5b3-e3b02895f1e8',
        'product_id' => '390',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/403_06-276ddced9fbd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857385272130/06-276ddced9fbd.jpg',
        'expect_id' => 'a7eca4f6-8dee-4130-b85b-27048a2ca1d3',
        'product_id' => '403',
        'before' => '800x784',
    ],
    [
        'src' => $out . '/406_04-b816e68f31da.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896845945793/04-b816e68f31da.jpg',
        'expect_id' => '7e0bbeb2-31d0-473e-9896-94c4273cb36f',
        'product_id' => '406',
        'before' => '800x790',
    ],
    [
        'src' => $out . '/170_04-589fb3c2b7e5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/823792675009/04-589fb3c2b7e5.jpg',
        'expect_id' => 'f71163e1-1902-4812-bda0-2e33c9a8fa00',
        'product_id' => '170',
        'before' => '905x1200',
    ],
    [
        'src' => $out . '/197_05-110278bfc16e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/833553496409/05-110278bfc16e.jpg',
        'expect_id' => 'df9e1e83-4b92-4fc3-a4ef-a67b61b07154',
        'product_id' => '197',
        'before' => '1000x1333',
    ],
    [
        'src' => $out . '/464_07-e07768b44b2b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1022640495788/07-e07768b44b2b.jpg',
        'expect_id' => '3e12f26d-67a9-4dda-ab59-efddb4abf143',
        'product_id' => '464',
        'before' => '800x791',
    ],
    [
        'src' => $out . '/489_07-4454cc3cb40b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705643992653/07-4454cc3cb40b.jpg',
        'expect_id' => 'b3e827fa-fc21-48c9-ab17-36a4b48aa998',
        'product_id' => '489',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/218_01-b77cda7c9d46.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/912814485918/01-b77cda7c9d46.jpg',
        'expect_id' => '76e001f4-d288-42f1-92d0-38b015485378',
        'product_id' => '218',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/244_07-4385cf3beb00.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/997213594839/07-4385cf3beb00.jpg',
        'expect_id' => '8ba59d73-7e07-43b5-99e0-6cbed85c22e5',
        'product_id' => '244',
        'before' => '800x793',
    ],
    [
        'src' => $out . '/342_10-3ca8af30ed28.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703375786953/10-3ca8af30ed28.jpg',
        'expect_id' => '8b424b9a-3745-430c-bcbf-534248d41dd6',
        'product_id' => '342',
        'before' => '788x704',
    ],
    [
        'src' => $out . '/368_10-2efbadc42f3e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/737369802795/10-2efbadc42f3e.jpg',
        'expect_id' => '29505b35-0f91-47ce-9408-89fdae65eaf9',
        'product_id' => '368',
        'before' => '400x361',
    ],
    [
        'src' => $out . '/370_05-0fac82d9276c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715276723549/05-0fac82d9276c.jpg',
        'expect_id' => 'aab8330a-5be4-487f-ac97-98252e2d841f',
        'product_id' => '370',
        'before' => '792x800',
    ],
    [
        'src' => $out . '/374_05-d001245cf8bd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738673544725/05-d001245cf8bd.jpg',
        'expect_id' => '4f3680fb-2f7e-4431-bd80-7fd52ef96ae0',
        'product_id' => '374',
        'before' => '570x738',
    ],
    [
        'src' => $out . '/389_07-a97b14482170.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770377252676/07-a97b14482170.jpg',
        'expect_id' => '74e31c28-d175-4109-b743-7ac4001742aa',
        'product_id' => '389',
        'before' => '1280x1428',
    ],
    [
        'src' => $out . '/396_13-d09f6250b091.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855202285799/13-d09f6250b091.png',
        'expect_id' => 'afe22ec7-37f7-4a73-82b2-10b143120362',
        'product_id' => '396',
        'before' => '601x529',
    ],
    [
        'src' => $out . '/398_11-659f3e1ff0aa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855439796678/11-659f3e1ff0aa.jpg',
        'expect_id' => '6982efeb-e7ac-4d88-8940-c7ec1c4864f8',
        'product_id' => '398',
        'before' => '802x744',
    ],
    [
        'src' => $out . '/405_03-dddc88253d9e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896146359561/03-dddc88253d9e.jpg',
        'expect_id' => 'e9d7a917-5c71-464c-b615-9f9c654804c4',
        'product_id' => '405',
        'before' => '1192x1185',
    ],
    [
        'src' => $out . '/427_05-fdf26f06586d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/704908236604/05-fdf26f06586d.jpg',
        'expect_id' => '0053b422-9104-422e-aaaf-436264804efb',
        'product_id' => '427',
        'before' => '621x639',
    ],
    [
        'src' => $out . '/445_09-5aa9dc4b3f56.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705647723560/09-5aa9dc4b3f56.jpg',
        'expect_id' => '867bbf5a-6811-4135-a002-cb4c554393ea',
        'product_id' => '445',
        'before' => '608x798',
    ],
    [
        'src' => $out . '/457_05-f2835c81ec4c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/713616804778/05-f2835c81ec4c.jpg',
        'expect_id' => '22049b7b-efd3-40be-b4d5-42a548a2218e',
        'product_id' => '457',
        'before' => '974x1280',
    ],
    [
        'src' => $out . '/483_07-881918e6dc62.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/639003693261/07-881918e6dc62.jpg',
        'expect_id' => '0cb22db7-56df-4eff-ba84-ad876d1c76f4',
        'product_id' => '483',
        'before' => '371x400',
    ],
    [
        'src' => $out . '/497_11-2242f5cf42cd.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/715367119667/11-2242f5cf42cd.jpg',
        'expect_id' => 'adf57a1e-b516-4e08-9198-2afb0a17cb7e',
        'product_id' => '497',
        'before' => '779x559',
    ],
    [
        'src' => $out . '/523_05-a3504bcbdf74.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819243236248/05-a3504bcbdf74.jpg',
        'expect_id' => 'be3559b0-b172-4fc8-aabb-ec0963ec9814',
        'product_id' => '523',
        'before' => '832x1064',
    ],
    [
        'src' => $out . '/525_07-f99e319a83ee.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/834116329033/07-f99e319a83ee.jpg',
        'expect_id' => '10b4234c-8bbe-4cbd-850d-16427ff2d7ac',
        'product_id' => '525',
        'before' => '917x912',
    ],
    [
        'src' => $out . '/528_03-c851fd2c58e6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/853856286725/03-c851fd2c58e6.jpg',
        'expect_id' => '0f1384d1-d9f2-45d8-889d-43c14bee389d',
        'product_id' => '528',
        'before' => '1166x1555',
    ],
    [
        'src' => $out . '/531_10-3f47eec96887.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/861289256056/10-3f47eec96887.jpg',
        'expect_id' => '30c40c07-75e6-44d0-9b8a-19b35db5a5bb',
        'product_id' => '531',
        'before' => '800x763',
    ],
    [
        'src' => $out . '/344_06-2e4721727526.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703612667993/06-2e4721727526.jpg',
        'expect_id' => '17f06f2b-1e69-4fa2-b254-22134533c4f1',
        'product_id' => '344',
        'before' => '800x795',
    ],
    [
        'src' => $out . '/350_08-b0b2f44e85b7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704019401867/08-b0b2f44e85b7.jpg',
        'expect_id' => 'f93c3bb9-db68-47b0-924b-6eda1b0d4462',
        'product_id' => '350',
        'before' => '912x1114',
    ],
    [
        'src' => $out . '/358_06-01d46912357e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706523563773/06-01d46912357e.jpg',
        'expect_id' => '882c160a-f8d0-4261-84c9-2f6eacddac59',
        'product_id' => '358',
        'before' => '704x800',
    ],
    [
        'src' => $out . '/362_08-70339cf76e2f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715078490005/08-70339cf76e2f.jpg',
        'expect_id' => '27d956b1-bc9d-4689-b5ec-a4a1ccdc34fa',
        'product_id' => '362',
        'before' => '571x665',
    ],
    [
        'src' => $out . '/363_09-590c36698d96.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706299882617/09-590c36698d96.jpg',
        'expect_id' => '0e26a6db-ba47-41e5-a7e9-ac2cce1f6b6d',
        'product_id' => '363',
        'before' => '750x701',
    ],
    [
        'src' => $out . '/364_08-f489d9147b84.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706301642313/08-f489d9147b84.jpg',
        'expect_id' => 'dee23099-12b6-4c31-8140-6a4a3b7f5ead',
        'product_id' => '364',
        'before' => '804x601',
    ],
    [
        'src' => $out . '/369_09-19371613b35a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/737371662599/09-19371613b35a.jpg',
        'expect_id' => 'd539a03d-39a4-4a4f-b537-e0a7365bbcd3',
        'product_id' => '369',
        'before' => '1320x1120',
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
