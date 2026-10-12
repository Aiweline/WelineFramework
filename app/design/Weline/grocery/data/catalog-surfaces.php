<?php

declare(strict_types=1);

/**
 * Grocery design-theme catalog surface copy (website/theme concept — not Product module).
 *
 * @return array{
 *     share_image:string,
 *     surfaces:array<string, array{zh:array<string,string>, en:array<string,string>}>
 * }
 */
return [
    'share_image' => '',
    'surfaces' => [
        'products' => [
            'zh' => [
                'title' => '货架商品',
                'heading' => '全部货架',
                'lede' => '浏览邻里杂货铺已发布的生鲜蔬果、乳品烘焙、粮油调味、零食饮料与日用清洁，可按价格与名称排序。',
                'seo_title' => '邻里货架 | 生鲜日用杂货',
                'seo_description' => '逛邻里杂货铺货架：新鲜蔬果、乳品烘焙、粮油调味、零食饮料与日用清洁，货架到家更省心。',
                'seo_keywords' => '邻里杂货铺,生鲜蔬果,粮油调味,日用清洁,零食饮料',
                'share_image_alt' => '邻里杂货铺货架',
            ],
            'en' => [
                'title' => 'Shelf Goods',
                'heading' => 'Shop the Shelf',
                'lede' => 'Browse published produce, dairy, pantry staples, snacks, and household essentials — sort by price or name.',
                'seo_title' => 'Neighborhood Shelf | Daily Grocery',
                'seo_description' => 'Shop Neighborhood Grocery: fresh produce, dairy, pantry staples, snacks, and household essentials.',
                'seo_keywords' => 'neighborhood grocery,produce,pantry,snacks,household',
                'share_image_alt' => 'Neighborhood grocery shelf',
            ],
        ],
        'categories' => [
            'zh' => [
                'title' => '货架分类',
                'heading' => '按货架探索',
                'lede' => '从生鲜蔬果、乳品烘焙、粮油调味、零食饮料与日用清洁中，找到今日需要的邻里好物。',
                'seo_title' => '货架分类 | 邻里杂货铺',
                'seo_description' => '按货架分类逛邻里杂货铺：生鲜、乳品、粮油、零食与日用清洁。',
                'seo_keywords' => '货架分类,生鲜,粮油,日用,邻里杂货铺',
                'share_image_alt' => '邻里杂货铺分类',
            ],
            'en' => [
                'title' => 'Shelf Categories',
                'heading' => 'Explore by Aisle',
                'lede' => 'Browse produce, dairy & bakery, pantry, snacks & drinks, and household essentials.',
                'seo_title' => 'Aisles | Neighborhood Grocery',
                'seo_description' => 'Explore Neighborhood Grocery by aisle: produce, dairy, pantry, snacks, and household.',
                'seo_keywords' => 'grocery aisles,produce,pantry,household',
                'share_image_alt' => 'Neighborhood grocery aisles',
            ],
        ],
        'new_arrivals' => [
            'zh' => [
                'title' => '货架上新',
                'heading' => '新到货架',
                'lede' => '按上架时间探索最新邻里日用与生鲜好物。',
                'seo_title' => '货架上新 | 邻里杂货铺',
                'seo_description' => '探索邻里杂货铺新品：新鲜蔬果、乳品与日用补给。',
                'seo_keywords' => '货架上新,邻里杂货,日用',
                'share_image_alt' => '邻里杂货铺上新',
            ],
            'en' => [
                'title' => 'New on the Shelf',
                'heading' => 'New Arrivals',
                'lede' => 'Explore the latest neighborhood grocery picks in arrival order.',
                'seo_title' => 'New Arrivals | Neighborhood Grocery',
                'seo_description' => 'Discover new produce, dairy, and household staples on the neighborhood shelf.',
                'seo_keywords' => 'new grocery,new arrivals,neighborhood shelf',
                'share_image_alt' => 'New grocery arrivals',
            ],
        ],
        'best_sellers' => [
            'zh' => [
                'title' => '热销货架',
                'heading' => '邻里热买',
                'lede' => '查看当前最受欢迎的日用与生鲜好物。',
                'seo_title' => '热销 | 邻里杂货铺',
                'seo_description' => '探索邻里杂货铺热销货架与日用补给。',
                'seo_keywords' => '热销,邻里杂货,日用',
                'share_image_alt' => '邻里杂货铺热销',
            ],
            'en' => [
                'title' => 'Best Sellers',
                'heading' => 'Neighborhood Favorites',
                'lede' => 'Discover the grocery staples neighbors pick up most.',
                'seo_title' => 'Best Sellers | Neighborhood Grocery',
                'seo_description' => 'Discover best-selling neighborhood grocery staples and household essentials.',
                'seo_keywords' => 'best sellers,grocery,neighborhood',
                'share_image_alt' => 'Neighborhood grocery favorites',
            ],
        ],
    ],
];
