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
$done = '/tmp/p-img-opt-wave9-20260918/done.tsv';

$jobs = [
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/535_01-218f3375ef87.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/901203282084/01-218f3375ef87.jpg',
        'expect_id' => '915cd2b8-2fa4-4b4c-9ff3-da03cddbaa4c',
        'product_id' => '535',
        'before' => '1024x1024',
        'why' => 'seam',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/509_01-aa9728a9ecb9.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/751558756922/01-aa9728a9ecb9.jpg',
        'expect_id' => 'abc5dfa2-e34e-4494-9755-65e77ae6e449',
        'product_id' => '509',
        'before' => '1024x1024',
        'why' => 'seam',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/508_01-6e033aa02ddb.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/749456249410/01-6e033aa02ddb.jpg',
        'expect_id' => '5474e615-b00c-4cbe-9286-76a12c7ca1ec',
        'product_id' => '508',
        'before' => '800x800',
        'why' => 'seam',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/471_01-30c0f6ec800e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1055472268607/01-30c0f6ec800e.jpg',
        'expect_id' => '2db7c551-b41d-4a0a-860e-41ed68de51a9',
        'product_id' => '471',
        'before' => '1920x1920',
        'why' => 'seam',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/499_01-95c129dd362d.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/721216122823/01-95c129dd362d.jpg',
        'expect_id' => '8285bf3a-cd31-439a-8349-37516d8ac8ce',
        'product_id' => '499',
        'before' => '750x750',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/498_01-262d2d29e56a.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/718521925364/01-262d2d29e56a.jpg',
        'expect_id' => 'a0fc6c1e-c57a-4f69-a016-326eef75403e',
        'product_id' => '498',
        'before' => '720x720',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/494_01-6737dc256907.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/715165586377/01-6737dc256907.jpg',
        'expect_id' => '56b4cb5f-9082-47f6-acbc-f72d4365fcbf',
        'product_id' => '494',
        'before' => '750x750',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/486_01-aab47c8db6c7.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/700343589208/01-aab47c8db6c7.jpg',
        'expect_id' => '17ef0f2b-7536-495d-a600-9902a6a2bd51',
        'product_id' => '486',
        'before' => '750x750',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/477_01-302221fe68ae.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-xinyao/1062533388157/01-302221fe68ae.jpg',
        'expect_id' => 'cc7be42a-f520-4e61-95d9-b515c9286046',
        'product_id' => '477',
        'before' => '478x475',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/454_01-3a067729b24e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/794049084176/01-3a067729b24e.jpg',
        'expect_id' => 'a17a0d56-bf3f-4c8f-99f2-8b062d5d5cb9',
        'product_id' => '454',
        'before' => '728x728',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/448_01-4fadcd0f1143.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/706245191669/01-4fadcd0f1143.jpg',
        'expect_id' => '7974e8ab-a9d6-4ca7-a873-e7268c303417',
        'product_id' => '448',
        'before' => '750x750',
        'why' => 'tiny',
    ],
    [
        'src' => '/tmp/p-img-opt-wave9-20260918/out/427_01-39cecdd2a01e.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/704908236604/01-39cecdd2a01e.jpg',
        'expect_id' => '469ee3bb-5a6b-4f37-b5aa-0149c63a9024',
        'product_id' => '427',
        'before' => '650x650',
        'why' => 'tiny',
    ],
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\twhy\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");

foreach ($jobs as $j) {
    $path = $j['src'];
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        exit(1);
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad {$path}\n");
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
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\t{$j['why']}\t{$j['before']}\t{$w}x{$h}\t是\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['why']} {$j['before']} -> {$w}x{$h}\n";
}
fclose($fh);
echo "done\n";
