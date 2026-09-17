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
$out = '/tmp/p-ar-wave5-20260917/out';
$done = '/tmp/p-ar-wave5-20260917/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/443_04-288b85ea7fab.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708123722007/04-288b85ea7fab.jpg',
        'expect_id' => 'ffda161d-ca1b-44cc-9399-8438698dc867',
        'product_id' => '443',
        'before' => '716x743',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/444_05-2935c818ead0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/714054146974/05-2935c818ead0.jpg',
        'expect_id' => '87deb229-0040-4cf7-9660-1b9ce43cec1d',
        'product_id' => '444',
        'before' => '760x992',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/448_03-e0fedad0cbc8.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706245191669/03-e0fedad0cbc8.jpg',
        'expect_id' => '651ea12d-1cf9-4bad-8039-58100ec5a3dc',
        'product_id' => '448',
        'before' => '570x727',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/456_05-0ba977e97985.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708240766569/05-0ba977e97985.jpg',
        'expect_id' => '2b7d6480-cb8f-4b42-92b5-07397ab8fc79',
        'product_id' => '456',
        'before' => '608x775',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/458_05-da6d36dcf91a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1006137196601/05-da6d36dcf91a.jpg',
        'expect_id' => '2289588b-1ddf-462c-b017-93e3bfcaa2fd',
        'product_id' => '458',
        'before' => '623x799',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/480_05-866d50b663fb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1064054265883/05-866d50b663fb.jpg',
        'expect_id' => 'ff4fb51c-bb9b-427f-829a-2b748622e9c3',
        'product_id' => '480',
        'before' => '608x794',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/481_05-b130dd0fa41c.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1065005448136/05-b130dd0fa41c.jpg',
        'expect_id' => '8308095f-d3c0-4239-b47c-a6064e7a0cf0',
        'product_id' => '481',
        'before' => '658x797',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/520_05-d43bbda3a42b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/814388216308/05-d43bbda3a42b.jpg',
        'expect_id' => 'a7b3ebb9-8bb6-438a-9c2b-39de2218d274',
        'product_id' => '520',
        'before' => '890x1000',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/177_05-202cdadbc73e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/825455560351/05-202cdadbc73e.jpg',
        'expect_id' => '44907837-0bdc-4bc6-bf2c-ac5f52b1274f',
        'product_id' => '177',
        'before' => '1086x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/210_05-7e3f4006075b.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/897341085402/05-7e3f4006075b.jpg',
        'expect_id' => 'f2307b94-bb60-4ff7-a1ee-4b6fe99c452a',
        'product_id' => '210',
        'before' => '1056x1200',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/211_06-0b5473b08fdc.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/899003672291/06-0b5473b08fdc.jpg',
        'expect_id' => 'a5e2f232-8a0d-4239-9de5-b1452e46d2bf',
        'product_id' => '211',
        'before' => '738x790',
    ],
    [
        'src' => '/tmp/p-ar-wave5-20260917/out/217_06-eeece4337123.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/908548254710/06-eeece4337123.jpg',
        'expect_id' => 'a7f67172-8ab3-4b7d-94d8-bc77995a22e5',
        'product_id' => '217',
        'before' => '627x800',
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
