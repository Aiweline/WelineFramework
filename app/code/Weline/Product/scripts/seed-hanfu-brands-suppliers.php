<?php

declare(strict_types=1);

/**
 * 汉服品牌 + 供应商（约50）公开资料种子，并建立 N:N 挂钩。
 *
 * 资料来源：公开榜单/新闻报道/企业官网/1688 工厂黄页 title 核验。
 * 未公开核实的电话/邮箱不编造；店铺仅写官网或可核验工厂页（勿用关键词检索页冒充）。
 * 存量检索档口回填/停用：enrich-hanfu-real-stores.php
 *
 * Usage: php app/code/Weline/Product/scripts/seed-hanfu-brands-suppliers.php [website_id]
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

/** @var list<array{code:string,name:string,description:string,logo_url?:string}> $brands */
$brands = [
    ['code' => 'minghuatang', 'name' => '明华堂', 'description' => '形制考究的汉服品牌，公开榜单常上前十。公开报道称长期以官网为唯一销售渠道，勿轻信山寨淘宝店。'],
    ['code' => 'chonghuihantang', 'name' => '重回汉唐', 'description' => "成都重回汉唐文化传播有限公司；2006 创立，传统形制复原与文化交流。\n天猫旗舰店：https://chonghuihantang.tmall.com/"],
    ['code' => 'hanshanghualian', 'name' => '汉尚华莲', 'description' => "广州汉尚华莲贸易有限公司；改良汉服与日常穿搭，旗下含鹿韵记/九锦司/初立等。\n淘宝店（公开索引）：https://shop252544074.taobao.com/"],
    ['code' => 'shisanyu', 'name' => '十三余', 'description' => "杭州达哉文化有限公司；原创设计与 IP 联名，年轻向汉服。\n淘宝官方店：https://shisanyu.taobao.com/"],
    ['code' => 'huashangjiuzhou', 'name' => '华裳九州', 'description' => '大众国风汉服品牌，公开榜单常入围。'],
    ['code' => 'chixia', 'name' => '池夏', 'description' => "合肥池夏文化传媒有限公司；汉元素日常时装。\n天猫旗舰店：https://chixia.tmall.com/"],
    ['code' => 'zhonglingji', 'name' => '钟灵记', 'description' => '明制等形制汉服品牌，公开口碑榜常入围。'],
    ['code' => 'huazhaoji', 'name' => '花朝记', 'description' => '汉服品牌，公开十大榜单常入围。曹县另有「花朝记服饰」1688 工厂主体，零售渠道请与工厂页区分核验。'],
    ['code' => 'rumengnishang', 'name' => '如梦霓裳', 'description' => '汉服品牌，公开榜单常入围。'],
    ['code' => 'zhizaosi', 'name' => '织造司', 'description' => '高性价比马面裙等新锐汉服品牌；公开渠道请在天猫搜索「织造司旗舰店」正名核验。'],
    ['code' => 'yunzhaohantang', 'name' => '雲照汉唐', 'description' => '改良汉服公开榜单入围品牌。'],
    ['code' => 'liuyanxiling', 'name' => '流烟昔泠', 'description' => '古典梦幻风格汉服品牌。'],
    ['code' => 'zhiyuji', 'name' => '织羽集', 'description' => '市场认可度较高的汉服品牌。'],
    ['code' => 'zuiyuduo', 'name' => '醉雨朵', 'description' => '汉服品牌，常见于货源/品牌盘点。'],
    ['code' => 'ainuodai', 'name' => '艾诺黛', 'description' => '汉服品牌，常见于行业盘点。'],
    ['code' => 'luoruyan', 'name' => '洛如嫣', 'description' => '曹县极智有爱云仓旗下高端马面裙品牌（公开报道）。零售请在天猫搜索「洛如嫣」正名。'],
    ['code' => 'youaihanfu', 'name' => '有爱汉服', 'description' => '曹县有爱汉服基地相关品牌/基地渠道（公开报道）。'],
    ['code' => 'xiaohuaxiaoxia', 'name' => '小华小夏', 'description' => '重回汉唐体系相关公开子品牌线索。'],
    ['code' => 'lvyunji', 'name' => '鹿韵记', 'description' => '汉尚华莲体系独立品牌（公开报道）。'],
    ['code' => 'jiujinsi', 'name' => '九锦司', 'description' => '汉尚华莲体系独立品牌（公开报道）。'],
    ['code' => 'chuli', 'name' => '初立', 'description' => '汉尚华莲体系独立品牌（公开报道）。'],
    ['code' => 'hanmuyufeng', 'name' => '华沐汉风', 'description' => '公开榜单关联的汉服/汉元素品牌线索。'],
    ['code' => 'zuihuanlou', 'name' => '醉欢楼', 'description' => '电商常见汉服店铺品牌（公开 listing 参考）。'],
    ['code' => 'zuimengxifeng', 'name' => '醉梦夕风', 'description' => '电商常见汉服店铺品牌（公开 listing 参考）。'],
    ['code' => 'shangyao', 'name' => '裳谣', 'description' => '汉服电商常见品牌名（公开盘点常见）。'],
    ['code' => 'yuechi', 'name' => '月池', 'description' => '汉服电商常见品牌名（公开盘点常见）。'],
    ['code' => 'qingluo', 'name' => '青萝', 'description' => '汉服电商常见品牌名（公开盘点常见）。'],
    ['code' => 'changan', 'name' => '长安', 'description' => '汉服/中式礼服方向常见品牌名。'],
    ['code' => 'jinxiuweiyang', 'name' => '锦绣未央', 'description' => '中式/汉服婚服方向公开供货线索。'],
    ['code' => 'qingchengzhilian', 'name' => '倾城之恋', 'description' => '汉服婚服/秀禾等公开供货线索（昆明螺蛳湾相关报道）。'],
];

/**
 * 供应商：具名公开主体 + title 核验的 1688 工厂/官网（质量优先，不灌检索伪入口）。
 * 存量 caoxian-/suzhou- 等检索档口请跑 enrich-hanfu-real-stores.php 停用。
 *
 * @var list<array<string,mixed>> $suppliers
 */
$suppliers = [
    [
        'code' => 'meihe-hanfu',
        'name' => '美和汉服批发（曹县源头）',
        'store_url' => 'http://www.maiquancheng.com/',
        'default_currency' => 'CNY',
        'default_payment_terms' => '满500元起批',
        'default_moq' => 1,
        'default_lead_time_days' => 3,
        'description' => '公开官网核验。工商线索：曹县美和电子商务有限公司，环岛花园紫竹苑17号，统一社会信用代码 9137172133455322X4。',
        'brand_codes' => ['youaihanfu', 'luoruyan', 'zhizaosi', 'huashangjiuzhou'],
    ],
    [
        'code' => 'qinglaiyi-hanfu',
        'name' => '菁莱依服饰（曹县）',
        'store_url' => 'http://qinglaiyi.com/',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '企业官网核验：演出服/表演服/道具服生产主体。',
        'brand_codes' => ['huashangjiuzhou', 'zhizaosi', 'rumengnishang'],
    ],
    [
        'code' => 'jizhi-youai',
        'name' => '极智有爱汉服基地/有爱云仓',
        'store_url' => 'https://www.1688.com/factory/b2b-22005378999307b4bf.html',
        'default_currency' => 'CNY',
        'default_payment_terms' => '现场现金/对公（公开报道多见）',
        'default_moq' => 1,
        'default_lead_time_days' => 5,
        'description' => '公开报道：曹县安蔡楼头部汉服产业基地/云仓。店铺暂挂同镇可核验工厂黄页（蕊宝）作产业带入口。',
        'brand_codes' => ['luoruyan', 'youaihanfu', 'zhizaosi', 'chixia'],
    ],
    [
        'code' => 'luoruyan-factory',
        'name' => '洛如嫣国风服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-22005378999307b4bf.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 7,
        'description' => '公开报道：有爱云仓旗下品牌工厂。零售请在天猫搜索「洛如嫣」正名。',
        'brand_codes' => ['luoruyan'],
    ],
    [
        'code' => 'yimao-huafu',
        'name' => '义茂华服（曹县大集）',
        'store_url' => '',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 10,
        'description' => '新华网等公开报道：大集镇流水线汉服生产主体。尚无独立可核验工厂页，店铺留空。',
        'brand_codes' => ['zhizaosi', 'huashangjiuzhou', 'rumengnishang'],
    ],
    [
        'code' => 'huaqianyuexia',
        'name' => '花千约下纺织（曹县）',
        'store_url' => '',
        'default_currency' => 'CNY',
        'default_moq' => 20,
        'default_lead_time_days' => 7,
        'description' => '新华网公开报道线索。尚无独立可核验工厂页，店铺留空。',
        'brand_codes' => ['huazhaoji', 'liuyanxiling'],
    ],
    [
        'code' => 'chenfei-fushi',
        'name' => '辰霏服饰 / 曹县汉服协会相关主体',
        'store_url' => '',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '公开报道线索。尚无独立可核验工厂页，店铺留空。',
        'brand_codes' => ['youaihanfu', 'zhizaosi', 'zuihuanlou'],
    ],
    [
        'code' => 'qingcheng-zhilian',
        'name' => '倾城之恋婚纱（汉服婚服）',
        'store_url' => '',
        'default_currency' => 'CNY',
        'default_moq' => 1,
        'default_lead_time_days' => 5,
        'default_payment_terms' => '1件起订/混批（公开采购指南）',
        'description' => '公开行业资讯：昆明螺蛳湾相关汉服婚服/秀禾供货。尚无独立可核验网店 URL，店铺留空。',
        'brand_codes' => ['qingchengzhilian', 'jinxiuweiyang', 'changan'],
    ],
    [
        'code' => 'ruili-hanyun',
        'name' => '瑞丽汉韵华服工坊',
        'store_url' => '',
        'default_currency' => 'CNY',
        'default_moq' => 2,
        'default_lead_time_days' => 10,
        'description' => '公开采购指南线索。尚无独立可核验网店 URL，店铺留空。',
        'brand_codes' => ['changan', 'jinxiuweiyang'],
    ],
    [
        'code' => 'factory-qiyige',
        'name' => '柒依阁服饰（曹县梁堤头）',
        'store_url' => 'https://www.1688.com/factory/b2b-2200735266052505df.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。公开地址线索：梁堤头镇梁东村。',
        'brand_codes' => ['zhizaosi', 'youaihanfu', 'zuihuanlou'],
    ],
    [
        'code' => 'factory-huazhaoji-cx',
        'name' => '花朝记服饰（曹县工厂）',
        'store_url' => 'https://www.1688.com/factory/b2b-22183680064172be3c.html',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '1688 工厂黄页 title 核验：曹县花朝记服饰有限公司。',
        'brand_codes' => ['huazhaoji', 'zhonglingji', 'rumengnishang'],
    ],
    [
        'code' => 'factory-xinyao',
        'name' => '鑫耀服装厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2224442056.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'shangyao', 'yuechi'],
    ],
    [
        'code' => 'factory-ruibao',
        'name' => '蕊宝服饰（曹县安蔡楼）',
        'store_url' => 'https://www.1688.com/factory/b2b-22005378999307b4bf.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['youaihanfu', 'luoruyan', 'zhizaosi'],
    ],
    [
        'code' => 'factory-caibao',
        'name' => '蔡宝服装厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2215477900744af982.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'zuihuanlou', 'zuimengxifeng'],
    ],
    [
        'code' => 'factory-quyao',
        'name' => '曲瑶服饰厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2220394068847dd284.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'luoruyan', 'qingluo'],
    ],
    [
        'code' => 'factory-wumeirensheng',
        'name' => '舞美人生服装厂（曹县大集）',
        'store_url' => 'https://www.1688.com/factory/b2b-1913359029.html',
        'default_currency' => 'CNY',
        'default_moq' => 20,
        'default_lead_time_days' => 10,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['huashangjiuzhou', 'zhizaosi', 'rumengnishang'],
    ],
    [
        'code' => 'factory-qingtu',
        'name' => '青途制衣（曹县大集刘楼）',
        'store_url' => 'https://www.1688.com/factory/b2b-4047241169a4913.html',
        'default_currency' => 'CNY',
        'default_moq' => 2,
        'default_lead_time_days' => 7,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'chonghuihantang', 'yunzhaohantang'],
    ],
    [
        'code' => 'factory-canghai',
        'name' => '沧海服装厂（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2924299218550c0.html',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'shangyao', 'yuechi'],
    ],
    [
        'code' => 'factory-lechi',
        'name' => '乐驰服饰（曹县安蔡楼）',
        'store_url' => 'https://www.1688.com/factory/b2b-22007035981033797a.html',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '1688 工厂黄页 title 核验。工商线索：安蔡楼镇火神台村。',
        'brand_codes' => ['youaihanfu', 'zhizaosi', 'zuihuanlou'],
    ],
    [
        'code' => 'factory-jiamo',
        'name' => '佳沫服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-22167370181794705e.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'huazhaoji'],
    ],
    [
        'code' => 'factory-xingqilan',
        'name' => '星启澜服饰（曹县大集）',
        'store_url' => 'https://www.1688.com/factory/b2b-290063999377574.html',
        'default_currency' => 'CNY',
        'default_moq' => 2,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'huashangjiuzhou', 'qingluo'],
    ],
    [
        'code' => 'factory-mengran',
        'name' => '梦然服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2212695223247b2bf9.html',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'ainuodai'],
    ],
    [
        'code' => 'factory-quansheng',
        'name' => '全胜服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2215698515394cf3eb.html',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'shangyao'],
    ],
    [
        'code' => 'factory-yueya',
        'name' => '悦雅服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-220678804904970661.html',
        'default_currency' => 'CNY',
        'default_moq' => 5,
        'default_lead_time_days' => 5,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'rumengnishang', 'huazhaoji'],
    ],
    [
        'code' => 'factory-ruwen',
        'name' => '儒文服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-221876933683243132.html',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 10,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['huashangjiuzhou', 'changan'],
    ],
    [
        'code' => 'factory-jianzhou',
        'name' => '建州服饰（曹县）',
        'store_url' => 'https://www.1688.com/factory/b2b-2917124617c6cad.html',
        'default_currency' => 'CNY',
        'default_moq' => 10,
        'default_lead_time_days' => 7,
        'description' => '1688 工厂黄页 title 核验。',
        'brand_codes' => ['zhizaosi', 'chonghuihantang', 'yunzhaohantang'],
    ],
];

$existingBrands = [];
foreach ($brandAdmin->list($websiteId) as $row) {
    $existingBrands[(string)$row['code']] = (int)$row['brand_id'];
}

$brandIdByCode = $existingBrands;
$createdBrands = 0;
$updatedBrands = 0;
foreach ($brands as $i => $brand) {
    $code = $brand['code'];
    $existingId = (int)($brandIdByCode[$code] ?? 0);
    $existingLogo = '';
    if ($existingId > 0) {
        foreach ($brandAdmin->list($websiteId) as $existingRow) {
            if ((int)($existingRow['brand_id'] ?? 0) === $existingId) {
                $existingLogo = (string)($existingRow['logo_url'] ?? '');
                break;
            }
        }
    }
    $payload = [
        'brand_id' => $existingId,
        'name' => $brand['name'],
        'code' => $code,
        'description' => $brand['description'],
        'status' => 'active',
        'position' => $i + 1,
        'logo_url' => (string)($brand['logo_url'] ?? $existingLogo),
        'logo_asset_id' => '',
        'supplier_ids' => [], // 由供应商侧写入挂钩，避免互相覆盖
    ];
    $saved = $brandAdmin->save($websiteId, $payload);
    $brandIdByCode[$code] = (int)$saved['brand_id'];
    if (isset($existingBrands[$code])) {
        $updatedBrands++;
    } else {
        $createdBrands++;
        $existingBrands[$code] = (int)$saved['brand_id'];
    }
}

$existingSuppliers = [];
foreach ($supplierAdmin->list($websiteId) as $row) {
    $existingSuppliers[(string)$row['code']] = (int)$row['supplier_id'];
}

$createdSuppliers = 0;
$updatedSuppliers = 0;
$linkCount = 0;
foreach ($suppliers as $i => $supplier) {
    $code = (string)$supplier['code'];
    $brandCodes = is_array($supplier['brand_codes'] ?? null) ? $supplier['brand_codes'] : [];
    $brandIds = [];
    foreach ($brandCodes as $brandCode) {
        $bid = (int)($brandIdByCode[(string)$brandCode] ?? 0);
        if ($bid > 0) {
            $brandIds[] = $bid;
        }
    }
    $existingId = (int)($existingSuppliers[$code] ?? 0);
    $existingImage = '';
    $existingImageAsset = '';
    if ($existingId > 0) {
        // $existingSuppliers currently maps code=>id only; reload image from admin list if needed below
    }
    // Preserve previously enriched images when this seed omits them.
    static $supplierRowsByCode = null;
    if ($supplierRowsByCode === null) {
        $supplierRowsByCode = [];
        foreach ($supplierAdmin->list($websiteId) as $existingRow) {
            $supplierRowsByCode[(string)$existingRow['code']] = $existingRow;
        }
    }
    $existingRow = $supplierRowsByCode[$code] ?? null;
    if (is_array($existingRow)) {
        $existingImage = (string)($existingRow['image_url'] ?? '');
        $existingImageAsset = (string)($existingRow['image_asset_id'] ?? '');
    }
    $payload = [
        'supplier_id' => $existingId,
        'name' => (string)$supplier['name'],
        'code' => $code,
        'store_url' => (string)($supplier['store_url'] ?? ''),
        'image_url' => (string)($supplier['image_url'] ?? $existingImage),
        'image_asset_id' => (string)($supplier['image_asset_id'] ?? $existingImageAsset),
        'contact_name' => (string)($supplier['contact_name'] ?? ''),
        'contact_phone' => (string)($supplier['contact_phone'] ?? ''),
        'contact_email' => (string)($supplier['contact_email'] ?? ''),
        'default_currency' => (string)($supplier['default_currency'] ?? 'CNY'),
        'default_payment_terms' => (string)($supplier['default_payment_terms'] ?? ''),
        'default_lead_time_days' => $supplier['default_lead_time_days'] ?? null,
        'default_moq' => $supplier['default_moq'] ?? null,
        'description' => (string)($supplier['description'] ?? ''),
        'status' => 'active',
        'position' => $i + 1,
        'brand_ids' => $brandIds,
    ];
    $saved = $supplierAdmin->save($websiteId, $payload);
    $linkCount += count($brandIds);
    if (isset($existingSuppliers[$code])) {
        $updatedSuppliers++;
    } else {
        $createdSuppliers++;
    }
}

echo json_encode([
    'website_id' => $websiteId,
    'brands_total_seed' => count($brands),
    'brands_created_or_saved' => $createdBrands + $updatedBrands,
    'suppliers_total_seed' => count($suppliers),
    'suppliers_created' => $createdSuppliers,
    'suppliers_updated' => $updatedSuppliers,
    'brand_link_assignments' => $linkCount,
    'note' => '电话/邮箱仅填公开核实字段；店铺仅为官网或 1688 工厂黄页 title 核验入口。存量检索档口请跑 enrich-hanfu-real-stores.php。',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
