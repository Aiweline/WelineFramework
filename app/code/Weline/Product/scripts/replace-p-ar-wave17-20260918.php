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
$out = '/tmp/p-ar-wave17-20260918/out';
$done = '/tmp/p-ar-wave17-20260918/done.tsv';

// 50 jobs; #383/#384 share generated pixels (same content hash) but distinct object_keys
$jobs = [
    [
        'src' => $out . '/477_05-a7d2b3e71fc6.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/05-a7d2b3e71fc6.jpg',
        'expect_id' => '85a10891-80d7-4fcd-a2d8-a6729035908b',
        'product_id' => '477',
        'before' => '880x1000',
    ],
    [
        'src' => $out . '/378_05-f22fc6eef766.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/743501593313/05-f22fc6eef766.jpg',
        'expect_id' => 'f4bd5c38-62c8-4bfd-b1ad-a3e2be21a528',
        'product_id' => '378',
        'before' => '748x703',
    ],
    [
        'src' => $out . '/411_04-114aadcdc68b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738653880900/04-114aadcdc68b.jpg',
        'expect_id' => '4817c04f-6620-4d8c-9bc4-e51f9e73892b',
        'product_id' => '411',
        'before' => '660x750',
    ],
    [
        'src' => $out . '/500_03-640be5a07be2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/722302509786/03-640be5a07be2.jpg',
        'expect_id' => 'a5e45d4d-7425-4e8f-9af2-77302b74e8b0',
        'product_id' => '500',
        'before' => '584x768',
    ],
    [
        'src' => $out . '/189_03-hd-89b1a03b9894.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/829804612849/03-hd-89b1a03b9894.jpg',
        'expect_id' => 'cf50630e-8116-4805-986f-6ad61f52b4b0',
        'product_id' => '189',
        'before' => '1500x1953',
    ],
    [
        'src' => $out . '/442_03-20cb9f24c128.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708120514164/03-20cb9f24c128.jpg',
        'expect_id' => '1135784a-37b7-4b1f-b1fc-6f7237cc6464',
        'product_id' => '442',
        'before' => '808x1000',
    ],
    [
        'src' => $out . '/466_03-ae52f951921e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1024212757490/03-ae52f951921e.jpg',
        'expect_id' => 'ed3e9366-d0be-4cca-98a5-f5b71af5ae46',
        'product_id' => '466',
        'before' => '948x1058',
    ],
    [
        'src' => $out . '/382_08-856674bb3e63.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/749119924927/08-856674bb3e63.jpg',
        'expect_id' => '079672a9-acf9-48df-b6b7-87b0b815f055',
        'product_id' => '382',
        'before' => '534x464',
    ],
    [
        'src' => $out . '/390_03-4eaefe4abdaf.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/770444426366/03-4eaefe4abdaf.jpg',
        'expect_id' => 'd9c19826-14f9-4ece-8f2f-8581279c2397',
        'product_id' => '390',
        'before' => '810x1064',
    ],
    [
        'src' => $out . '/400_09-45d7d5cd12d5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/856821726140/09-45d7d5cd12d5.jpg',
        'expect_id' => '05fb6eaf-c27a-4cbf-8af8-d929c82a3b6a',
        'product_id' => '400',
        'before' => '503x456',
    ],
    [
        'src' => $out . '/489_03-782f1201a640.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/705643992653/03-782f1201a640.jpg',
        'expect_id' => '71e75a63-e5ea-4a7b-9f73-568863044785',
        'product_id' => '489',
        'before' => '1217x1500',
    ],
    [
        'src' => $out . '/508_15-69d11a19d781.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/15-69d11a19d781.jpg',
        'expect_id' => '5e0b6942-9b46-4ae0-b4a6-5fb304085263',
        'product_id' => '508',
        'before' => '315x454',
    ],
    [
        'src' => $out . '/537_03-92c5aa230be3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/863769428598/03-92c5aa230be3.jpg',
        'expect_id' => '9b11e885-1f24-4f2d-9527-13407da924d1',
        'product_id' => '537',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/396_10-a0680b29474c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855202285799/10-a0680b29474c.png',
        'expect_id' => '1938c927-95c7-443a-929b-8200205cec2f',
        'product_id' => '396',
        'before' => '731x639',
    ],
    [
        'src' => $out . '/457_03-fbd4b85b914f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/713616804778/03-fbd4b85b914f.jpg',
        'expect_id' => 'fd389788-7f8c-419e-89cb-8a35457d71f1',
        'product_id' => '457',
        'before' => '974x1280',
    ],
    [
        'src' => $out . '/526_03-bb1facb1427c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/836115959517/03-bb1facb1427c.jpg',
        'expect_id' => '9f936eee-ea1f-40df-82a4-36ab3f5d315a',
        'product_id' => '526',
        'before' => '812x1066',
    ],
    [
        'src' => $out . '/237_04-481e3e70efd4.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/981174366560/04-481e3e70efd4.jpg',
        'expect_id' => '0e661e12-b92c-493d-a94e-cc0a47fe2e78',
        'product_id' => '237',
        'before' => '1460x1920',
    ],
    [
        'src' => $out . '/250_04-df7ce3233732.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1003899862373/04-df7ce3233732.jpg',
        'expect_id' => 'cb9645a6-3da8-4776-a4ba-7dd9cbd31fbe',
        'product_id' => '250',
        'before' => '1920x1730',
    ],
    [
        'src' => $out . '/252_05-854054a4a8ba.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1012436385028/05-854054a4a8ba.jpg',
        'expect_id' => 'c5ed5a2c-5f7f-47b4-a88c-d2f9196a9cb1',
        'product_id' => '252',
        'before' => '1920x1690',
    ],
    [
        'src' => $out . '/291_04-56aff205a2a9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1070868426782/04-56aff205a2a9.jpg',
        'expect_id' => '3d69aa37-0642-4100-8924-936386f70276',
        'product_id' => '291',
        'before' => '1200x1056',
    ],
    [
        'src' => $out . '/331_06-5eee02842ab2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703149373866/06-5eee02842ab2.jpg',
        'expect_id' => '3f23b518-a492-4417-9033-293736c47a1c',
        'product_id' => '331',
        'before' => '741x608',
    ],
    [
        'src' => $out . '/374_04-2a978f3ecf57.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738673544725/04-2a978f3ecf57.jpg',
        'expect_id' => '1375ff10-f6fa-4a42-9062-3d24fb5f2969',
        'product_id' => '374',
        'before' => '660x750',
    ],
    [
        'src' => $out . '/398_09-da4affef18aa.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/855439796678/09-da4affef18aa.jpg',
        'expect_id' => 'd8f38d87-8ea5-40a9-87eb-f3d5bdd9d5c3',
        'product_id' => '398',
        'before' => '852x803',
    ],
    [
        'src' => $out . '/441_04-a03fdeb37df7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708115286585/04-a03fdeb37df7.jpg',
        'expect_id' => '7c5011af-6b9d-4aa6-ba27-9dbf20286e56',
        'product_id' => '441',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/445_07-0a43445711f9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705647723560/07-0a43445711f9.jpg',
        'expect_id' => '8d67e479-ee19-4b9c-b1e8-084990bb5921',
        'product_id' => '445',
        'before' => '608x798',
    ],
    [
        'src' => $out . '/499_08-ed612a864637.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/08-ed612a864637.jpg',
        'expect_id' => 'f34e367a-f9d6-4836-bccb-87aa9a2f051d',
        'product_id' => '499',
        'before' => '800x864',
    ],
    [
        'src' => $out . '/523_03-c7f015aba385.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/819243236248/03-c7f015aba385.jpg',
        'expect_id' => '3b34820b-8e91-42be-8b99-f84c1813cb7b',
        'product_id' => '523',
        'before' => '1064x1001',
    ],
    [
        'src' => $out . '/531_04-0f63b30a1ad8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/861289256056/04-0f63b30a1ad8.jpg',
        'expect_id' => '4e705d0c-2395-42ee-ac52-37a06eee6e3f',
        'product_id' => '531',
        'before' => '939x1066',
    ],
    [
        'src' => $out . '/218_04-e489420f68ee.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/912814485918/04-e489420f68ee.jpg',
        'expect_id' => 'b118223c-4c6e-4a83-bf7c-a435195fe1db',
        'product_id' => '218',
        'before' => '912x1200',
    ],
    [
        'src' => $out . '/227_06-bbdd6031688d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/925302638410/06-bbdd6031688d.jpg',
        'expect_id' => '4a2edca3-1f54-4474-9d15-a8384c65bdb2',
        'product_id' => '227',
        'before' => '608x800',
    ],
    [
        'src' => $out . '/272_03-62ad11e79d0f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1051635342005/03-62ad11e79d0f.jpg',
        'expect_id' => '123fac27-d054-48b9-8222-efa2bd7b8840',
        'product_id' => '272',
        'before' => '1200x1056',
    ],
    [
        'src' => $out . '/342_09-459c836ee7d3.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703375786953/09-459c836ee7d3.jpg',
        'expect_id' => 'a42c2cb7-2112-41fd-bd59-41a8cb51a723',
        'product_id' => '342',
        'before' => '782x667',
    ],
    [
        'src' => $out . '/347_04-f39d2a0654fe.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703732796408/04-f39d2a0654fe.jpg',
        'expect_id' => 'f7dd48da-25b5-42e2-9af0-8854fc786bde',
        'product_id' => '347',
        'before' => '772x800',
    ],
    [
        'src' => $out . '/350_07-e7b6c6621d9e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/704019401867/07-e7b6c6621d9e.jpg',
        'expect_id' => 'c6845217-8a64-4db8-9fcf-60000bf7793e',
        'product_id' => '350',
        'before' => '1056x1137',
    ],
    [
        'src' => $out . '/360_04-7d19cf251291.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715071130913/04-7d19cf251291.jpg',
        'expect_id' => '9449acbc-b4b6-49af-ae76-ff88923a3081',
        'product_id' => '360',
        'before' => '672x800',
    ],
    [
        'src' => $out . '/362_07-15126c6989a9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/715078490005/07-15126c6989a9.jpg',
        'expect_id' => '5680f6b7-c8ab-44cf-a6d7-5e17cc72e8b4',
        'product_id' => '362',
        'before' => '507x487',
    ],
    [
        'src' => $out . '/364_07-4c666a940b07.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/706301642313/07-4c666a940b07.jpg',
        'expect_id' => '7a5545c6-893c-4d13-9270-8029f01c2ffc',
        'product_id' => '364',
        'before' => '782x470',
    ],
    [
        'src' => $out . '/368_09-e49ade21b317.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/737369802795/09-e49ade21b317.jpg',
        'expect_id' => '69ce8620-3921-40f8-948b-8afe193e07ab',
        'product_id' => '368',
        'before' => '400x333',
    ],
    [
        'src' => $out . '/375_06-580a6255557e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/738962342070/06-580a6255557e.jpg',
        'expect_id' => 'bc9d4a7f-5900-44f0-adf1-842021fb2264',
        'product_id' => '375',
        'before' => '767x608',
    ],
    [
        'src' => $out . '/383_07-819c040cf8c9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752469198811/07-819c040cf8c9.jpg',
        'expect_id' => '0ba3eb2c-f8c5-467c-a2c2-7a1e2568a5be',
        'product_id' => '383',
        'before' => '710x608',
    ],
    [
        'src' => $out . '/384_06-819c040cf8c9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/752544903797/06-819c040cf8c9.jpg',
        'expect_id' => 'bd97c95f-26bc-43d5-ab57-2678bfcfe049',
        'product_id' => '384',
        'before' => '710x608',
    ],
    [
        'src' => $out . '/403_05-4b5c5a648ac0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/857385272130/05-4b5c5a648ac0.jpg',
        'expect_id' => 'd58a8ae7-d1f1-43e8-a920-bd6bd5c1cc09',
        'product_id' => '403',
        'before' => '812x1038',
    ],
    [
        'src' => $out . '/405_06-b4718ebc87e2.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/896146359561/06-b4718ebc87e2.jpg',
        'expect_id' => 'd6c50945-5022-4210-b36e-e1c28b190b21',
        'product_id' => '405',
        'before' => '732x794',
    ],
    [
        'src' => $out . '/414_12-e84be134f608.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/1028223378473/12-e84be134f608.jpg',
        'expect_id' => '0ab0734f-545b-451e-a8fa-65c57f470f6b',
        'product_id' => '414',
        'before' => '833x753',
    ],
    [
        'src' => $out . '/436_05-30bd81db01ae.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706013682914/05-30bd81db01ae.jpg',
        'expect_id' => '80419cba-2250-4138-a1d3-ca86df409aa5',
        'product_id' => '436',
        'before' => '730x960',
    ],
    [
        'src' => $out . '/447_03-d79323084d54.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706028294343/03-d79323084d54.jpg',
        'expect_id' => 'd95624df-a6a6-4480-8e3e-1858943651f2',
        'product_id' => '447',
        'before' => '960x885',
    ],
    [
        'src' => $out . '/459_05-892461753720.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1007764993817/05-892461753720.jpg',
        'expect_id' => 'da3709a3-66ac-4643-9676-9dccebc8343a',
        'product_id' => '459',
        'before' => '925x1000',
    ],
    [
        'src' => $out . '/460_05-c264789f3f90.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1008408346289/05-c264789f3f90.jpg',
        'expect_id' => '86b93092-0117-4543-a15d-2e0c8e80f17c',
        'product_id' => '460',
        'before' => '1096x1440',
    ],
    [
        'src' => $out . '/504_12-0adac2b5628d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/740456581150/12-0adac2b5628d.jpg',
        'expect_id' => 'bce27bb0-d815-4277-9dc5-af6c09770f44',
        'product_id' => '504',
        'before' => '997x950',
    ],
    [
        'src' => $out . '/515_06-b1c4b0dc8d77.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/813955763895/06-b1c4b0dc8d77.jpg',
        'expect_id' => '0d0e76c5-076f-41f4-8834-4ff5b8d15e04',
        'product_id' => '515',
        'before' => '706x786',
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
