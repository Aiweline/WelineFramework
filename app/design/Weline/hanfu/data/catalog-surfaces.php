<?php

declare(strict_types=1);

/**
 * Hanfu design-theme catalog surface copy (website/theme concept — not Product module).
 *
 * @return array{
 *     share_image:string,
 *     surfaces:array<string, array{zh:array<string,string>, en:array<string,string>}>
 * }
 */
return [
    'share_image' => '/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp',
    'surfaces' => [
        'products' => [
            'zh' => [
                'title' => '商品列表',
                'heading' => '全部商品',
                'lede' => '浏览当前网站已发布的汉服、马面裙与传统配饰，可按价格与名称排序。',
                'seo_title' => '汉服商城 | 明制、宋制、唐制与马面裙',
                'seo_description' => '浏览全系列汉服，选购明制、宋制、唐制汉服、马面裙与传统配饰，支持国际独立站多语言浏览。',
                'seo_keywords' => '汉服,明制汉服,宋制汉服,唐制汉服,马面裙,传统配饰',
                'share_image_alt' => '桃园清梦米白粉色明制上衣与马面裙套装',
            ],
            'en' => [
                'title' => 'Hanfu Shop',
                'heading' => 'Shop All Hanfu',
                'lede' => 'Explore published Hanfu, mamian skirts, and traditional accessories, with price and name sorting.',
                'seo_title' => 'Shop All Hanfu | Ming & Tang',
                'seo_description' => 'Shop Ming, Song, and Tang dynasty Hanfu, mamian skirts, and traditional accessories with international storefront support.',
                'seo_keywords' => 'hanfu,ming hanfu,song hanfu,tang hanfu,mamian skirt,traditional accessories',
                'share_image_alt' => 'Peach Garden Dream ivory-and-pink Ming-style top and mamian set',
            ],
        ],
        'categories' => [
            'zh' => [
                'title' => '汉服分类',
                'heading' => '按形制探索汉服',
                'lede' => '从明制、宋制、唐制、马面裙与传统配饰中，找到适合日常、节庆与礼仪的款式。',
                'seo_title' => '汉服分类 | 按朝代、形制与场景选购',
                'seo_description' => '按朝代、形制与穿着场景探索明制、宋制、唐制汉服、马面裙和传统配饰。',
                'seo_keywords' => '汉服分类,明制,宋制,唐制,马面裙',
                'share_image_alt' => '桃园清梦米白粉色明制上衣与马面裙套装',
            ],
            'en' => [
                'title' => 'Hanfu Categories',
                'heading' => 'Explore Hanfu by Style',
                'lede' => 'Browse Ming, Song, and Tang styles, mamian skirts, and accessories for everyday wear, festivals, and ceremonies.',
                'seo_title' => 'Hanfu Categories | By Style',
                'seo_description' => 'Explore Ming, Song, and Tang dynasty Hanfu, mamian skirts, and accessories by style and occasion.',
                'seo_keywords' => 'hanfu categories,ming style,song style,tang style,mamian skirt',
                'share_image_alt' => 'Peach Garden Dream ivory-and-pink Ming-style top and mamian set',
            ],
        ],
        'new_arrivals' => [
            'zh' => [
                'title' => '新品上架',
                'heading' => '新到汉服',
                'lede' => '按上架时间探索最新汉服、马面裙与传统配饰，发现当季东方衣冠新作。',
                'seo_title' => '汉服新品 | 最新汉服、马面裙与传统配饰',
                'seo_description' => '探索新品上架，选购最新汉服、马面裙与传统配饰。',
                'seo_keywords' => '汉服新品,新到汉服,马面裙,传统配饰',
                'share_image_alt' => '桃园清梦米白粉色明制上衣与马面裙套装',
            ],
            'en' => [
                'title' => 'New Arrivals',
                'heading' => 'New Hanfu Arrivals',
                'lede' => 'Explore the latest Hanfu, mamian skirts, and traditional accessories in arrival order.',
                'seo_title' => 'New Hanfu Arrivals',
                'seo_description' => 'Discover the latest Hanfu, mamian skirts, and traditional accessories.',
                'seo_keywords' => 'new hanfu,new arrivals,mamian skirt,traditional accessories',
                'share_image_alt' => 'Peach Garden Dream ivory-and-pink Ming-style top and mamian set',
            ],
        ],
        'best_sellers' => [
            'zh' => [
                'title' => '热销榜',
                'heading' => '人气汉服榜',
                'lede' => '查看当前最受欢迎的汉服、马面裙与传统配饰，快速发现店铺口碑之选。',
                'seo_title' => '热销汉服榜 | 人气汉服与马面裙推荐',
                'seo_description' => '探索热销榜，发现人气汉服、马面裙与传统配饰。',
                'seo_keywords' => '热销汉服,人气汉服,马面裙,汉服榜单',
                'share_image_alt' => '桃园清梦米白粉色明制上衣与马面裙套装',
            ],
            'en' => [
                'title' => 'Best Sellers',
                'heading' => 'Most-Loved Hanfu',
                'lede' => 'Discover the Hanfu, mamian skirts, and traditional accessories our customers love most.',
                'seo_title' => 'Best-Selling Hanfu',
                'seo_description' => 'Discover best-selling Hanfu, mamian skirts, and traditional accessories.',
                'seo_keywords' => 'best selling hanfu,popular hanfu,mamian skirt',
                'share_image_alt' => 'Peach Garden Dream ivory-and-pink Ming-style top and mamian set',
            ],
        ],
    ],
];
