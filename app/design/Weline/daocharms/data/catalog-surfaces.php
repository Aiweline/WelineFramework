<?php

declare(strict_types=1);

/**
 * DaoCharms design-theme catalog surface copy + listing presentation.
 *
 * @return array{
 *     share_image:string,
 *     surfaces:array<string, array{zh:array<string,string>, en:array<string,string>}>,
 *     presentation:array<string, array<string, mixed>>
 * }
 */
return [
    'share_image' => '',
    'surfaces' => [
        'products' => [
            'zh' => [
                'title' => '仪式物件',
                'heading' => '为更安静的日常准备的物件。',
                'lede' => '现代生活的天然象征。每一件都是提醒：慢一点，回到中心，带着更多意图走过一天。',
                'seo_title' => '仪式物件 | DaoCharms',
                'seo_description' => '选购 DaoCharms 仪式物件：天然石与木质吊坠、香器与磬铃，以及安静的居家器物。',
                'seo_keywords' => '仪式物件,吊坠,无为,道家,DaoCharms',
                'share_image_alt' => 'DaoCharms 仪式物件',
            ],
            'en' => [
                'title' => 'Ritual Objects',
                'heading' => 'Objects for a quieter everyday.',
                'lede' => 'Natural symbols for modern life. Each piece is a reminder to slow down, find your center, and move through the day with greater intention.',
                'seo_title' => 'Objects for a quieter everyday. | DaoCharms',
                'seo_description' => 'Shop DaoCharms ritual objects: natural-stone pendants, incense and bells, and quiet home pieces.',
                'seo_keywords' => 'ritual objects,pendants,wu wei,dao,DaoCharms',
                'share_image_alt' => 'DaoCharms ritual objects',
            ],
        ],
        'categories' => [
            'zh' => [
                'title' => '物件分类',
                'heading' => '按系列探索',
                'lede' => '从可佩戴仪式、无为空间、居家器物与居服中，找到适合日常节奏的物件。',
                'seo_title' => '物件分类 | DaoCharms',
                'seo_description' => '按系列探索 DaoCharms：可佩戴仪式、无为空间、居家器物与居服。',
                'seo_keywords' => '分类,吊坠,香器,居服,DaoCharms',
                'share_image_alt' => 'DaoCharms 物件分类',
            ],
            'en' => [
                'title' => 'Collections',
                'heading' => 'Explore by Collection',
                'lede' => 'Browse Wearable Rituals, Wu Wei Sanctuary, home objects, and lounge wear for quieter everyday rituals.',
                'seo_title' => 'Collections | DaoCharms',
                'seo_description' => 'Explore DaoCharms by collection: wearable rituals, sanctuary objects, home pieces, and lounge wear.',
                'seo_keywords' => 'collections,pendants,incense,lounge wear,DaoCharms',
                'share_image_alt' => 'DaoCharms collections',
            ],
        ],
        'new_arrivals' => [
            'zh' => [
                'title' => '新品上架',
                'heading' => '为日常新添一件。',
                'lede' => '近期上架的仪式物件。',
                'seo_title' => '新品上架 | DaoCharms',
                'seo_description' => '探索 DaoCharms 新品：最新吊坠、香器与居家器物。',
                'seo_keywords' => '新品,仪式物件,DaoCharms',
                'share_image_alt' => 'DaoCharms 新品',
            ],
            'en' => [
                'title' => 'New Arrivals',
                'heading' => 'New to your everyday.',
                'lede' => 'Recently added ritual objects.',
                'seo_title' => 'New Arrivals | DaoCharms',
                'seo_description' => 'Discover new DaoCharms pendants, incense pieces, and quiet home objects.',
                'seo_keywords' => 'new arrivals,ritual objects,DaoCharms',
                'share_image_alt' => 'DaoCharms new arrivals',
            ],
        ],
        'best_sellers' => [
            'zh' => [
                'title' => '热销榜',
                'heading' => '人气物件',
                'lede' => '查看当前最受欢迎的仪式物件，快速发现店铺口碑之选。',
                'seo_title' => '热销 | DaoCharms',
                'seo_description' => '探索 DaoCharms 热销仪式物件与吊坠。',
                'seo_keywords' => '热销,人气物件,DaoCharms',
                'share_image_alt' => 'DaoCharms 热销物件',
            ],
            'en' => [
                'title' => 'Best Sellers',
                'heading' => 'Most-Loved Objects',
                'lede' => 'Discover the ritual objects our customers return to most.',
                'seo_title' => 'Best Sellers | DaoCharms',
                'seo_description' => 'Discover best-selling DaoCharms ritual objects and pendants.',
                'seo_keywords' => 'best sellers,ritual objects,DaoCharms',
                'share_image_alt' => 'DaoCharms best sellers',
            ],
        ],
    ],
    'presentation' => [
        'new_arrivals' => [
            'card_limit' => 3,
            'show_filters' => false,
            'show_toolbar' => false,
            'show_recommendations' => false,
            /* UI 04 图序（主题模板按 URL/名匹配；此处作权威注释与后续控制器可消费） */
            'preferred_url_needles' => ['ruyi', 'bagua', 'yinyang'],
        ],
    ],
];
