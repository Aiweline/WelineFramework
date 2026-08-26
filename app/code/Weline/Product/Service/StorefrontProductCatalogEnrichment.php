<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Storefront catalog enrichment: attribute sets, spec attributes, descriptions and galleries.
 */
final class StorefrontProductCatalogEnrichment
{
    /** @var array<string, array{code:string,label:string}> */
    public const ATTRIBUTE_SETS = [
        'phone' => ['code' => 'phone', 'label' => '手机数码'],
        'air_purifier' => ['code' => 'air_purifier', 'label' => '空气净化'],
        'office_gear' => ['code' => 'office_gear', 'label' => '办公外设'],
        'home_cleaning' => ['code' => 'home_cleaning', 'label' => '清洁家电'],
        'home_textile' => ['code' => 'home_textile', 'label' => '家居软装'],
        'smart_home' => ['code' => 'smart_home', 'label' => '智能家居'],
        'consumable' => ['code' => 'consumable', 'label' => '日用消耗'],
    ];

    /**
     * @return array{
     *     attribute_set:string,
     *     description:string,
     *     attributes:array<string,string>,
     *     gallery:list<string>
     * }|null
     */
    public static function forSku(string $sku): ?array
    {
        return self::CATALOG[$sku] ?? null;
    }

    /** @var array<string, array{attribute_set:string,description:string,attributes:array<string,string>,gallery:list<string>}> */
    private const CATALOG = [
        'WEB-REDMI-TURBO4-16-256' => [
            'attribute_set' => 'phone',
            'description' => "Redmi Turbo 4 定位千元档性能机，搭载天玑8400-Ultra 芯片，安兔兔跑分可达 180 万+。\n6550mAh 大电池配合 67W 快充，日常通勤可两天一充。\n16GB+256GB 存储组合适合游戏与影像缓存，参考 2026 年电商到手价约 1499 元。",
            'attributes' => [
                'brand' => 'Redmi',
                'model' => 'Turbo 4',
                'color' => '浅海青',
                'storage' => '16GB+256GB',
                'chipset' => '天玑8400-Ultra',
                'battery' => '6550mAh',
                'charging' => '67W 快充',
                'network' => '5G 双卡',
                'warranty_months' => '12',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?w=640&h=640&fit=crop',
                'https://images.unsplash.com/photo-1565849906261-5a4c496227a9?w=640&h=640&fit=crop',
            ],
        ],
        'WEB-HONOR-PLAY10' => [
            'attribute_set' => 'phone',
            'description' => "荣耀 Play10 面向均衡护眼与长续航场景，6000mAh 电池配合天玑7200，适合学生与长辈用户。\n35W 充电、线下渠道覆盖广，售后便利。参考售价约 1399 元。",
            'attributes' => [
                'brand' => '荣耀',
                'model' => 'Play10',
                'color' => '幻夜黑',
                'storage' => '8GB+256GB',
                'chipset' => '天玑7200',
                'battery' => '6000mAh',
                'charging' => '35W',
                'network' => '5G',
                'warranty_months' => '12',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1592899677977-9b10ca588fab?w=640&h=640&fit=crop',
            ],
        ],
        'WEB-IQOO-Z11I' => [
            'attribute_set' => 'phone',
            'description' => "iQOO Z11i 主打超大电池与低价，7000mAh 容量适合备用机、外卖骑手等重度续航需求。\n骁龙685 平台满足微信、短视频与通话，参考价约 1019 元。",
            'attributes' => [
                'brand' => 'iQOO',
                'model' => 'Z11i',
                'color' => '星岩灰',
                'storage' => '8GB+128GB',
                'chipset' => '骁龙685',
                'battery' => '7000mAh',
                'charging' => '18W',
                'network' => '4G',
                'warranty_months' => '12',
            ],
            'gallery' => [],
        ],
        'WEB-MIJIA-AIR-6PRO' => [
            'attribute_set' => 'air_purifier',
            'description' => "米家空气净化器 6 Pro 升级双芯双架构与复合净化矩阵，适合 30-50㎡ 客厅卧室。\n2026 年国补叠券后常见到手价 1800-1900 元，发售价 2399 元。",
            'attributes' => [
                'brand' => '米家',
                'model' => '空气净化器 6 Pro',
                'coverage_area' => '30-50㎡',
                'filter_type' => 'HEPA 复合滤芯',
                'power' => '38W',
                'noise_level' => '32-64dB',
                'smart_control' => '米家 App',
                'warranty_months' => '12',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1605000796989-c3e7684c9a65?w=640&h=640&fit=crop',
            ],
        ],
        'WEB-TREEFRESH-T2PRO' => [
            'attribute_set' => 'air_purifier',
            'description' => "树新风 T2 Pro 面向新房除醛，催化分解方案性能衰减低，适合刚装修家庭。\n参考价约 2199 元，甲醛 CADR 与长效分解是核心卖点。",
            'attributes' => [
                'brand' => '树新风',
                'model' => 'T2 Pro',
                'coverage_area' => '40-60㎡',
                'filter_type' => '催化分解',
                'formaldehyde_cadr' => '400m³/h+',
                'power' => '45W',
                'smart_control' => 'App + 触控',
                'warranty_months' => '24',
            ],
            'gallery' => [],
        ],
        'WEB-BELKIN-TB4-DOCK' => [
            'attribute_set' => 'office_gear',
            'description' => "贝尔金 12 合 1 雷电 4 扩展坞，支持多显示器、USB、SD 读卡与 PD 供电。\n适合 MacBook / 轻薄本桌面一站扩展，618 参考价约 899 元。",
            'attributes' => [
                'brand' => 'Belkin',
                'model' => 'Thunderbolt 4 Dock 12-in-1',
                'connectivity' => 'Thunderbolt 4 / USB-C',
                'ports' => '12 口',
                'power_delivery' => '90W PD',
                'compatibility' => 'Mac / Windows 轻薄本',
                'warranty_months' => '24',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?w=640&h=640&fit=crop',
            ],
        ],
        'WEB-BENQ-SCREENBAR' => [
            'attribute_set' => 'office_gear',
            'description' => "明基 ScreenBar 屏幕挂灯减少屏幕反光与桌面阴影，适合程序员、设计师长时间办公。\n参考价约 599 元，挂屏式设计不占用桌面空间。",
            'attributes' => [
                'brand' => 'BenQ',
                'model' => 'ScreenBar',
                'color' => '深空灰',
                'power' => '5W USB 供电',
                'compatibility' => '17-34 英寸显示器',
                'dimming' => '自动调光',
                'warranty_months' => '12',
            ],
            'gallery' => [],
        ],
        'WEB-LOGITECH-MX3S' => [
            'attribute_set' => 'office_gear',
            'description' => "罗技 MX Master 3S 旗舰办公鼠标，静音微动、MagSpeed 滚轮，支持 Logi Bolt 多设备切换。\n人体工学造型适合右手长时间使用，参考价约 699 元。",
            'attributes' => [
                'brand' => 'Logitech',
                'model' => 'MX Master 3S',
                'color' => '石墨黑',
                'connectivity' => '蓝牙 + Logi Bolt',
                'dpi' => '8000',
                'battery' => '70 天续航',
                'warranty_months' => '12',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1615667243544-447c04d8f585?w=640&h=640&fit=crop',
            ],
        ],
        'WEB-DYSON-V15' => [
            'attribute_set' => 'home_cleaning',
            'description' => "戴森 V15 Detect 无线吸尘器，激光显尘与 Hyperdymium 马达，适合地毯、硬地板与床褥除螨。\n高端无线清洁代表机型，参考价约 4990 元。",
            'attributes' => [
                'brand' => 'Dyson',
                'model' => 'V15 Detect',
                'color' => '镍蓝',
                'runtime' => '最长 60 分钟',
                'suction' => '240AW',
                'weight_kg' => '3.0',
                'accessories' => '激光软绒吸头+除螨吸头',
                'warranty_months' => '24',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1581578731548-c64695cc6952?w=640&h=640&fit=crop',
            ],
        ],
        'WEB-LIBY-LAUNDRY-3KG' => [
            'attribute_set' => 'consumable',
            'description' => "立白天然茶籽除菌洗衣液 3kg 家庭装，茶籽精华去渍除菌，适合日常机洗。\n大促组合装参考价约 129 元，高频复购消耗品。",
            'attributes' => [
                'brand' => '立白',
                'model' => '天然茶籽除菌',
                'volume' => '3kg',
                'scent' => '自然清香',
                'suitable_for' => '棉麻及合成纤维',
                'shelf_life' => '3 年',
            ],
            'gallery' => [],
        ],
        'WEB-QINGSHAN-RUG' => [
            'attribute_set' => 'home_textile',
            'description' => "青山美宿极简侘寂风羊毛地毯，新西兰羊毛混纺，低饱和大地色系适配北欧/日式客厅。\n多尺寸可选，参考价约 699 元，底部防滑乳胶底。",
            'attributes' => [
                'brand' => '青山美宿',
                'material' => '新西兰羊毛混纺',
                'color' => '燕麦色',
                'dimensions' => '160×230cm',
                'weight_kg' => '8.5',
                'care_instructions' => '定期吸尘，局部干洗',
                'warranty_months' => '12',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?w=640&h=640&fit=crop',
            ],
        ],
        'WEB-MIJIA-LOCK-E30' => [
            'attribute_set' => 'smart_home',
            'description' => "小米智能门锁 E30 支持指纹、密码、NFC 与 App 远程管理，适合存量房免布线改造。\n2026 智能门锁热销品类，参考价约 1299 元。",
            'attributes' => [
                'brand' => '小米',
                'model' => '智能门锁 E30',
                'color' => '碳黑',
                'unlock_methods' => '指纹/密码/NFC/App',
                'battery' => '8 号电池×8',
                'material' => '锌合金',
                'warranty_months' => '36',
            ],
            'gallery' => [
                'https://images.unsplash.com/photo-1560185127-6ed189bf02f4?w=640&h=640&fit=crop',
            ],
        ],
    ];
}
