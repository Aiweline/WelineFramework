<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Canonical data for the reusable Textile Heritage widget.
 *
 * Every image is a real museum object or a photographed woven sample. Source,
 * object and licence details stay with the item so provenance is never inferred
 * from a filename.
 */
final class TextileHeritageCatalog
{
    public const TITLE = '织艺谱系 · Textile Heritage';

    public const IMAGE_BASE = '/Weline/Theme/view/statics/images/textile-heritage';

    /**
     * @return list<array{
     *     slug:string,name:string,caption:string,caption_en:string,image:string,link:string,
     *     object_title:string,object_title_en:string,creator:string,creator_en:string,
     *     collection:string,source_url:string,license:string,license_url:string,evidence_url:string
     * }>
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
                'image' => $base . '/yunjin.jpg',
                'link' => '/search?q=' . rawurlencode('云锦'),
                'object_title' => '清乾隆黄地龙袍（云锦织造）',
                'object_title_en' => 'Qing Qianlong yellow-ground dragon robe (Yunjin weaving)',
                'creator' => 'Dr. Meierhofer',
                'creator_en' => 'Dr. Meierhofer',
                'collection' => 'Grassi Museum, Leipzig',
                'source_url' => 'https://commons.wikimedia.org/wiki/File:Drachenrobe-Qianlong.JPG',
                'license' => 'CC BY-SA 3.0',
                'license_url' => 'https://creativecommons.org/licenses/by-sa/3.0/',
                'evidence_url' => 'https://commons.wikimedia.org/wiki/Category:Nanjing_brocade',
            ],
            [
                'slug' => 'songjin',
                'name' => '宋锦',
                'caption' => '宋锦',
                'caption_en' => 'Suzhou Song Brocade',
                'image' => $base . '/songjin.png',
                'link' => '/search?q=' . rawurlencode('宋锦'),
                'object_title' => '银线嵌入宋锦实物织样（论文图 13）',
                'object_title_en' => 'Song brocade sample with silver-thread inlay (paper fig. 13)',
                'creator' => 'Xiuling Zhang et al.',
                'creator_en' => 'Xiuling Zhang et al.',
                'collection' => 'Materials 14 (2021), 3779',
                'source_url' => 'https://www.mdpi.com/1996-1944/14/14/3779',
                'license' => 'CC BY 4.0',
                'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
                'evidence_url' => 'https://doi.org/10.3390/ma14143779',
            ],
            [
                'slug' => 'shujin',
                'name' => '蜀锦',
                'caption' => '蜀锦',
                'caption_en' => 'Chengdu Shu Brocade',
                'image' => $base . '/shujin.jpg',
                'link' => '/search?q=' . rawurlencode('蜀锦'),
                'object_title' => '唐代联珠对鸟纹蜀锦',
                'object_title_en' => 'Tang dynasty Shu brocade with pearl roundel and paired birds',
                'creator' => 'Unknown',
                'creator_en' => 'Unknown',
                'collection' => 'Chengdu Museum',
                'source_url' => 'https://commons.wikimedia.org/wiki/File:Shu_brocade,_Chengdu_Museum.png',
                'license' => 'Public Domain Mark 1.0',
                'license_url' => 'https://creativecommons.org/publicdomain/mark/1.0/',
                'evidence_url' => 'https://www.cdmuseum.com/xinwen/202501/3869.html',
            ],
            [
                'slug' => 'suxiu',
                'name' => '苏绣',
                'caption' => '苏绣',
                'caption_en' => 'Suzhou Embroidery',
                'image' => $base . '/suxiu.jpg',
                'link' => '/search?q=' . rawurlencode('苏绣'),
                'object_title' => '清代苏绣《灵仙祝寿图》',
                'object_title_en' => 'Qing dynasty Suzhou embroidery “Immortals Celebrating Longevity”',
                'creator' => 'Unknown',
                'creator_en' => 'Unknown',
                'collection' => 'Shanghai Museum',
                'source_url' => 'https://commons.wikimedia.org/wiki/File:苏绣灵仙祝寿图.jpg',
                'license' => 'Public Domain Mark 1.0',
                'license_url' => 'https://creativecommons.org/publicdomain/mark/1.0/',
                'evidence_url' => 'https://www.shanghaimuseum.net/mu/frontend/pg/article/id/CI00004895',
            ],
            [
                'slug' => 'zhuanghua',
                'name' => '妆花',
                'caption' => '妆花',
                'caption_en' => 'Zhuanghua Brocade',
                'image' => $base . '/zhuanghua.jpg',
                'link' => '/search?q=' . rawurlencode('妆花'),
                'object_title' => '明早期缠枝莲托八宝凤鸟纹妆花缎',
                'object_title_en' => 'Early Ming zhuanghua satin with lotus scrolls, Eight Treasures and phoenix-bird motifs',
                'creator' => 'Unknown',
                'creator_en' => 'Unknown',
                'collection' => 'The Metropolitan Museum of Art, 2001.471',
                'source_url' => 'https://www.metmuseum.org/art/collection/search/62477',
                'license' => 'CC0 1.0',
                'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
                'evidence_url' => 'https://collectionapi.metmuseum.org/public/collection/v1/objects/62477',
            ],
            [
                'slug' => 'hualuo',
                'name' => '花罗',
                'caption' => '花罗',
                'caption_en' => 'Patterned Gauze',
                'image' => $base . '/hualuo.jpg',
                'link' => '/search?q=' . rawurlencode('花罗'),
                'object_title' => '南宋黄褐色如意山茶暗花罗',
                'object_title_en' => 'Southern Song yellowish-brown patterned gauze with ruyi camellia motif',
                'creator' => '三猎',
                'creator_en' => 'Sanlie',
                'collection' => 'China National Silk Museum',
                'source_url' => 'https://commons.wikimedia.org/wiki/File:南宋黄褐色如意山茶暗花罗.jpg',
                'license' => 'CC BY-SA 4.0',
                'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
                'evidence_url' => 'https://chinasilkmuseum.com/zggd/info_21.aspx?itemid=2405',
            ],
        ];
    }

    /** @return list<array<string, string>> */
    public static function brandsConfig(): array
    {
        return self::items();
    }

    /** @return array<string, mixed> */
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
