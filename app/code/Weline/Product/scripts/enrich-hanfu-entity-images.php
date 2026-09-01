<?php

declare(strict_types=1);

/**
 * 下载公开可核验的品牌主图 / 供应商展厅图，写入 pub/media 并回填 logo_url / image_url。
 *
 * Usage: php app/code/Weline/Product/scripts/enrich-hanfu-entity-images.php [website_id]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductBrandAdminService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductSupplierAdminService;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));
$root = dirname(__DIR__, 5);
$mediaRoot = $root . '/pub/media/catalog/hanfu-entities';

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductBrandAdminService $brandAdmin */
$brandAdmin = ObjectManager::getInstance(ProductBrandAdminService::class);
/** @var ProductSupplierAdminService $supplierAdmin */
$supplierAdmin = ObjectManager::getInstance(ProductSupplierAdminService::class);

/**
 * code => 公开图源（官网 / 买购榜单品牌图 / 1688 工厂黄页展厅图）。
 *
 * @var array<string, string> $brandSources
 */
$brandSources = [
    'chonghuihantang' => 'http://www.chhthf.com/logo.jpg',
    'hanshanghualian' => 'https://image4.cnpp.cn/upload/images/20260406/22273360349_600x600.jpg',
    'shisanyu' => 'https://image.maigoo.com/upload/images/20230923/11172197679_600x600.jpg',
    'chixia' => 'https://image4.cnpp.cn/upload/images/20230414/09483034007_600x600.jpg',
    'zhonglingji' => 'https://image.maigoo.com/upload/images/20240226/13391019895_600x600.jpg',
    'huazhaoji' => 'https://image.maigoo.com/upload/images/20260309/09121583152_600x600.jpg',
    'rumengnishang' => 'https://image.maigoo.com/upload/images/20260508/12185093457_600x600.jpg',
    'zhizaosi' => 'https://image.maigoo.com/upload/images/20260813/02071826314_600x600.jpg',
];

/**
 * @var array<string, string> $supplierSources
 */
$supplierSources = [
    'meihe-hanfu' => 'http://www.maiquancheng.com/storage/site-images/1348/59726266.jpg',
    'qinglaiyi-hanfu' => 'http://qinglaiyi.com/static/upload/image/20250408/1744101926751471.png',
    'jizhi-youai' => 'https://cbu01.alicdn.com/i3/2200537899930/O1CN01kv05fn2NDzxhS5xOl_!!2200537899930-0-cbucrm.jpg',
    'luoruyan-factory' => 'https://cbu01.alicdn.com/i3/2200537899930/O1CN01kv05fn2NDzxhS5xOl_!!2200537899930-0-cbucrm.jpg',
    'factory-qiyige' => 'https://cbu01.alicdn.com/img/ibank/O1CN01ZXqfX61uZroe5ahwH_!!2200735266052-0-cib.jpg',
    'factory-huazhaoji-cx' => 'https://cbu01.alicdn.com/i3/2218368006417/O1CN01tGAVNn1xH2XOMG9A6_!!2218368006417-0-cbucrm.jpg',
    'factory-xinyao' => 'https://cbu01.alicdn.com/img/ibank/O1CN01pDGqP31R3h88uJGsn_!!2224442056-0-cib.jpg',
    'factory-ruibao' => 'https://cbu01.alicdn.com/i3/2200537899930/O1CN01kv05fn2NDzxhS5xOl_!!2200537899930-0-cbucrm.jpg',
    'factory-caibao' => 'https://cbu01.alicdn.com/img/ibank/O1CN0117k4PJ1HMnZruAhUu_!!2215477900744-0-cib.jpg',
    'factory-wumeirensheng' => 'https://cbu01.alicdn.com/img/ibank/O1CN01qI38Bl2GZKkJOQirW_!!1913359029-0-cib.jpg',
    'factory-qingtu' => 'https://cbu01.alicdn.com/img/ibank/9857792582_1724566531.jpg',
    'factory-canghai' => 'https://cbu01.alicdn.com/i2/2924299218/O1CN01QN9Ysn2HxtlKt9c1G_!!2924299218-0-cbucrm.jpg',
    'factory-lechi' => 'https://cbu01.alicdn.com/img/ibank/O1CN01oZxQIT1Bs2uCxYbDb_!!0-0-cib.jpg',
    'factory-jiamo' => 'https://cbu01.alicdn.com/i4/2216737018179/O1CN012yehah2AI2UCHNwgH_!!2216737018179-0-cbucrm.jpg',
    'factory-xingqilan' => 'https://cbu01.alicdn.com/img/ibank/O1CN01U9Q7Gn27xJDVeCll8_!!2203019077863-0-cib.jpg',
    'factory-mengran' => 'https://cbu01.alicdn.com/img/ibank/O1CN01XASD7T1va9fKvVM8u_!!2214659976188-0-cib.jpg',
    'factory-quansheng' => 'https://cbu01.alicdn.com/i4/2215698515394/O1CN0119WsK41piVFsvxe9u_!!2215698515394-0-cbucrm.jpg',
    'factory-yueya' => 'https://cbu01.alicdn.com/img/ibank/O1CN01cLBApM2GiUzA7XHwA_!!2206788049049-0-cib.jpg',
    'factory-ruwen' => 'https://cbu01.alicdn.com/i3/2218769336832/O1CN01nt507P20L6z0FCHmN_!!2218769336832-0-cbucrm.jpg',
    'factory-jianzhou' => 'https://cbu01.alicdn.com/img/ibank/O1CN019uvcEG1jydgZJMjlz_!!2917124617-0-cib.jpg',
];

$download = static function (string $url, string $targetFile): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (!is_dir(dirname($targetFile))) {
        mkdir(dirname($targetFile), 0775, true);
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    $fp = fopen($targetFile, 'wb');
    if ($fp === false) {
        curl_close($ch);

        return false;
    }
    $referer = 'https://www.1688.com/';
    if (str_contains($url, 'maiquancheng.com')) {
        $referer = 'http://www.maiquancheng.com/';
    } elseif (str_contains($url, 'qinglaiyi.com')) {
        $referer = 'http://qinglaiyi.com/';
    } elseif (str_contains($url, 'chhthf.com')) {
        $referer = 'http://www.chhthf.com/';
    } elseif (str_contains($url, 'maigoo.com') || str_contains($url, 'cnpp.cn')) {
        $referer = 'https://web.maigoo.com/';
    } elseif (str_contains($url, 'alicdn.com')) {
        $referer = 'https://www.taobao.com/';
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_REFERER => $referer,
    ]);
    $ok = curl_exec($ch) !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    fclose($fp);
    if (!$ok || !is_file($targetFile) || filesize($targetFile) < 4000) {
        @unlink($targetFile);

        return false;
    }

    return true;
};

$guessExt = static function (string $url, string $file): string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        return 'jpg';
    }
    $head = (string)fread($fh, 16);
    fclose($fh);
    if (str_starts_with($head, "\x89PNG")) {
        return 'png';
    }
    if (str_starts_with($head, 'RIFF') && str_contains($head, 'WEBP')) {
        return 'webp';
    }
    if (str_starts_with($head, 'GIF8')) {
        return 'gif';
    }

    return 'jpg';
};

$brandsByCode = [];
foreach ($brandAdmin->list($websiteId) as $row) {
    $brandsByCode[(string)$row['code']] = $row;
}
$suppliersByCode = [];
foreach ($supplierAdmin->list($websiteId) as $row) {
    $suppliersByCode[(string)$row['code']] = $row;
}

$brandsUpdated = 0;
$brandsFailed = [];
foreach ($brandSources as $code => $sourceUrl) {
    $row = $brandsByCode[$code] ?? null;
    if ($row === null) {
        $brandsFailed[] = $code . ':missing';
        continue;
    }
    $tmp = $mediaRoot . '/brands/' . $code . '.download';
    if (!$download($sourceUrl, $tmp)) {
        $brandsFailed[] = $code . ':download';
        continue;
    }
    $ext = $guessExt($sourceUrl, $tmp);
    $absolute = $mediaRoot . '/brands/' . $code . '.' . $ext;
    @unlink($absolute);
    rename($tmp, $absolute);
    $publicPath = '/pub/media/catalog/hanfu-entities/brands/' . $code . '.' . $ext;
    $brandAdmin->save($websiteId, [
        'brand_id' => (int)$row['brand_id'],
        'name' => (string)$row['name'],
        'code' => $code,
        'description' => (string)($row['description'] ?? ''),
        'status' => (string)($row['status'] ?? 'active'),
        'position' => (int)($row['position'] ?? 0),
        'logo_url' => $publicPath,
        'logo_asset_id' => (string)($row['logo_asset_id'] ?? ''),
        'supplier_ids' => array_map(
            'intval',
            is_array($row['supplier_ids'] ?? null) ? $row['supplier_ids'] : [],
        ),
    ]);
    $brandsUpdated++;
}

$suppliersUpdated = 0;
$suppliersFailed = [];
foreach ($supplierSources as $code => $sourceUrl) {
    $row = $suppliersByCode[$code] ?? null;
    if ($row === null) {
        $suppliersFailed[] = $code . ':missing';
        continue;
    }
    $tmp = $mediaRoot . '/suppliers/' . $code . '.download';
    if (!$download($sourceUrl, $tmp)) {
        $suppliersFailed[] = $code . ':download';
        continue;
    }
    $ext = $guessExt($sourceUrl, $tmp);
    $absolute = $mediaRoot . '/suppliers/' . $code . '.' . $ext;
    @unlink($absolute);
    rename($tmp, $absolute);
    $publicPath = '/pub/media/catalog/hanfu-entities/suppliers/' . $code . '.' . $ext;
    $supplierAdmin->save($websiteId, [
        'supplier_id' => (int)$row['supplier_id'],
        'name' => (string)$row['name'],
        'code' => $code,
        'store_url' => (string)($row['store_url'] ?? ''),
        'image_url' => $publicPath,
        'image_asset_id' => (string)($row['image_asset_id'] ?? ''),
        'contact_name' => (string)($row['contact_name'] ?? ''),
        'contact_phone' => (string)($row['contact_phone'] ?? ''),
        'contact_email' => (string)($row['contact_email'] ?? ''),
        'default_currency' => (string)($row['default_currency'] ?? 'CNY'),
        'default_payment_terms' => (string)($row['default_payment_terms'] ?? ''),
        'default_lead_time_days' => $row['default_lead_time_days'] ?? null,
        'default_moq' => $row['default_moq'] ?? null,
        'description' => (string)($row['description'] ?? ''),
        'status' => (string)($row['status'] ?? 'active'),
        'position' => (int)($row['position'] ?? 0),
        'brand_ids' => array_map(
            'intval',
            is_array($row['brand_ids'] ?? null) ? $row['brand_ids'] : [],
        ),
    ]);
    $suppliersUpdated++;
}

echo json_encode([
    'website_id' => $websiteId,
    'brands_updated' => $brandsUpdated,
    'suppliers_updated' => $suppliersUpdated,
    'brands_failed' => $brandsFailed,
    'suppliers_failed' => $suppliersFailed,
    'media_root' => '/pub/media/catalog/hanfu-entities/',
    'note' => '图源为官网/买购公开品牌图/1688 工厂黄页展厅图；本地落盘后写 logo_url/image_url。',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
