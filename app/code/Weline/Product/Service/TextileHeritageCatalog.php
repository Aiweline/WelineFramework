<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Default 织艺谱系 / Textile Heritage entries for the Product-owned homepage widget.
 *
 * Image paths are module static assets; links use the storefront /search?q= protocol.
 */
final class TextileHeritageCatalog
{
    public const TITLE = '织艺谱系 · Textile Heritage';

    public const IMAGE_BASE = '/Weline/Product/view/statics/images/textile-heritage';

    /**
     * @return list<array{name:string,caption:string,caption_en:string,image:string,link:string,slug:string}>
     */
    public static function items(): array
    {
        $base = self::IMAGE_BASE;

        return [
            [
                'slug' => 'yunjin',
                'name' => '云锦',
                'caption' => '云锦',
                'caption_en' => 'Nanjing Yunjin',
                'image' => $base . '/yunjin.svg',
                'link' => '/search?q=' . rawurlencode('云锦'),
            ],
            [
                'slug' => 'songjin',
                'name' => '宋锦',
                'caption' => '宋锦',
                'caption_en' => 'Suzhou Song Brocade',
                'image' => $base . '/songjin.svg',
                'link' => '/search?q=' . rawurlencode('宋锦'),
            ],
            [
                'slug' => 'shujin',
                'name' => '蜀锦',
                'caption' => '蜀锦',
                'caption_en' => 'Chengdu Shu Brocade',
                'image' => $base . '/shujin.svg',
                'link' => '/search?q=' . rawurlencode('蜀锦'),
            ],
            [
                'slug' => 'suxiu',
                'name' => '苏绣',
                'caption' => '苏绣',
                'caption_en' => 'Suzhou Embroidery',
                'image' => $base . '/suxiu.svg',
                'link' => '/search?q=' . rawurlencode('苏绣'),
            ],
            [
                'slug' => 'zhuanghua',
                'name' => '妆花',
                'caption' => '妆花',
                'caption_en' => 'Brocaded Weave',
                'image' => $base . '/zhuanghua.svg',
                'link' => '/search?q=' . rawurlencode('妆花'),
            ],
            [
                'slug' => 'hualuo',
                'name' => '花罗',
                'caption' => '花罗',
                'caption_en' => 'Patterned Gauze',
                'image' => $base . '/hualuo.svg',
                'link' => '/search?q=' . rawurlencode('花罗'),
            ],
        ];
    }

    /**
     * Widget param / default_injection config shape (name, caption, image, link).
     *
     * @return list<array{name:string,caption:string,image:string,link:string}>
     */
    public static function brandsConfig(): array
    {
        $out = [];
        foreach (self::items() as $item) {
            $out[] = [
                'name' => $item['name'],
                'caption' => $item['caption'],
                'image' => $item['image'],
                'link' => $item['link'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function widgetConfig(): array
    {
        return [
            'title' => self::TITLE,
            'brands' => self::brandsConfig(),
            'columns' => '6',
            'layout' => 'grid',
            'grayscale' => false,
        ];
    }
}
