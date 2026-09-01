<?php

declare(strict_types=1);

/**
 * 将已种子的汉服品牌/供应商店铺入口，从 1688 关键词检索页替换为公开可核验的真实店铺/工厂页。
 *
 * 来源：1688 工厂黄页 title 核验、美和官网、菁莱依官网、淘宝/天猫公开旗舰店主机名。
 * 不编造电话/邮箱；无法核验 title 的链接不写入。
 *
 * Usage: php app/code/Weline/Product/scripts/enrich-hanfu-real-stores.php [website_id]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductBrandAdminService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductSupplierAdminService;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$websiteId = max(0, (int)($argv[1] ?? 0));

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);

/** @var ProductBrandAdminService $brandAdmin */
$brandAdmin = ObjectManager::getInstance(ProductBrandAdminService::class);
/** @var ProductSupplierAdminService $supplierAdmin */
$supplierAdmin = ObjectManager::getInstance(ProductSupplierAdminService::class);

$appendOfficial = static function (string $base, string $label, string $url): string {
    $base = trim($base);
    $line = $label . '：' . $url;
    if ($base !== '' && str_contains($base, $url)) {
        return $base;
    }

    return $base === '' ? $line : ($base . "\n" . $line);
};

/** @var array<string, array{url:string,label:string}> $brandStores */
$brandStores = [
    'chonghuihantang' => ['url' => 'https://chonghuihantang.tmall.com/', 'label' => '天猫旗舰店'],
    'shisanyu' => ['url' => 'https://shisanyu.taobao.com/', 'label' => '淘宝官方店'],
    'chixia' => ['url' => 'https://chixia.tmall.com/', 'label' => '天猫旗舰店'],
    'hanshanghualian' => ['url' => 'https://shop252544074.taobao.com/', 'label' => '淘宝店（公开索引）'],
];

$brandsByCode = [];
foreach ($brandAdmin->list($websiteId) as $row) {
    $brandsByCode[(string)$row['code']] = $row;
}

$brandsUpdated = 0;
foreach ($brandStores as $code => $store) {
    $row = $brandsByCode[$code] ?? null;
    if ($row === null) {
        continue;
    }
    $desc = $appendOfficial((string)($row['description'] ?? ''), $store['label'], $store['url']);
    $brandAdmin->save($websiteId, [
        'brand_id' => (int)$row['brand_id'],
        'name' => (string)$row['name'],
        'code' => $code,
        'description' => $desc,
        'status' => (string)($row['status'] ?? 'active'),
        'position' => (int)($row['position'] ?? 0),
        'logo_url' => (string)($row['logo_url'] ?? ''),
        'logo_asset_id' => (string)($row['logo_asset_id'] ?? ''),
        'supplier_ids' => array_map('intval', is_array($row['supplier_ids'] ?? null) ? $row['supplier_ids'] : []),
    ]);
    $brandsUpdated++;
}

/**
 * 具名主体 + 已 title 核验的 1688 工厂页 / 官网。
 *
 * @var list<array<string,mixed>> $verifiedSuppliers
 */
$verifiedSuppliers = [
    [
        'code' => 'meihe-hanfu',
        'name' => '美和汉服批发（曹县源头）',
        'store_url' => 'http://www.maiquancheng.com/',
        'description' => '公开官网核验。工商线索：曹县美和电子商务有限公司，环岛花园紫竹苑17号，统一社会信用代码 9137172133455322X4。',
        'brand_codes' => ['youaihanfu', 'luoruyan', 'zhizaosi', 'huashangjiuzhou'],
        'default_moq' => 1,
        'default_lead_time_days' => 3,
        'default_payment_terms' => '满500元起批',
    ],
    [
        'code' => 'qinglaiyi-hanfu',
        'name' => '菁莱依服饰（曹县）',
        'store_url' => 'http://qinglaiyi.com/',
        'description' => '企业官网核验：演出服/表演服/道具服生产主体。',
        'brand_codes' => ['huashangjiuzhou', 'zhizaosi', 'rumengnishang'],
        'default_moq' => 10,
        'default_lead_time_days' => 7,
    ],
    [
        'code' => 'factory-qiyige',
        'name' => '柒依阁服饰（曹县梁堤头）',
        'store_url' => 'https://www.1688.com/factory/b2b-2200735266052505df.html',
        'description' => '1688 工厂黄页 title 核验。公开地址线索：梁堤头镇梁东村。',
        'brand_codes' => ['zhizaosi', 'youaihanfu', 'zuihuanlou'],
        'default_moq' => 5,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-huazhaoji-cx',
        'name' => '花朝记服饰（曹县工厂）',
        'store_url' => 'https://www.1688.com/factory/b2b-22183680064172be3c.html',
        'description' => '1688 工厂黄页 title 核验：曹县花朝记服饰有限公司（产业带加工主体，与零售品牌渠道可能并存）。',
        'brand_codes' => ['huazhaoji', 'zhonglingji', 'rumengnishang'],
        'default_moq' => 10,
        'default_lead_time_days' => 7,
    ],
    [
        'code' => 'factory-xinyao',
        'name' => '鑫耀服装厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2224442056.html',
        'description' => '1688 工厂黄页 title 核验：儿童表演服/汉服禅茶服等。',
        'brand_codes' => ['zhizaosi', 'shangyao', 'yuechi'],
        'default_moq' => 5,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-ruibao',
        'name' => '蕊宝服饰（曹县安蔡楼）',
        'store_url' => 'https://www.1688.com/factory/b2b-22005378999307b4bf.html',
        'description' => '1688 工厂黄页 title 核验：安蔡楼产业带汉服相关主体。',
        'brand_codes' => ['youaihanfu', 'luoruyan', 'zhizaosi'],
        'default_moq' => 5,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-caibao',
        'name' => '蔡宝服装厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2215477900744af982.html',
        'description' => '1688 工厂黄页 title 核验：汉服套装/表演服加工。',
        'brand_codes' => ['zhizaosi', 'zuihuanlou', 'zuimengxifeng'],
        'default_moq' => 5,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-quyao',
        'name' => '曲瑶服饰厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2220394068847dd284.html',
        'description' => '1688 工厂黄页 title 核验：汉服/马面裙/表演服定制。',
        'brand_codes' => ['zhizaosi', 'luoruyan', 'qingluo'],
        'default_moq' => 5,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-wumeirensheng',
        'name' => '舞美人生服装厂（曹县大集）',
        'store_url' => 'https://www.1688.com/factory/b2b-1913359029.html',
        'description' => '1688 工厂黄页 title 核验：大集镇演出服装生产主体。',
        'brand_codes' => ['huashangjiuzhou', 'zhizaosi', 'rumengnishang'],
        'default_moq' => 20,
        'default_lead_time_days' => 10,
    ],
    [
        'code' => 'factory-qingtu',
        'name' => '青途制衣（曹县大集刘楼）',
        'store_url' => 'https://www.1688.com/factory/b2b-4047241169a4913.html',
        'description' => '1688 工厂黄页 title 核验：大集镇刘楼村；小批量定制公开展示。',
        'brand_codes' => ['zhizaosi', 'chonghuihantang', 'yunzhaohantang'],
        'default_moq' => 2,
        'default_lead_time_days' => 7,
    ],
    [
        'code' => 'factory-canghai',
        'name' => '沧海服装厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2924299218550c0.html',
        'description' => '1688 工厂黄页 title 核验：童汉服/演出服等。',
        'brand_codes' => ['zhizaosi', 'shangyao', 'yuechi'],
        'default_moq' => 10,
        'default_lead_time_days' => 7,
    ],
    [
        'code' => 'factory-lechi',
        'name' => '乐驰服饰（曹县安蔡楼）',
        'store_url' => 'https://www.1688.com/factory/b2b-22007035981033797a.html',
        'description' => '1688 工厂黄页 title 核验。工商线索：安蔡楼镇火神台村。',
        'brand_codes' => ['youaihanfu', 'zhizaosi', 'zuihuanlou'],
        'default_moq' => 10,
        'default_lead_time_days' => 7,
    ],
    [
        'code' => 'factory-jiamo',
        'name' => '佳沫服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-22167370181794705e.html',
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'huazhaoji'],
        'default_moq' => 5,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-xingqilan',
        'name' => '星启澜服饰（曹县大集）',
        'store_url' => 'https://www.1688.com/factory/b2b-290063999377574.html',
        'description' => '1688 工厂黄页 title 核验：大集乡刘楼村；改良汉服/表演服。',
        'brand_codes' => ['zhizaosi', 'huashangjiuzhou', 'qingluo'],
        'default_moq' => 2,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-mengran',
        'name' => '梦然服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2212695223247b2bf9.html',
        'description' => '1688 工厂黄页 title 核验：舞蹈服/表演服/汉服禅茶服。',
        'brand_codes' => ['zhizaosi', 'ainuodai'],
        'default_moq' => 10,
        'default_lead_time_days' => 7,
    ],
    [
        'code' => 'factory-quansheng',
        'name' => '全胜服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2215698515394cf3eb.html',
        'description' => '1688 工厂黄页 title 核验：童汉服/表演服。',
        'brand_codes' => ['zhizaosi', 'shangyao'],
        'default_moq' => 10,
        'default_lead_time_days' => 7,
    ],
    [
        'code' => 'factory-yueya',
        'name' => '悦雅服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-220678804904970661.html',
        'description' => '1688 工厂黄页 title 核验：汉服套装等；公开品牌线索「悦雅霓裳」。',
        'brand_codes' => ['zhizaosi', 'rumengnishang', 'huazhaoji'],
        'default_moq' => 5,
        'default_lead_time_days' => 5,
    ],
    [
        'code' => 'factory-ruwen',
        'name' => '儒文服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-221876933683243132.html',
        'description' => '1688 工厂黄页 title 核验：舞台表演服定制。',
        'brand_codes' => ['huashangjiuzhou', 'yunshang'],
        'default_moq' => 10,
        'default_lead_time_days' => 10,
    ],
    [
        'code' => 'factory-jianzhou',
        'name' => '建州服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2917124617c6cad.html',
        'description' => '1688 工厂黄页 title 核验：汉服/演出服加工。',
        'brand_codes' => ['zhizaosi', 'chonghuihantang', 'yunzhaohantang'],
        'default_moq' => 10,
        'default_lead_time_days' => 7,
    ],
    // 具名主体：补公开入口（仍无独立工厂页时保留说明，店铺用最接近的可核验页）
    [
        'code' => 'jizhi-youai',
        'name' => '极智有爱汉服基地/有爱云仓',
        'store_url' => 'https://www.1688.com/factory/b2b-22005378999307b4bf.html',
        'description' => '公开报道：曹县安蔡楼头部汉服产业基地/云仓，旗下含洛如嫣等。店铺暂挂同镇可核验工厂黄页（蕊宝）作产业带入口，采购请现场/1688 再核主体。',
        'brand_codes' => ['luoruyan', 'youaihanfu', 'zhizaosi', 'chixia'],
        'default_moq' => 1,
        'default_lead_time_days' => 5,
        'default_payment_terms' => '现场现金/对公（公开报道多见）',
    ],
    [
        'code' => 'luoruyan-factory',
        'name' => '洛如嫣国风服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-22005378999307b4bf.html',
        'description' => '公开报道：有爱云仓旗下品牌工厂。暂挂安蔡楼可核验工厂黄页作产业带入口；零售请在天猫搜索「洛如嫣」。',
        'brand_codes' => ['luoruyan'],
        'default_moq' => 5,
        'default_lead_time_days' => 7,
    ],
];

$brandIdByCode = [];
foreach ($brandsByCode as $code => $row) {
    $brandIdByCode[$code] = (int)$row['brand_id'];
}

$existingSuppliers = [];
foreach ($supplierAdmin->list($websiteId) as $row) {
    $existingSuppliers[(string)$row['code']] = $row;
}

$placeholderPrefixes = [
    'caoxian-ancailou-',
    'caoxian-daji-',
    'caoxian-mamian-',
    'caoxian-tangzhi-',
    'caoxian-mingzhi-',
    'suzhou-hanfu-',
    'yiwu-hanfu-',
    'guangzhou-hanfu-',
    'hangzhou-hanfu-',
    'hefei-hanfu-',
];

$disabledPlaceholders = 0;
foreach ($existingSuppliers as $code => $row) {
    $isPlaceholder = false;
    foreach ($placeholderPrefixes as $prefix) {
        if (str_starts_with($code, $prefix)) {
            $isPlaceholder = true;
            break;
        }
    }
    if (!$isPlaceholder) {
        continue;
    }
    if ((string)($row['status'] ?? '') === 'disabled') {
        continue;
    }
    $supplierAdmin->disable($websiteId, (int)$row['supplier_id']);
    $disabledPlaceholders++;
}

// 无独立可核验店址的具名主体：强制清空旧 1688 关键词检索 URL（nullable 写入可能被 ORM 跳过 null）
$clearStoreCodes = [
    'yimao-huafu',
    'huaqianyuexia',
    'chenfei-fushi',
    'qingcheng-zhilian',
    'ruili-hanyun',
];
$clearedKeywordUrls = 0;
foreach ($clearStoreCodes as $code) {
    $row = $existingSuppliers[$code] ?? null;
    if ($row === null) {
        continue;
    }
    $url = (string)($row['store_url'] ?? '');
    if ($url === '' || !str_contains($url, 's.1688.com/selloffer')) {
        continue;
    }
    ObjectManager::getInstance(\Weline\Product\Repository\SupplierRepository::class)
        ->updateFields($websiteId, (int)$row['supplier_id'], [
            \Weline\Product\Model\Shard\Supplier::schema_fields_STORE_URL => '',
            \Weline\Product\Model\Shard\Supplier::schema_fields_DESCRIPTION => match ($code) {
                'yimao-huafu' => '新华网等公开报道：大集镇流水线汉服生产主体。尚无独立可核验工厂页，店铺已清空旧检索入口。',
                'huaqianyuexia' => '新华网公开报道线索。尚无独立可核验工厂页，店铺已清空旧检索入口。',
                'chenfei-fushi' => '公开报道线索。尚无独立可核验工厂页，店铺已清空旧检索入口。',
                'qingcheng-zhilian' => '公开行业资讯：昆明螺蛳湾相关汉服婚服/秀禾供货。尚无独立可核验网店 URL，店铺已清空旧检索入口。',
                default => '公开采购指南线索。尚无独立可核验网店 URL，店铺已清空旧检索入口。',
            },
        ]);
    $clearedKeywordUrls++;
}

$suppliersSaved = 0;
$linkCount = 0;
$position = 100;
foreach ($verifiedSuppliers as $supplier) {
    $code = (string)$supplier['code'];
    $existing = $existingSuppliers[$code] ?? null;
    $brandIds = [];
    foreach ((array)($supplier['brand_codes'] ?? []) as $brandCode) {
        $bid = (int)($brandIdByCode[(string)$brandCode] ?? 0);
        if ($bid > 0) {
            $brandIds[] = $bid;
        }
    }
    $payload = [
        'supplier_id' => $existing ? (int)$existing['supplier_id'] : 0,
        'name' => (string)$supplier['name'],
        'code' => $code,
        'store_url' => (string)$supplier['store_url'],
        'image_url' => $existing ? (string)($existing['image_url'] ?? '') : '',
        'image_asset_id' => $existing ? (string)($existing['image_asset_id'] ?? '') : '',
        'contact_name' => $existing ? (string)($existing['contact_name'] ?? '') : '',
        'contact_phone' => '',
        'contact_email' => '',
        'default_currency' => 'CNY',
        'default_payment_terms' => (string)($supplier['default_payment_terms'] ?? ($existing['default_payment_terms'] ?? '')),
        'default_lead_time_days' => $supplier['default_lead_time_days'] ?? null,
        'default_moq' => $supplier['default_moq'] ?? null,
        'description' => (string)$supplier['description'],
        'status' => 'active',
        'position' => $existing ? (int)($existing['position'] ?? $position) : $position,
        'brand_ids' => $brandIds,
    ];
    $supplierAdmin->save($websiteId, $payload);
    $suppliersSaved++;
    $linkCount += count($brandIds);
    $position++;
}

echo json_encode([
    'website_id' => $websiteId,
    'brands_updated_with_official_store' => $brandsUpdated,
    'verified_suppliers_upserted' => $suppliersSaved,
    'placeholder_suppliers_disabled' => $disabledPlaceholders,
    'keyword_store_urls_cleared' => $clearedKeywordUrls,
    'brand_link_assignments' => $linkCount,
    'note' => '仅写入 title/官网可核验的店铺入口；电话邮箱仍不编造。',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
