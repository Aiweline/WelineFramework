<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * 主题编辑器与部件预览用的默认可视化样例数据。
 */
final class ThemeDemoCatalog
{
    /** @var list<string> */
    private const PRODUCT_NAMES = [
        '极简陶瓷马克杯',
        '无线降噪耳机 Pro',
        '轻量缓震跑鞋',
        '天然棉质圆领 T 恤',
        '便携蓝牙音箱',
        '实木桌面收纳盒',
        '智能温显保温杯',
        '经典真皮双肩包',
        '高清运动相机',
        '柔光护眼台灯',
        '多功能料理机',
        '云感记忆枕',
    ];

    /** @var list<array{from:string,to:string,accent:string}> */
    private const IMAGE_PALETTES = [
        ['from' => '#fff7ed', 'to' => '#fed7aa', 'accent' => '#fb923c'],
        ['from' => '#eff6ff', 'to' => '#bfdbfe', 'accent' => '#3b82f6'],
        ['from' => '#ecfdf5', 'to' => '#a7f3d0', 'accent' => '#10b981'],
        ['from' => '#fdf4ff', 'to' => '#f5d0fe', 'accent' => '#d946ef'],
        ['from' => '#fef2f2', 'to' => '#fecaca', 'accent' => '#ef4444'],
        ['from' => '#f8fafc', 'to' => '#cbd5e1', 'accent' => '#64748b'],
    ];

    /** @var list<array{name:string,icon:string,count:int}> */
    private const CATEGORIES = [
        ['name' => '数码电子', 'icon' => 'bolt', 'count' => 156],
        ['name' => '家居生活', 'icon' => 'box', 'count' => 98],
        ['name' => '运动户外', 'icon' => 'circle', 'count' => 74],
    ];

    public static function productImage(int $index): string
    {
        $palette = self::IMAGE_PALETTES[abs($index) % count(self::IMAGE_PALETTES)];
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="640" viewBox="0 0 640 640">'
            . '<defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1">'
            . '<stop stop-color="%s"/><stop offset="1" stop-color="%s"/></linearGradient></defs>'
            . '<rect width="640" height="640" fill="url(#g)"/>'
            . '<rect x="170" y="200" width="300" height="240" rx="32" fill="%s" opacity="0.9"/>'
            . '<circle cx="320" cy="170" r="52" fill="%s" opacity="0.35"/>'
            . '</svg>',
            $palette['from'],
            $palette['to'],
            $palette['accent'],
            $palette['accent'],
        );

        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
    }

    /**
     * @return list<array{
     *     id:int,
     *     name:string,
     *     url:string,
     *     image:string,
     *     price:float,
     *     original_price:float,
     *     rating:float,
     *     review_count:int
     * }>
     */
    public static function products(int $limit = 8, int $seed = 0): array
    {
        $limit = max(1, min(24, $limit));
        $products = [];

        for ($i = 0; $i < $limit; $i++) {
            $n = $i + 1 + max(0, $seed);
            $price = 89.0 + (($n * 47) % 520);
            $original = round($price * (1.08 + (($n % 4) * 0.04)), 2);
            $nameIndex = ($i + $seed) % count(self::PRODUCT_NAMES);

            $products[] = [
                'id' => $n,
                'name' => self::PRODUCT_NAMES[$nameIndex],
                'url' => '/product/demo-' . $n,
                'image' => self::productImage($i + $seed),
                'price' => $price,
                'original_price' => $original,
                'rating' => min(5.0, 4.2 + (($n % 5) * 0.15)),
                'review_count' => 12 + ($n * 23),
            ];
        }

        return $products;
    }

    /**
     * @return list<array{name:string,icon:string,count:int}>
     */
    public static function categories(int $limit = 3): array
    {
        return array_slice(self::CATEGORIES, 0, max(1, min(count(self::CATEGORIES), $limit)));
    }

    public static function formatPrice(float $amount): string
    {
        return '¥' . number_format($amount, 2);
    }
}
