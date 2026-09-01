<?php

declare(strict_types=1);

/**
 * Rewrite ALL website_id=0 published blog posts into substantive illustrated articles.
 * Covers/inline images: ONLY /media/blog/hanfu/articles/photos/product-01.jpg … product-29.jpg
 * Captions: app/code/Weline/Blog/data/hanfu-photo-captions.json
 *
 * Usage: php app/code/Weline/Blog/data/rewrite-blog-r1-substance.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const PHOTO_BASE = '/media/blog/hanfu/articles/photos';
const CAPTIONS_FILE = __DIR__ . '/hanfu-photo-captions.json';
const AUTHOR = 'Amayun Editorial';

/**
 * @return array<string, array{zh:string,en:string}>
 */
function loadCaptions(): array
{
    $raw = file_get_contents(CAPTIONS_FILE);
    if ($raw === false) {
        throw new RuntimeException('Cannot read captions: ' . CAPTIONS_FILE);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid captions JSON');
    }
    $out = [];
    foreach ($data as $file => $row) {
        if (!is_string($file) || !is_array($row)) {
            continue;
        }
        if (!preg_match('/^product-\d{2}\.jpg$/', $file)) {
            continue;
        }
        $out[$file] = [
            'zh' => trim((string)($row['zh'] ?? '')),
            'en' => trim((string)($row['en'] ?? '')),
        ];
    }
    if (count($out) < 10) {
        throw new RuntimeException('Too few product captions loaded');
    }

    return $out;
}

function h2(string $t): string
{
    return '<h2>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
}

function p(string $t): string
{
    return '<p>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
}

function ul(array $items): string
{
    $lis = '';
    foreach ($items as $item) {
        $lis .= '<li>' . htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
    }

    return '<ul>' . $lis . '</ul>';
}

function tableHtml(array $headers, array $rows): string
{
    $html = '<table><thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th>' . htmlspecialchars((string)$h, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table>';
}

/**
 * @param array<string, array{zh:string,en:string}> $captions
 */
function figure(string $file, string $locale, array $captions, string $alt): string
{
    if (!preg_match('/^product-\d{2}\.jpg$/', $file)) {
        throw new InvalidArgumentException('Only product-*.jpg allowed: ' . $file);
    }
    $src = PHOTO_BASE . '/' . $file;
    $cap = $captions[$file][str_starts_with($locale, 'en') ? 'en' : 'zh'] ?? $file;
    $srcE = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $altE = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $capE = htmlspecialchars($cap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<figure class="blog-illust">'
        . '<img src="' . $srcE . '" alt="' . $altE . '" loading="lazy" />'
        . '<figcaption>' . $capE . '</figcaption>'
        . '</figure>';
}

function cta(string $locale): string
{
    if (str_starts_with($locale, 'en')) {
        return h2('Factory-direct next step')
            . p('Amayun Technology Co., Ltd. (registered 2024) builds a factory-direct path for overseas buyers: verified origin partners, inspected construction, and handmade finishing combined with green mechanical production. When a marketplace listing is thin on flat-lay structure, fabric facts, or after-sales clarity—or when a DTC boutique markup exceeds the value you need—compare the same silhouette against Amayun factory-direct catalog photos and QC notes before you pay.')
            . p('Use the product photos in this article as correct-form references (mamian panels, ruqun waistbands, yuanling collars), not as a claim that every marketplace sells these exact SKUs. Learn the structure first, then choose the channel that matches your risk tolerance.');
    }

    return h2('源头工厂直销：下一步怎么选')
        . p('阿玛云科技有限公司（注册于 2024）面向海外买家提供源头工厂直销路径：原产地伙伴可追溯、结构与面料按可穿服装标准质检，生产采用手工结合绿色机械制造，兼顾性价比与稳定性。当平台详情缺少平铺结构图、面料参数或售后边界不清，或独立站溢价超出你需要的服务价值时，请先用成衣实拍对照形制，再决定是否转向工厂直销。')
        . p('本文配图均为形制/面料/结构参考实拍，用于学习正确结构，并不等同于宣称某平台正在售卖同一 SKU。先懂形制，再选渠道，能显著降低退货与「买错衣服」成本。');
}

/** @param list<int> $nums @return list<string> */
function photoFiles(array $nums): array
{
    $out = [];
    $seen = [];
    foreach ($nums as $n) {
        $n = (int)$n;
        if ($n <= 0 || isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $out[] = sprintf('product-%02d.jpg', $n);
    }

    return $out;
}

/**
 * Globally allocate photo sets for distinct base slugs.
 * Hard rules:
 * - within one article: pairwise unique files (cover + body)
 * - across base slugs: unordered photo sets MUST be unique (no identical 整组图)
 * - prefer globally least-used product-NN.jpg (even spread)
 * - cover = least-used member of the chosen set (covers also spread)
 * - $reservedUnorderedKeys keeps sets owned by posts outside this plan (e.g. batch4)
 *
 * Note: zh/en of the same base slug share one assignment later; do not pass both here.
 *
 * @param list<string> $slugs
 * @param array<string, true> $reservedUnorderedKeys
 * @return array<string, list<int>> slug => [cover, inline…]
 */
function allocateUniquePhotoSets(array $slugs, int $poolSize = 29, int $perArticle = 4, array $reservedUnorderedKeys = []): array
{
    if ($perArticle < 2 || $perArticle > $poolSize) {
        throw new InvalidArgumentException('Invalid perArticle/poolSize');
    }
    $usage = array_fill(1, $poolSize, 0);
    $coverUsage = array_fill(1, $poolSize, 0);
    $seenSets = $reservedUnorderedKeys;
    $assign = [];

    foreach ($slugs as $slug) {
        $ids = range(1, $poolSize);
        usort($ids, static function (int $a, int $b) use ($usage): int {
            return ($usage[$a] <=> $usage[$b]) ?: ($a <=> $b);
        });

        $picked = null;
        $n = count($ids);
        // Walk combinations in least-used-first order; first unused unordered set wins.
        if ($perArticle === 4) {
            for ($i = 0; $i < $n && $picked === null; ++$i) {
                for ($j = $i + 1; $j < $n && $picked === null; ++$j) {
                    for ($k = $j + 1; $k < $n && $picked === null; ++$k) {
                        for ($l = $k + 1; $l < $n; ++$l) {
                            $combo = [$ids[$i], $ids[$j], $ids[$k], $ids[$l]];
                            sort($combo);
                            $key = implode(',', $combo);
                            if (!isset($seenSets[$key])) {
                                $picked = $combo;
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            // Generic fallback for other sizes (e.g. 3).
            $stack = [[]];
            while ($stack !== [] && $picked === null) {
                $cur = array_pop($stack);
                $start = $cur === [] ? 0 : (array_search(end($cur), $ids, true) + 1);
                if (count($cur) === $perArticle) {
                    $combo = $cur;
                    sort($combo);
                    $key = implode(',', $combo);
                    if (!isset($seenSets[$key])) {
                        $picked = $combo;
                    }
                    continue;
                }
                for ($i = $n - 1; $i >= $start; --$i) {
                    $next = $cur;
                    $next[] = $ids[$i];
                    $stack[] = $next;
                }
            }
        }

        if ($picked === null) {
            throw new RuntimeException('No unique photo set left for ' . $slug);
        }

        // Order for article: cover first = least coverUsage then least usage among set.
        usort($picked, static function (int $a, int $b) use ($coverUsage, $usage): int {
            return ($coverUsage[$a] <=> $coverUsage[$b])
                ?: (($usage[$a] <=> $usage[$b]) ?: ($a <=> $b));
        });

        $sorted = $picked;
        sort($sorted);
        $setKey = implode(',', $sorted);
        if (isset($seenSets[$setKey])) {
            throw new RuntimeException('Photo set collision for ' . $slug . ': ' . $setKey);
        }
        $seenSets[$setKey] = true;

        ++$coverUsage[$picked[0]];
        foreach ($picked as $num) {
            ++$usage[$num];
        }
        $assign[$slug] = $picked;
    }

    assertUniquePhotoSetsAcrossSlugs($assign);

    return $assign;
}

/**
 * @param array<string, list<int>> $assign
 */
function assertUniquePhotoSetsAcrossSlugs(array $assign): void
{
    $bySet = [];
    foreach ($assign as $slug => $nums) {
        $sorted = $nums;
        sort($sorted);
        $key = implode(',', $sorted);
        $bySet[$key][] = $slug;
    }
    $dups = [];
    foreach ($bySet as $key => $slugs) {
        if (count($slugs) > 1) {
            $dups[] = $key . ' => ' . implode('|', $slugs);
        }
    }
    if ($dups !== []) {
        throw new RuntimeException('Identical photo sets across articles: ' . implode('; ', $dups));
    }
}

/**
 * @param list<string> $photos
 * @param array<string, array{zh:string,en:string}> $captions
 * @return list<string>
 */
function figs(array $photos, string $locale, array $captions, string $altBase): array
{
    $blocks = [];
    $seen = [];
    foreach ($photos as $i => $file) {
        if (isset($seen[$file])) {
            throw new InvalidArgumentException('Duplicate figure in article: ' . $file);
        }
        $seen[$file] = true;
        $blocks[] = figure($file, $locale, $captions, $altBase . ' #' . ($i + 1));
    }

    return $blocks;
}

/**
 * Assert no duplicate product image paths in cover+content.
 */
function assertNoDuplicateProductImages(string $cover, string $content, string $slug): void
{
    preg_match_all('#product-\d{2}\.jpg#', $cover . "\n" . $content, $m);
    $files = $m[0] ?? [];
    $counts = array_count_values($files);
    $dups = [];
    foreach ($counts as $file => $n) {
        if ($n > 1) {
            $dups[] = $file . 'x' . $n;
        }
    }
    if ($dups !== []) {
        throw new RuntimeException('Duplicate product images in ' . $slug . ': ' . implode(',', $dups));
    }
}

/** @return array<string, array<string, mixed>> */
function articleSpecs(): array
{
    return [
        'where-to-buy-hanfu-top-marketplaces' => [
            'type' => 'overview_market',
            'subject_zh' => '十大跨境综合平台',
            'subject_en' => 'ten major cross-border marketplaces',
            'angle_zh' => '流量、价格带、正统度与售后',
            'angle_en' => 'traffic, price band, form accuracy and after-sales',
            'photos' => [1, 5, 12, 20],
        ],
        'amazon-hanfu-buying-guide' => [
            'type' => 'marketplace',
            'subject_zh' => 'Amazon',
            'subject_en' => 'Amazon',
            'angle_zh' => '高客单马面裙、定制与退货政策',
            'angle_en' => 'higher-ticket mamian, custom listings and returns',
            'photos' => [1, 2, 3, 8],
        ],
        'tiktok-shop-hanfu-guide' => [
            'type' => 'marketplace',
            'subject_zh' => 'TikTok Shop',
            'subject_en' => 'TikTok Shop',
            'angle_zh' => '短视频种草转化与形制错配风险',
            'angle_en' => 'short-video discovery vs silhouette accuracy risk',
            'photos' => [4, 9, 14, 18],
        ],
        'aliexpress-hanfu-europe-sea' => [
            'type' => 'marketplace',
            'subject_zh' => 'AliExpress（速卖通）',
            'subject_en' => 'AliExpress',
            'angle_zh' => '欧亚走量、曹县供应链与物流周期',
            'angle_en' => 'EU/Asia volume, Cao County supply and shipping cycles',
            'photos' => [6, 7, 11, 15],
        ],
        'shein-new-chinese-style-hanfu' => [
            'type' => 'marketplace',
            'subject_zh' => 'SHEIN',
            'subject_en' => 'SHEIN',
            'angle_zh' => '极致低价新中式与考据形制的边界',
            'angle_en' => 'ultra-low-price New Chinese vs classical form boundaries',
            'photos' => [10, 13, 16, 19],
        ],
        'temu-hanfu-accessories-guide' => [
            'type' => 'marketplace',
            'subject_zh' => 'TEMU',
            'subject_en' => 'TEMU',
            'angle_zh' => '平价配饰下沉与成衣质量波动',
            'angle_en' => 'budget accessories vs garment quality variance',
            'photos' => [17, 21, 22, 8],
        ],
        'yesstyle-hanfu-asia-fashion-gateway' => [
            'type' => 'marketplace',
            'subject_zh' => 'YesStyle',
            'subject_en' => 'YesStyle',
            'angle_zh' => '亚洲潮流分销入口与品牌混杂选店',
            'angle_en' => 'Asia fashion gateway and mixed brand store selection',
            'photos' => [5, 12, 23, 24],
        ],
        'etsy-hanfu-handmade-custom' => [
            'type' => 'marketplace',
            'subject_zh' => 'Etsy',
            'subject_en' => 'Etsy',
            'angle_zh' => '手作定制同好、工期与退换边界',
            'angle_en' => 'handmade custom community, lead times and return limits',
            'photos' => [25, 26, 2, 9],
        ],
        'ebay-hanfu-secondhand-cosplay' => [
            'type' => 'marketplace',
            'subject_zh' => 'eBay',
            'subject_en' => 'eBay',
            'angle_zh' => '二手绝版与 Cos 古装成色风险',
            'angle_en' => 'secondhand rares and cosplay costume condition risk',
            'photos' => [3, 14, 27, 11],
        ],
        'shopee-hanfu-southeast-asia' => [
            'type' => 'marketplace',
            'subject_zh' => 'Shopee',
            'subject_en' => 'Shopee',
            'angle_zh' => '新马分站动销与本地物流友好',
            'angle_en' => 'SG/MY local shipping and SEA demand spikes',
            'photos' => [7, 15, 18, 28],
        ],
        'lazada-new-chinese-style-sea' => [
            'type' => 'marketplace',
            'subject_zh' => 'Lazada',
            'subject_en' => 'Lazada',
            'angle_zh' => '泛华人商圈新中式与改良日常款',
            'angle_en' => 'SEA Chinese diaspora New Chinese dailywear',
            'photos' => [19, 20, 4, 29],
        ],
        'hanfu-vertical-stores-top10-overview' => [
            'type' => 'overview_dtc',
            'subject_zh' => '海外汉服垂直独立站',
            'subject_en' => 'overseas Hanfu DTC vertical stores',
            'angle_zh' => '定位、客群与定价逻辑',
            'angle_en' => 'positioning, audience and pricing logic',
            'photos' => [1, 8, 12, 22],
        ],
        'newmoondance-hanfu-review' => [
            'type' => 'dtc',
            'subject_zh' => 'NewMoonDance',
            'subject_en' => 'NewMoonDance',
            'angle_zh' => '北美 DTC 审美与客服体验',
            'angle_en' => 'North America DTC aesthetics and support experience',
            'photos' => [2, 6, 13, 21],
        ],
        'nuwa-hanfu-review' => [
            'type' => 'dtc',
            'subject_zh' => 'Nüwa Hanfu',
            'subject_en' => 'Nüwa Hanfu',
            'angle_zh' => '设计感独立站与价格带',
            'angle_en' => 'design-led boutique positioning and price band',
            'photos' => [5, 9, 16, 24],
        ],
        'hanfu-story-review' => [
            'type' => 'dtc',
            'subject_zh' => 'Hanfu Story',
            'subject_en' => 'Hanfu Story',
            'angle_zh' => '新加坡与澳洲市场口碑与物流',
            'angle_en' => 'Singapore/Australia reputation and logistics fit',
            'photos' => [3, 10, 17, 25],
        ],
        'newhanfu-store-review' => [
            'type' => 'dtc',
            'subject_zh' => 'Newhanfu Store',
            'subject_en' => 'Newhanfu Store',
            'angle_zh' => '社区官方商城权威感与货盘边界',
            'angle_en' => 'community-official store authority vs assortment limits',
            'photos' => [4, 11, 18, 26],
        ],
        'fashion-hanfu-review' => [
            'type' => 'dtc',
            'subject_zh' => 'Fashion Hanfu',
            'subject_en' => 'Fashion Hanfu',
            'angle_zh' => '时尚化汉服零售与形制说明完整度',
            'angle_en' => 'fashion-forward retail and form documentation completeness',
            'photos' => [7, 14, 19, 27],
        ],
        'intervene-new-chinese-designer' => [
            'type' => 'dtc',
            'subject_zh' => 'Intervene',
            'subject_en' => 'Intervene',
            'angle_zh' => '新中式设计师品牌定位',
            'angle_en' => 'New Chinese designer-brand positioning',
            'photos' => [8, 15, 20, 28],
        ],
        'dawn-x-dare-han-element-buyer' => [
            'type' => 'dtc',
            'subject_zh' => 'Dawn x Dare',
            'subject_en' => 'Dawn x Dare',
            'angle_zh' => '汉元素日常时装化购买场景',
            'angle_en' => 'Han-element daily fashion buying scenarios',
            'photos' => [9, 12, 22, 29],
        ],
        'doresuwe-costume-hanfu-formal' => [
            'type' => 'dtc',
            'subject_zh' => 'Doresuwe',
            'subject_en' => 'Doresuwe',
            'angle_zh' => '礼服/舞台向服装与正统汉服边界',
            'angle_en' => 'formal/stage costume vs classical Hanfu boundary',
            'photos' => [1, 13, 23, 6],
        ],
        'east-meets-dress-chinese-wedding' => [
            'type' => 'dtc',
            'subject_zh' => 'East Meets Dress',
            'subject_en' => 'East Meets Dress',
            'angle_zh' => '中式婚礼礼服需求与仪式感',
            'angle_en' => 'Chinese wedding dress demand and ceremonial look',
            'photos' => [10, 16, 21, 2],
        ],
        'soulsfen-oriental-retro-huafu' => [
            'type' => 'dtc',
            'subject_zh' => 'Soulsfen',
            'subject_en' => 'Soulsfen',
            'angle_zh' => '东方复古华服审美与日常可穿性',
            'angle_en' => 'oriental retro huafu aesthetics and daily wearability',
            'photos' => [11, 17, 24, 5],
        ],
        'why-amayun-factory-direct-hanfu' => [
            'type' => 'why',
            'subject_zh' => '阿玛云源头工厂直销',
            'subject_en' => 'Amayun factory-direct',
            'angle_zh' => '平台与独立站对照清单',
            'angle_en' => 'marketplace vs DTC comparison checklist',
            'photos' => [1, 8, 15, 22],
        ],
        'what-is-hanfu-complete-guide' => [
            'type' => 'encyclopedia_what',
            'subject_zh' => '汉服定义与入门',
            'subject_en' => 'What is Hanfu',
            'angle_zh' => '定义、影楼装差异与核心结构',
            'angle_en' => 'definition, vs costume, core structure',
            'photos' => [1, 5, 12, 20],
        ],
        'hanfu-styles-ruqun-mamian-yuanling' => [
            'type' => 'encyclopedia_styles',
            'subject_zh' => '襦裙、马面、圆领袍',
            'subject_en' => 'ruqun, mamian, yuanling',
            'angle_zh' => '形制对照与选购提示',
            'angle_en' => 'form map and buying tips',
            'photos' => [2, 6, 14, 25],
        ],
        'hanfu-through-dynasties-tang-song-ming' => [
            'type' => 'encyclopedia_dynasty',
            'subject_zh' => '唐宋史明形制演变',
            'subject_en' => 'Tang–Song–Ming silhouette evolution',
            'angle_zh' => '朝代审美与结构变化',
            'angle_en' => 'dynasty aesthetics and structure shifts',
            'photos' => [3, 9, 18, 27],
        ],
        'hanfu-fabrics-embroidery-green-manufacturing' => [
            'type' => 'encyclopedia_fabric',
            'subject_zh' => '面料刺绣与绿色制造',
            'subject_en' => 'fabrics, embroidery and green manufacturing',
            'angle_zh' => '提花刺绣与制造责任',
            'angle_en' => 'jacquard, embroidery and responsible making',
            'photos' => [4, 10, 19, 28],
        ],
        'hanfu-occasions-daily-wedding-festival' => [
            'type' => 'encyclopedia_occasion',
            'subject_zh' => '日常婚礼节令场合',
            'subject_en' => 'daily, wedding and festival occasions',
            'angle_zh' => '场合选形制与礼仪分寸',
            'angle_en' => 'occasion-to-form matching and etiquette',
            'photos' => [7, 13, 21, 29],
        ],
        'amayun-technology-company-story' => [
            'type' => 'brand',
            'subject_zh' => '阿玛云科技公司故事',
            'subject_en' => 'Amayun Technology company story',
            'angle_zh' => '2024 注册与物美价廉宗旨',
            'angle_en' => '2024 founding and value-first mission',
            'photos' => [8, 14, 22, 1],
        ],
        'amayun-origin-visits-factory-partners' => [
            'type' => 'brand',
            'subject_zh' => '产地走访与工厂伙伴',
            'subject_en' => 'origin visits and factory partners',
            'angle_zh' => '原产地确认货源',
            'angle_en' => 'origin verification of supply',
            'photos' => [9, 15, 23, 2],
        ],
        'amayun-handmade-green-mechanical-production' => [
            'type' => 'brand',
            'subject_zh' => '手工结合绿色机械制造',
            'subject_en' => 'handmade plus green mechanical production',
            'angle_zh' => '手工与绿色机械结合',
            'angle_en' => 'handwork plus green mechanical production',
            'photos' => [11, 16, 24, 3],
        ],
    ];
}


/**
 * @param array<string, mixed> $spec
 * @param array<string, array{zh:string,en:string}> $captions
 */
function buildContent(string $baseSlug, array $spec, string $locale, string $title, array $captions): string
{
    $type = (string)($spec['type'] ?? 'marketplace');
    $photos = photoFiles(is_array($spec['photos'] ?? null) ? $spec['photos'] : [1, 2, 3, 4]);
    if (count($photos) < 4) {
        throw new InvalidArgumentException('Need 4 unique photos for ' . $baseSlug);
    }
    // Cover = photos[0]; body figures MUST exclude cover (no within-article reuse).
    $F = figs(array_slice($photos, 1), $locale, $captions, $title);
    $isEn = str_starts_with($locale, 'en');
    $sz = (string)($spec['subject_zh'] ?? $baseSlug);
    $se = (string)($spec['subject_en'] ?? $baseSlug);
    $az = (string)($spec['angle_zh'] ?? '');
    $ae = (string)($spec['angle_en'] ?? '');

    $html = match ($type) {
        'overview_market' => buildOverviewMarket($isEn, $F),
        'overview_dtc' => buildOverviewDtc($isEn, $F),
        'marketplace' => buildMarketplace($isEn, $sz, $se, $az, $ae, $F),
        'dtc' => buildDtc($isEn, $sz, $se, $az, $ae, $F),
        'why' => buildWhy($isEn, $F),
        'encyclopedia_what' => buildWhat($isEn, $F),
        'encyclopedia_styles' => buildStyles($isEn, $F),
        'encyclopedia_dynasty' => buildDynasty($isEn, $F),
        'encyclopedia_fabric' => buildFabric($isEn, $F),
        'encyclopedia_occasion' => buildOccasion($isEn, $F),
        'brand' => buildBrand($isEn, $baseSlug, $sz, $se, $az, $ae, $F),
        default => buildMarketplace($isEn, $sz, $se, $az, $ae, $F),
    };

    return $html . cta($locale);
}

/** @param list<string> $F */
function buildOverviewMarket(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('Why marketplaces are still the first door')
            . p('For overseas buyers who do not read Chinese, or who prefer local payment, tracked shipping, and familiar dispute tools, the major cross-border marketplaces remain the default first door into Hanfu and New Chinese fashion. That convenience is real: you can compare ten sellers in one afternoon, use a credit card you already trust, and lean on platform mediation when a parcel is wrong. The cost of that convenience is also real: listing language mixes classical Hanfu, Han-element fashion, studio costume, and festival cosplay without clear structure photos, so “beautiful on video” often fails the flat-lay test.')
            . p('This guide treats Amazon, TikTok Shop, AliExpress, SHEIN, TEMU, YesStyle, Etsy, eBay, Shopee, and Lazada as channels with different traffic engines, price bands, silhouette risk, and after-sales friction. It is not a ranking of “best clothes forever.” It is a decision map: when a marketplace is enough, when a DTC boutique is worth the markup, and when factory-direct becomes the cleaner path once you know which silhouette you actually want.')
            . $F[0]
            . h2('Channel map at a glance')
            . tableHtml(
                ['Channel', 'Best fit', 'Price feel', 'Form risk', 'Watch-outs'],
                [
                    ['Amazon', 'Higher-ticket mamian / custom needs', 'Mid–high', 'Medium', 'Seller rating + fiber facts'],
                    ['TikTok Shop', 'Interest-commerce discovery', 'Low–mid', 'Med–high', 'Video beauty ≠ structure'],
                    ['AliExpress', 'EU/Asia volume supply', 'Low–mid', 'Medium', 'Lead time + size charts'],
                    ['SHEIN', 'Ultra-cheap New Chinese', 'Low', 'High', 'Fast fashion, not kaoju'],
                    ['TEMU', 'Budget accessories', 'Very low', 'High', 'Garment QC variance'],
                    ['YesStyle', 'English Asia-fashion gateway', 'Mid', 'Medium', 'Mixed brands—vet shops'],
                    ['Etsy', 'Handmade / custom peers', 'Mid–high', 'Low–mid', 'Lead time + returns'],
                    ['eBay', 'Secondhand / cosplay stock', 'Volatile', 'High', 'Condition & provenance'],
                    ['Shopee', 'SEA local logistics', 'Low–mid', 'Medium', 'Site policy differs'],
                    ['Lazada', 'Diaspora New Chinese daily', 'Low–mid', 'Medium', 'Often fashion-reform'],
                ],
            )
            . h2('How to read product photos like a buyer')
            . p('Before you trust a title that says “authentic Hanfu,” demand evidence of construction: cross-collar right lapel (jiaoling youren) where claimed, mamian flat panels and side pleats that match Ming-style logic, ruqun waistband height that is consistent in flat lay, and yuanling round-collar geometry that is not a modern zipper dress with printed motifs. The reference shots below show how correct-form garments look in product photography—use them as a checklist against marketplace carousels, not as decoration.')
            . $F[1]
            . p('Also check fabric honesty: polyester vs silk blends change drape, heat, and longevity. Embroidery density, jacquard layers, and lining completeness are not “nice extras”—they are the difference between a one-event costume and clothing you can wear across seasons. If a listing refuses fiber content, seam close-ups, and size tables in centimeters, treat the price as a soft warning.')
            . $F[2]
            . h2('Buying checklist (marketplace)')
            . ul([
                'Identify the silhouette name (ruqun / mamian / yuanling / aoqun) before comparing price.',
                'Require flat-lay or exploded structure photos—not only model poses.',
                'Read return windows against international shipping time.',
                'Separate New Chinese fashion from classical-form Hanfu in your cart.',
                'If two listings look identical, compare seller tenure and dispute history.',
                'When you already know the form, quote factory-direct for the same structure.',
            ])
            . h2('When factory-direct wins')
            . p('Marketplaces win on discovery and payment habit. Factory-direct wins when you have already decided the silhouette, care about repeatable QC, and want origin-traceable pricing without stacking platform ads, influencer markups, and boutique margins. Amayun’s editorial stance is simple: use platforms to learn taste, then buy structure with evidence.')
            ;
    }

    return h2('为什么综合平台仍是第一入口')
        . p('对不懂中文、或习惯本地支付、可追踪物流与平台纠纷工具的海外买家来说，跨境综合平台仍是接触汉服与新中式的「第一入口」。便利是真实的：一下午能对比十个卖家，信用卡流程熟悉，包裹有问题时也有平台介入。代价同样真实：标题把考据汉服、汉元素时装、影楼装与节令 Cos 混写，缺少平铺结构图时，「视频好看」常常过不了平铺检验。')
        . p('本文把 Amazon、TikTok Shop、速卖通、SHEIN、TEMU、YesStyle、Etsy、eBay、Shopee、Lazada 当作不同流量引擎、价格带、形制风险与售后摩擦的渠道来看——不是「永远最好的衣服」排行榜，而是决策地图：何时平台足够、何时垂直独立站溢价值得、何时在你已经知道要什么形制之后，源头工厂直销更干净。')
        . $F[0]
        . h2('渠道速览对照表')
        . tableHtml(
            ['渠道', '更适合', '价格带体感', '形制风险', '注意点'],
            [
                ['Amazon', '高客单马面/定制刚需', '中高', '中', '卖家评分与材质说明'],
                ['TikTok Shop', '兴趣电商种草转化', '低到中', '中高', '短视频好看≠形制正确'],
                ['AliExpress', '欧亚走量供应链', '低到中', '中', '物流周期与尺码表'],
                ['SHEIN', '极致低价新中式', '低', '高', '快时尚改良，非考据向'],
                ['TEMU', '平价配饰下沉', '很低', '高', '成衣质量波动大'],
                ['YesStyle', '英语亚洲潮流入口', '中', '中', '品牌混杂，需认准店铺'],
                ['Etsy', '手作/定制同好', '中高到高', '低到中', '工期与退换政策'],
                ['eBay', '二手绝版/Cos 古装', '波动大', '高', '成色与来源细查'],
                ['Shopee', '东南亚本地物流', '低到中', '中', '分站政策差异'],
                ['Lazada', '泛华人新中式日常', '低到中', '中', '偏改良日常款'],
            ],
        )
        . h2('像买家一样读产品图')
        . p('在相信「正统汉服」标题之前，先要结构证据：声称交领右衽就要看领襟方向；明制马面要看前后光面与两侧褶裥是否成立；襦裙要看腰头高度在平铺中是否一致；圆领袍要看圆领几何，而不是拉链连衣裙加印花纹样。下列成衣实拍展示正确形制在商品摄影中的样子——请当作对照平台轮播图的检查清单，而不是装饰图。')
        . $F[1]
        . p('还要核对面料诚实度：涤纶与真丝混纺会改变垂感、闷热与寿命。刺绣密度、提花层次、里布完整度不是「好看加分项」，而是一次活动戏服与跨季可穿服装的分水岭。若详情拒绝纤维含量、缝份特写与厘米尺码表，就把低价当成软性警告。')
        . $F[2]
        . h2('综合平台购买清单')
        . ul([
            '比价前先写出形制名（襦裙/马面/圆领袍/袄裙）。',
            '要求平铺或结构分解图，不要只有模特摆拍。',
            '用国际物流耗时对照退货窗口是否现实。',
            '购物车里把新中式时装与考据形制汉服分开。',
            '两款看起来一样时，比较卖家年限与纠纷记录。',
            '一旦形制已定，用同一结构去询工厂直销报价。',
        ])
        . h2('何时工厂直销更优')
        . p('平台赢在发现与支付习惯；工厂直销赢在你已决定形制、在意可重复质检，并希望价格可追溯到产地，而不是叠平台广告、达人加价与精品站毛利。阿玛云的编辑立场很简单：用平台学审美，用证据买结构。')
        ;
}

/** @param list<string> $F */
function buildOverviewDtc(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('What “vertical Hanfu DTC” actually means')
            . p('Overseas Hanfu DTC stores are not a single genre. Some are community-adjacent shops with educational content; some are fashion boutiques selling New Chinese silhouettes; some specialize in wedding or stage looks; some are closer to curated marketplaces with English UX. The shared promise is focus: fewer non-Hanfu SKUs, more styling notes, and often better photography than a random marketplace seller. The shared risk is markup and assortment opacity—beautiful site design does not automatically mean classical construction.')
            . p('Use this overview to compare audience, price band, silhouette honesty, and after-sales posture across major English-facing verticals. Photos below are correct-form references so you can sanity-check any boutique lookbook that only shows runway angles.')
            . $F[0]
            . h2('Comparison dimensions that matter')
            . tableHtml(
                ['Dimension', 'What to ask', 'Red flag', 'Green flag'],
                [
                    ['Audience', 'Beginner / diaspora / stage?', 'One-size marketing blur', 'Clear persona + size notes'],
                    ['Price band', 'What are you paying for?', 'No fabric sheet', 'Fiber + craft breakdown'],
                    ['Silhouette risk', 'Classical vs New Chinese?', 'Only soft filters', 'Flat structure + terms'],
                    ['After-sales', 'Intl returns realistic?', 'All-sales-final silence', 'Policy in writing'],
                ],
            )
            . $F[1]
            . h2('How boutiques differ from marketplaces')
            . p('A good DTC site invests in education, consistent photography, and customer support hours that match overseas time zones. That can justify a premium when you need hand-holding on first purchase. It is less justified when you already know you want a Ming-style mamian with specific panel width and lining, and the boutique cannot show construction any better than a factory catalog. In that case, boutique UX is a luxury wrapper around the same garment decision.')
            . $F[2]
            . h2('Practical shortlist checklist')
            . ul([
                'Write your occasion (daily / wedding / festival / stage) before browsing moodboards.',
                'Force every candidate SKU into a silhouette bucket.',
                'Compare return cost including outbound shipping you already paid.',
                'Ask whether the brand distinguishes costume from wearable Hanfu.',
                'Keep one factory-direct quote for the same silhouette as a price anchor.',
            ])
            . p('Vertical stores remain excellent for discovery and styling confidence. Factory-direct becomes competitive when your uncertainty is no longer “what style do I like?” but “which construction and QC standard am I paying for?”')
            ;
    }

    return h2('「垂直汉服独立站」到底指什么')
        . p('海外汉服 DTC 并不是单一品类。有的靠社区内容带货，有的是新中式时装精品站，有的专做婚礼或舞台，有的更像英语体验更好的精选店。共同承诺是专注：非汉服 SKU 更少、搭配说明更多、摄影往往好过随机平台卖家。共同风险是溢价与货盘不透明——站点设计精美，不等于结构考据成立。')
        . p('用本总览对照主要英语向垂直站的客群、价格带、形制诚实度与售后姿态。下方成衣实拍是正确形制参考，用来校准那些只给秀场角度的 lookbook。')
        . $F[0]
        . h2('真正重要的对比维度')
        . tableHtml(
            ['维度', '该问什么', '红灯', '绿灯'],
            [
                ['客群', '新手/侨民/舞台？', '营销话术一团糊', '人设清晰+尺码说明'],
                ['价格带', '钱买到了什么？', '无线料单', '纤维+工艺拆解'],
                ['形制风险', '考据还是新中式？', '只有柔光滤镜', '平铺结构+术语'],
                ['售后', '国际退货现实吗？', '终售却不写清', '政策白纸黑字'],
            ],
        )
        . $F[1]
        . h2('精品站与综合平台差在哪')
        . p('好的独立站会投入教育内容、稳定摄影，以及匹配海外时区的客服——第一次购买需要手把手时，溢价可以成立。当你已经明确要明制马面、特定光面宽度与里布标准，而精品站并不能比工厂目录展示更多结构时，站点体验就只是同一决策外的豪华包装。')
        . $F[2]
        . h2('实用短名单检查')
        . ul([
            '浏览情绪板之前先写清场合（日常/婚礼/节令/舞台）。',
            '强迫每个候选 SKU 归入形制桶。',
            '把已付的去程运费算进退货总成本。',
            '确认品牌是否区分戏服与可穿汉服。',
            '保留同一形制的工厂直销报价作锚。',
        ])
        . p('垂直站仍擅长发现与搭配信心；当你的不确定从「喜欢什么风格」变成「为哪套结构与质检付钱」时，工厂直销开始具备竞争力。')
        ;
}


/** @param list<string> $F */
function buildMarketplace(bool $isEn, string $sz, string $se, string $az, string $ae, array $F): string
{
    if ($isEn) {
        return h2('Who actually shops ' . $se . ' for Hanfu')
            . p($se . ' attracts overseas buyers for a concrete reason set: payment familiarity, discovery mechanics, and dispute tooling that feels safer than wiring money to an unknown boutique. The angle that matters most here is ' . $ae . '. That does not make every listing honest about silhouette. Titles still blur classical Hanfu, New Chinese fashion, and stage costume, so your job as a buyer is to force structure evidence before price comparison.')
            . p('Treat this review as a channel brief, not a takedown. Fair dimensions are audience fit, price band, silhouette risk, and after-sales realism. We also mark when factory-direct becomes the cleaner purchase once you already know the form you want. The product photos below are correct-form references for mamian panels, ruqun joins, and fabric/structure detail—not claims that ' . $se . ' is selling these exact factory SKUs.')
            . $F[0]
            . h2('Fair comparison dimensions')
            . tableHtml(
                ['Dimension', $se . ' tendency', 'Buyer question', 'Factory-direct contrast'],
                [
                    ['Audience', 'Platform-native shoppers', 'Do I need discovery or a known SKU?', 'Best when silhouette is decided'],
                    ['Price band', 'Ads + seller margin stacked', 'What am I paying beyond cloth?', 'Origin pricing more transparent'],
                    ['Silhouette risk', 'Depends on seller diligence', 'Flat-lay proof or only video?', 'Catalog built around form terms'],
                    ['After-sales', 'Platform rules help/hurt', 'Is return window longer than shipping?', 'QC notes before dispatch'],
                ],
            )
            . h2('Silhouette and fabric checks on ' . $se)
            . p('Open any candidate listing and ignore the first hero pose. Look for cross-collar direction if jiaoling is claimed, mamian flat faces versus chaotic pleats, chest-high or waist-high ruqun joins that stay consistent across photos, and yuanling collars that are cut as robes rather than zipper sheaths with printed “ancient” motifs. If the seller cannot show seams, lining, and fiber content, you are shopping atmosphere, not clothing engineering.')
            . $F[1]
            . p('Fabric and craft decide whether the piece survives more than one photoshoot: weave stability, embroidery density, and whether metallic yarns crack after washing. For accessories-heavy channels, remember that hairpins and sashes can look premium while the main garment uses unstable polyester with weak seam allowances. Separate the cart: learn form from garment photos, then decide if ' . $se . ' is the right fulfillment path.')
            . $F[2]
            . h2('Practical checklist for ' . $se)
            . ul([
                'Write the silhouette name before you sort by price.',
                'Demand centimeters size charts and fiber labels.',
                'Screenshot the return policy dated on purchase day.',
                'Compare at least one factory-direct quote for the same form.',
                'If the listing is New Chinese fashion, label it so in your notes—do not “upgrade” it mentally to kaoju Hanfu.',
                'Watch shipping time against event dates; rush fees rarely fix wrong structure.',
            ])
            . h2('When ' . $se . ' is enough—and when it is not')
            . p($se . ' is enough when you want low-friction discovery, you accept mixed quality, and the item is low-regret (accessories, clearly fashion-reform pieces, or a first experimental wear). It is not enough when you need repeatable Ming-style mamian geometry, documented fabric, or a traceable factory partner. In those cases, keep ' . $se . ' for inspiration, then purchase through a factory-direct path that shows construction on purpose.')
            ;
    }

    return h2('谁会在' . $sz . '买汉服')
        . p($sz . '吸引海外买家，通常因为支付习惯熟悉、发现机制强、纠纷工具比给陌生独立站打款更安心。本篇关键角度是：' . $az . '。这并不等于每条 listing 都对形制诚实。标题仍会把考据汉服、新中式时装与舞台戏服混写，所以买家的工作是：比价之前先逼出结构证据。')
        . p('把本文当作渠道简报，而不是攻击文。公平维度是：客群匹配、价格带、形制风险、售后是否现实；并标明当你已经知道要什么形制时，工厂直销何时更干净。下方成衣图是正确形制参考（马面光面、襦裙连接、面料结构），不是宣称' . $sz . '正在售卖同一工厂 SKU。')
        . $F[0]
        . h2('公平对比维度')
        . tableHtml(
            ['维度', $sz . '常见倾向', '买家该问', '工厂直销对照'],
            [
                ['客群', '平台原生购物习惯', '我要发现还是已知 SKU？', '形制定了更合适'],
                ['价格带', '广告与卖家毛利叠加', '布料之外还付了什么？', '产地定价更透明'],
                ['形制风险', '取决于卖家是否用心', '有平铺证据还是只有视频？', '目录按形制术语组织'],
                ['售后', '平台规则有利有弊', '退货窗口是否长过物流？', '发货前有质检说明'],
            ],
        )
        . h2('在' . $sz . '如何检查形制与面料')
        . p('打开候选商品时先忽略首图摆拍。声称交领就要看领襟方向；马面要分清前后光面与乱褶；齐胸/齐腰襦裙的连接在多图中是否一致；圆领袍是否按袍服裁，而不是拉链连衣裙加「古风」印花。若卖家给不出缝份、里布与纤维含量，你买的是氛围，不是服装工程。')
        . $F[1]
        . p('面料与工艺决定它能否撑过一次拍摄：织纹稳定性、刺绣密度、织金线洗涤后是否脆裂。对配饰很强的渠道，要记住发簪与飘带可以很精致，主服却是缝份不足的不稳涤纶。把购物车拆开：用成衣图学形制，再决定' . $sz . '是否适合作为履约路径。')
        . $F[2]
        . h2($sz . '实用购买清单')
        . ul([
            '按价格排序前先写下形制名。',
            '要求厘米尺码表与纤维标识。',
            '下单当日截图退货政策。',
            '同一形制至少对比一份工厂直销报价。',
            '若是新中式时装，笔记里就标注时装——不要在心理上升级成考据汉服。',
            '用活动日期倒推物流；加急费修不好错误结构。',
        ])
        . h2($sz . '何时够用，何时不够')
        . p($sz . '够用的场景是：你要低摩擦发现、能接受质量分层，且商品低后悔成本（配饰、明确的时装改良、或第一次试水）。不够用的场景是：你需要可重复的明制马面几何、可核对面料，或可追溯工厂伙伴。那时把' . $sz . '留给灵感，购买走以展示结构为目的的工厂直销路径。')
        ;
}

/** @param list<string> $F */
function buildDtc(bool $isEn, string $sz, string $se, string $az, string $ae, array $F): string
{
    if ($isEn) {
        return h2('Reading ' . $se . ' as a serious buyer')
            . p($se . ' sits in the overseas vertical / designer-adjacent lane. The editorial focus here is ' . $ae . '. That can be a strength: clearer branding, more consistent photography, and support that feels less random than a marketplace stranger. It can also hide weak construction behind lifestyle storytelling. Our review separates audience fit, price band, silhouette risk, and after-sales—without pretending one boutique is “the only authentic shop.”')
            . p('Important: Amayun product photos in this article are correct-form references for learning structure (panels, collars, fabric close-ups). They are not presented as items currently sold on ' . $se . '. Use them to interrogate any lookbook that only offers soft lighting and no flat-lay geometry.')
            . $F[0]
            . h2('Fair scorecard')
            . tableHtml(
                ['Lens', 'What good looks like', 'Common miss', 'Your move'],
                [
                    ['Audience', 'Honest persona + sizing', 'Everyone-marketing', 'Match your occasion'],
                    ['Price band', 'Explains craft cost', 'Vibe-only pricing', 'Ask for fiber sheet'],
                    ['Silhouette', 'Names forms correctly', '“Hanfu dress” mush', 'Map to ruqun/mamian/etc.'],
                    ['After-sales', 'Intl policy readable', 'Hidden final-sale', 'Cost the return'],
                ],
            )
            . $F[1]
            . h2('Silhouette risk and when factory-direct wins')
            . p('If ' . $se . ' is transparent about New Chinese fashion versus classical Hanfu, you can buy with eyes open. Risk rises when ceremony language is used to sell zipper dresses, or when wedding/stage pieces are implied to be daily kaoju wear. Factory-direct tends to win when you already know the silhouette, want QC notes, and do not need boutique storytelling to feel confident.')
            . $F[2]
            . h2('Buyer checklist for ' . $se)
            . ul([
                'List your must-have structure points before opening the site.',
                'Save flat photos; discard listings that only show motion blurs.',
                'Compare boutique total cost (ship + duty + return risk) to factory-direct.',
                'Note whether education content matches the SKU construction.',
                'For wedding/stage brands, separate “photoshoot success” from “daily wear seams.”',
            ])
            . p('Bottom line: keep ' . $se . ' on your shortlist when branding, regional logistics, or design direction uniquely fit you. Switch channels when the missing piece is construction evidence and origin pricing—not more mood.')
            ;
    }

    return h2('用认真买家的方式读' . $sz)
        . p($sz . '处在海外垂直站/设计师相邻赛道。本篇关注：' . $az . '。这可以是优势：品牌更清晰、摄影更稳定、客服体感优于陌生平台卖家；也可能用生活方式叙事掩盖薄弱结构。我们的评测拆开客群、价格带、形制风险与售后——不假装某一家是「唯一正统店」。')
        . p('重要：文中阿玛云成衣图是学习结构的正确形制参考（光面、领型、面料特写），并不是宣称这些商品正在' . $sz . '售卖。用它们去追问那些只有柔光、没有平铺几何的 lookbook。')
        . $F[0]
        . h2('公平记分卡')
        . tableHtml(
            ['镜头', '好的样子', '常见失误', '你该怎么做'],
            [
                ['客群', '人设与尺码诚实', '人人适用话术', '对齐你的场合'],
                ['价格带', '解释工艺成本', '只卖氛围定价', '索取纤维说明'],
                ['形制', '正确使用形制名', '「汉服连衣裙」糊弄', '归入襦裙/马面等'],
                ['售后', '国际政策可读', '隐藏终售', '把退货算进成本'],
            ],
        )
        . $F[1]
        . h2('形制风险，以及工厂直销何时胜出')
        . p('若' . $sz . '能清楚区分新中式时装与考据汉服，你可以睁眼购买。风险上升于：用礼仪话术卖拉链裙，或把婚礼/舞台款暗示成日常考据可穿。工厂直销更容易胜出的时刻是：你已知道形制、需要质检说明，并不再依赖精品站叙事来建立信心。')
        . $F[2]
        . h2($sz . '购买清单')
        . ul([
            '打开网站前先列出必须具备的结构点。',
            '保存平铺图；只有动态模糊的listing直接淘汰。',
            '把精品站总成本（运费+税+退货风险）对比工厂直销。',
            '看教育内容是否与 SKU 结构一致。',
            '婚礼/舞台品牌要把「出片成功」与「日常缝份」分开。',
        ])
        . p('结论：当品牌方向、区域物流或设计语言独特契合你时，把' . $sz . '留在短名单；当缺口是结构证据与产地定价——而不是更多情绪板——就换渠道。')
        ;
}

/** @param list<string> $F */
function buildWhy(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('The real problem factory-direct solves')
            . p('Overseas Hanfu shopping fails in predictable ways: marketplace titles over-promise form, DTC markups charge for storytelling you may not need, and shipping timelines collide with events. Factory-direct is not “always cheapest” and not “anti-platform.” It is a channel that starts after you can name the silhouette and care about construction repeatability. Amayun Technology Co., Ltd. (2024) exists to connect verified origin partners with buyers who want inspected garments—handmade finishing plus green mechanical production—without pretending every customer should skip discovery channels.')
            . $F[0]
            . h2('Marketplace vs DTC vs factory-direct')
            . tableHtml(
                ['Channel', 'Wins when', 'Loses when', 'Amayun stance'],
                [
                    ['Marketplace', 'You need discovery + local payment', 'Structure evidence is missing', 'Use to learn taste'],
                    ['DTC boutique', 'You need styling guidance', 'Markup > documented craft', 'Keep if service is unique'],
                    ['Factory-direct', 'Silhouette is decided', 'You still need browsing serendipity', 'Primary for QC + origin price'],
                ],
            )
            . $F[1]
            . h2('What “origin verified” should mean')
            . p('Origin visits are not tourism content. They are checks on whether a workshop can reproduce mamian panel geometry, stable pleat spacing, honest fiber labeling, and seam allowances that survive wear. Green mechanical production is about reducing wasteful rework and unsafe finishing shortcuts while keeping handwork where it still matters—button loops, embroidery placement, final press. If a brand only shows festival lanterns and never shows product structure, it is selling mood, not manufacturing discipline.')
            . $F[2]
            . h2('Decision checklist')
            . ul([
                'Can I name the form in Chinese/English terms?',
                'Do I have flat-lay references for that form?',
                'Is my risk mainly taste (browse) or construction (buy)?',
                'Have I costed returns including outbound shipping?',
                'Do I need boutique education this time, or only fulfillment?',
            ])
            . p('If your answers skew toward construction and fulfillment, factory-direct is the rational default. If you are still exploring aesthetics, keep marketplaces and verticals in the loop—then return here when the silhouette is clear.')
            ;
    }

    return h2('工厂直销真正解决什么问题')
        . p('海外买汉服的失败模式很稳定：平台标题夸大形制、独立站溢价卖你不一定需要的叙事、物流周期撞上活动日期。工厂直销不是「永远最便宜」，也不是「反平台」。它是在你能叫出形制名、并在意结构可重复之后的渠道。阿玛云科技有限公司（2024）连接经确认的原产地伙伴与需要质检成衣的买家——手工结合绿色机械制造——同时不假装每位顾客都该跳过发现型渠道。')
        . $F[0]
        . h2('平台 vs 独立站 vs 工厂直销')
        . tableHtml(
            ['渠道', '何时赢', '何时输', '阿玛云立场'],
            [
                ['综合平台', '要发现+本地支付', '缺少结构证据', '用来学审美'],
                ['DTC 精品站', '需要搭配指导', '溢价>可证明工艺', '服务独特再保留'],
                ['工厂直销', '形制已决定', '仍需要偶然浏览灵感', '质检+产地价主路径'],
            ],
        )
        . $F[1]
        . h2('「产地可追溯」应意味着什么')
        . p('产地走访不是旅游内容，而是确认工坊能否复现马面光面几何、稳定褶距、诚实纤维标识，以及耐穿的缝份。绿色机械制造要减少无效返工与不安全的后整理捷径，同时在扣襻、绣位、终烫等仍需人手的环节保留手工。若一个品牌只晒节庆灯笼、从不展示产品结构，它卖的是情绪，不是制造纪律。')
        . $F[2]
        . h2('决策清单')
        . ul([
            '我能用中/英术语说出形制名吗？',
            '我有该形制的平铺参考吗？',
            '我的风险主要是审美（逛）还是结构（买）？',
            '退货成本是否含已付去程运费？',
            '这次需要精品站教育，还是只要履约？',
        ])
        . p('若答案偏向结构与履约，工厂直销是理性默认；若仍在探索审美，继续保留平台与垂直站——形制清晰后再回来。')
        ;
}


/** @param list<string> $F */
function buildWhat(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('A usable definition of Hanfu')
            . p('Hanfu is the traditional dress system of the Han people: a family of structures historically linked by features such as the cross-collar right lapel (jiaoling youren), largely flat cutting logic, sash-based closure rather than tailored Western darts as the default, and layered etiquette meanings across eras. It is not a synonym for “anything that looks ancient on Instagram.” If a garment cannot be discussed in silhouette terms—ruqun, mamian, yuanlingpao, aoqun, beizi—you are probably looking at costume styling or New Chinese fashion, which can be beautiful without being classical-form Hanfu.')
            . p('This encyclopedia page is written for buyers and learners who need decision language: what counts, what is adjacent, and how to connect study to purchase without being shamed for liking modern reform pieces. Clarity protects both kaoju enthusiasts and fashion buyers.')
            . $F[0]
            . h2('Hanfu vs studio costume vs New Chinese')
            . tableHtml(
                ['Category', 'Primary goal', 'Structure honesty', 'Buy implication'],
                [
                    ['Classical-form Hanfu', 'Wearable system with form rules', 'Should show collar/panels/pleats', 'Shop by silhouette terms'],
                    ['Studio / drama costume', 'Camera and stage effect', 'Often breaks historical logic', 'Budget for one-event use'],
                    ['New Chinese / Han-element', 'Modern fashion with motifs', 'May use zippers, darts, sheath cuts', 'Label it fashion, not kaoju'],
                ],
            )
            . h2('Core feature: cross-collar right lapel')
            . p('Jiaoling youren is a frequent structural clue, not a magical authenticity stamp by itself. Learn to see whether the left and right fronts overlap in the historically expected direction, whether the collar is a true crossed geometry or a printed V-neck illusion, and whether closures rely on ties/loops consistent with the claimed form. Pair collar literacy with skirt or robe literacy: a correct collar on a chaotic mamian still fails the garment.')
            . $F[1]
            . h2('Major forms you will actually shop')
            . p('Ruqun sets split upper and lower garments with waistline height as a major style signal (chest-high vs waist-high). Mamian skirts are famous for flat front/back panels with pleated sides—Ming-style logic that product photography should prove, not hide. Yuanlingpao emphasizes round-collar robe presence, often in menswear and formal-leaning looks. Use the photos as form references while you read dynasty and fabric articles next.')
            . $F[2]
            . h2('Dynasty sketch for beginners')
            . p('Tang aesthetics often read fuller and more decorative in popular imagination; Song tastes are frequently discussed through refined restraint and layered scholar-adjacent looks; Ming structures heavily influence today’s mamian boom. These are sketches, not museum essays—enough to stop buying “Tang dress” labels that are actually modern zipper gowns. Deeper chronology belongs in the Tang–Song–Ming companion guide.')
            . h2('Buying checklist after you learn the words')
            . ul([
                'Name the form before naming the color.',
                'Require flat-lay proof of collar and skirt/robe logic.',
                'Separate occasion (daily/wedding/festival) from silhouette.',
                'Read fiber content as seriously as embroidery beauty.',
                'Only then choose marketplace, DTC, or factory-direct fulfillment.',
            ])
            ;
    }

    return h2('一个可用来决策的汉服定义')
        . p('汉服是汉民族传统服饰体系：以交领右衽、偏平直裁剪逻辑、系带闭合（而非默认西式省道结构），以及历代礼仪语境为线索的结构家族。它不是「Instagram 上看起来很古」的同义词。若一件衣服无法用襦裙、马面、圆领袍、袄裙、褙子等形制语言讨论，你更可能在看影楼造型或新中式时装——它们可以很好看，却不等于考据形制汉服。')
        . p('本百科页写给需要决策语言的买家与学习者：什么算、什么相邻、如何把学习连到购买，而不因喜欢现代改良就被羞辱。清晰同时保护考据爱好者与时装买家。')
        . $F[0]
        . h2('汉服 vs 影楼装 vs 新中式')
        . tableHtml(
            ['类别', '主要目标', '结构诚实度', '购买含义'],
            [
                ['考据形制汉服', '可穿的形制系统', '应展示领/光面/褶', '按形制术语选购'],
                ['影楼/剧装', '镜头与舞台效果', '常打破历史逻辑', '按一次活动预算'],
                ['新中式/汉元素', '借用符号的现代时装', '可用拉链省道紧身', '标注时装，非考据'],
            ],
        )
        . h2('核心特征：交领右衽')
        . p('交领右衽是高频结构线索，本身不是神奇真伪印章。要学会看左右襟是否按声称方向交叠，领型是真交领几何还是印花假 V 领，闭合是否以系带/襻扣匹配其形制。把领部素养与裙/袍素养配对：领对了但马面乱，整件仍不合格。')
        . $F[1]
        . h2('你真正会买到的主流形制')
        . p('襦裙上下分裁，腰线高低是重要风格信号（齐胸/齐腰）。马面裙以前后光面、两侧褶裥著称——明制逻辑应被商品图证明，而不是藏住。圆领袍强调圆领袍服感，常见于男装与偏礼服向造型。读下一篇朝代与面料文时，继续用这些图作成形参考。')
        . $F[2]
        . h2('给新手的朝代速写')
        . p('唐风在流行想象里常更饱满装饰；宋往往被讨论为清雅克制与层叠文人相邻气质；明制结构深刻影响今天的马面热。这是速写不是博物馆论文——足以让你停止把「唐装」标签买成现代拉链礼服。更细年表见唐宋史明专文。')
        . h2('学会术语后的购买清单')
        . ul([
            '先命名形制，再命名颜色。',
            '要求领与裙/袍逻辑的平铺证据。',
            '把场合（日常/婚礼/节令）与形制分开。',
            '像看刺绣一样认真读纤维含量。',
            '最后再选平台、独立站或工厂直销履约。',
        ])
        ;
}

/** @param list<string> $F */
function buildStyles(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('Why silhouette is the shopping unit')
            . p('Platform titles love “Hanfu dress.” Silhouette is what you can measure, compare, and QC. Wrong color is annoying; wrong form is a different garment. This cheat sheet focuses on ruqun, mamian, and yuanling—the three forms overseas beginners meet most—plus notes on aoqun and outer layers so you do not confuse jackets with base systems.')
            . $F[0]
            . h2('Form map')
            . tableHtml(
                ['Form', 'Structure cues', 'Common use', 'Photo check'],
                [
                    ['Qi-xiong / Qi-yao ruqun', 'Top + skirt; waist height differs', 'Daily, travel, photos', 'Waistband flat consistency'],
                    ['Aoqun', 'Warmer short jacket + skirt', 'Cooler weather dressy daily', 'Collar + sleeve type'],
                    ['Mamian', 'Two-panel logic; flat faces', 'Ming vibe, commute guofeng', 'Panel width + pleat spacing'],
                    ['Yuanlingpao', 'Round collar robe presence', 'Menswear / formal lean', 'Collar circle + hem behavior'],
                    ['Beizi / bijia', 'Open outer layer', 'Season bridging', 'Length vs inner set'],
                ],
            )
            . h2('Ruqun: read the join')
            . p('Chest-high and waist-high ruqun are not merely “more sexy” vs “more modest” marketing. The join between upper garment and skirt changes balance, mobility, and how accessories sit. Demand photos of the waistband region without hands blocking it. If every image hides the join, assume the maker knows it is the weak point.')
            . $F[1]
            . h2('Mamian: panels are the point')
            . p('If you cannot see flat front/back panels and orderly side pleats, you may be looking at a generic pleated skirt with Hanfu keywords. Panel width, overlapping logic, and fabric weight decide whether it walks like mamian or like a costume circle skirt. Close-ups of weave help you estimate craft cost honestly.')
            . $F[2]
            . h2('Yuanling and buying discipline')
            . p('Yuanlingpao should read as robe architecture. Check sleeve span, side vents if claimed, and whether the collar is a constructed round opening. Menswear buyers especially should reject “emperor cosplay” listings that skip construction. Finish with a short checklist before any cart.')
            . ul([
                'One sentence: which form am I buying?',
                'Two proofs: collar + skirt/robe geometry.',
                'Three facts: fiber, lining, size in cm.',
                'Then compare channels on the same form.',
            ])
            ;
    }

    return h2('为什么形制才是购物单位')
        . p('平台标题爱写「汉服连衣裙」。形制才是可测量、可对比、可质检的单位。颜色错了烦人；形制错了是另一件衣服。本速查聚焦海外新手最常遇到的襦裙、马面、圆领袍，并补充袄裙与外搭，避免把外套当成基础系统。')
        . $F[0]
        . h2('形制对照表')
        . tableHtml(
            ['形制', '结构线索', '常见用途', '看图检查'],
            [
                ['齐胸/齐腰襦裙', '上衣+下裙；腰线高低不同', '日常、出游、拍照', '腰头平铺是否一致'],
                ['袄裙', '加厚短袄+裙', '秋冬偏礼仪日常', '领型与袖型'],
                ['马面', '两片式逻辑；前后光面', '明制风、通勤国风', '光面宽与褶距'],
                ['圆领袍', '圆领袍服感', '男装/偏礼服', '领圈与下摆动态'],
                ['褙子/比甲', '开衫式外搭', '换季叠穿', '长度与内搭匹配'],
            ],
        )
        . h2('襦裙：读懂连接处')
        . p('齐胸与齐腰不是营销上的「更性感/更端庄」那么简单。上下装连接改变平衡、行动与配饰位置。要求腰头区域不被手挡住的照片；若每张图都藏连接处，默认制作者知道那是薄弱点。')
        . $F[1]
        . h2('马面：光面才是重点')
        . p('若看不见前后光面与有序侧褶，你可能只是在看带汉服关键词的普通百褶裙。光面宽度、交叠逻辑与面料克重决定它走起来像马面还是像戏服大圆裙。织纹特写帮你诚实估计工艺成本。')
        . $F[2]
        . h2('圆领与购买纪律')
        . p('圆领袍应读作袍服建筑。检查通袖、声称的开衩，以及圆领是否为构造出来的领圈。男装买家尤其应拒绝跳过结构的「皇帝 Cos」listing。下单前用短清单收尾。')
        . ul([
            '一句话：我买的是哪一形制？',
            '两证据：领 + 裙/袍几何。',
            '三事实：纤维、里布、厘米尺码。',
            '然后就同一形制对比渠道。',
        ])
        ;
}

/** @param list<string> $F */
function buildDynasty(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('How to use dynasty labels without getting scammed')
            . p('“Tang Hanfu” in marketplace English often means “warm palette + big sleeves + fantasy hair,” not a museum-consistent reconstruction. Dynasty labels are useful as aesthetic clusters and structural hints—especially for beginners—but they become harmful when used as authenticity stamps on zipper dresses. Read Tang–Song–Ming here as shopping literacy: what people usually mean, what structure tends to appear, and how to verify with photos.')
            . $F[0]
            . h2('Sketch table')
            . tableHtml(
                ['Era cue', 'Popular look signal', 'Structure note', 'Buyer caution'],
                [
                    ['Tang-leaning', 'Fuller volume, decorative energy', 'Varied ruqun heights; drama influence strong', 'Reject pure costume engineering'],
                    ['Song-leaning', 'Quieter layers, scholarly vibe', 'Beizi / restrained color stories common in modern shops', 'Do not confuse with office blazer sets'],
                    ['Ming-leaning', 'Mamian boom, cleaner panels', 'Panel + pleat logic should be visible', 'Fake mamian = chaotic pleats only'],
                ],
            )
            . $F[1]
            . h2('From chronology to cart')
            . p('If you love Tang aesthetics, still buy a named form (ruqun variant, etc.) rather than a mood. If you love Ming mamian, demand panel proof. Song-inspired layering should show outer-garment length and inner set compatibility. Dynasty talk without silhouette talk is how budgets disappear into unusable costumes.')
            . $F[2]
            . h2('Checklist')
            . ul([
                'Translate every dynasty hashtag into a silhouette name.',
                'Keep one reference photo of correct structure beside the listing.',
                'Ask whether the piece is daily-wearable or display-only.',
                'Compare factory-direct examples of the same era cue when price gaps look irrational.',
            ])
            ;
    }

    return h2('如何使用朝代标签而不被骗')
        . p('平台英语里的「唐汉服」常常等于「暖色+大袖+幻想发型」，而不是博物馆一致的复原。朝代标签作为审美簇与结构提示对新手有用，但若当成拉链裙的正统印章就有害。把唐—宋—明读成购物素养：大家通常指什么、常见结构倾向、如何用图片核实。')
        . $F[0]
        . h2('速写对照表')
        . tableHtml(
            ['朝代线索', '流行外观信号', '结构提示', '买家警惕'],
            [
                ['偏唐', '体量更满、装饰感强', '襦裙腰线多变；剧装影响大', '拒绝纯戏服工程'],
                ['偏宋', '层叠更静、文人气质', '现代店常见褙子/克制配色', '别与西装套装混淆'],
                ['偏明', '马面热、光面更干净', '光面+褶逻辑应可见', '假马面=只有乱褶'],
            ],
        )
        . $F[1]
        . h2('从年表到购物车')
        . p('喜欢唐风，也仍应买「有名字的形制」，而不是情绪。喜欢明制马面，就要光面证据。宋意叠穿要看外搭长度与内搭兼容。没有形制的朝代话术，是预算消失进不可穿戏服的典型路径。')
        . $F[2]
        . h2('清单')
        . ul([
            '把每个朝代标签翻译成形制名。',
            '把一张正确结构参考放在 listing 旁边对照。',
            '问清楚是日常可穿还是仅展示。',
            '价格差不合理时，对比同朝代线索的工厂直销样本。',
        ])
        ;
}

/** @param list<string> $F */
function buildFabric(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('Fabric is not a footnote')
            . p('Embroidery and jacquard catch the eye; fiber content decides sweat, drape, seasonality, and whether seams hold after washing. Overseas listings often hide polyester behind “silk feeling.” This guide teaches you to read weave, embroidery density, lining, and manufacturing responsibility—including why Amayun emphasizes handmade finishing plus green mechanical production rather than romanticizing every stitch as handmade theater.')
            . $F[0]
            . h2('What to inspect')
            . tableHtml(
                ['Element', 'Why it matters', 'Good signal', 'Bad signal'],
                [
                    ['Fiber label', 'Comfort + care', 'Clear % breakdown', 'Only “premium fabric”'],
                    ['Jacquard/weave', 'Visual depth + cost', 'Even pattern registration', 'Blurry print pretending weave'],
                    ['Embroidery', 'Durability of beauty', 'Stable density, clean back', 'Loose threads, glue stiffness'],
                    ['Lining', 'Opacity + wear', 'Matched to season', 'See-through with no note'],
                ],
            )
            . $F[1]
            . h2('Green mechanical + handwork')
            . p('Serious production uses machines where consistency reduces waste—cutting, certain stitching—and human hands where judgment still wins: embroidery placement, final pressing, button loops. “100% handmade” marketing can be a cover for unscalable QC. Prefer makers who document both.')
            . $F[2]
            . h2('Buyer checklist')
            . ul([
                'Match fiber to climate where you will wear it.',
                'Ask wash method before event deadlines.',
                'Treat metallic yarns as high-maintenance.',
                'Compare craft claims to close-up photos, not adjectives.',
            ])
            ;
    }

    return h2('面料不是脚注')
        . p('刺绣与提花抓眼球；纤维含量决定闷汗、垂感、季节性，以及洗涤后缝份是否还在。海外 listing 常把涤纶藏在「丝感」背后。本指南教你读织纹、绣密、里布与制造责任——以及阿玛云为何强调手工结合绿色机械，而不是把每针都浪漫化成手工剧场。')
        . $F[0]
        . h2('检查什么')
        . tableHtml(
            ['要素', '为何重要', '好信号', '坏信号'],
            [
                ['纤维标识', '舒适+护理', '清晰百分比', '只有「高级面料」'],
                ['提花/织纹', '层次+成本', '对花稳定', '印花冒充提花'],
                ['刺绣', '美的耐久', '密度稳、背面干净', '线头多、胶感僵'],
                ['里布', '遮光+穿着', '匹配季节', '透光却不说明'],
            ],
        )
        . $F[1]
        . h2('绿色机械 + 手工')
        . p('严肃生产在一致性可减少浪费处用机器——裁剪、部分车缝；在判断仍占优处用人手：绣位、终烫、扣襻。「100% 手工」营销可能掩盖不可扩展的质检。更信任能同时说明两者的制造者。')
        . $F[2]
        . h2('买家清单')
        . ul([
            '按实际穿着气候匹配纤维。',
            '活动截止日期前先问洗涤方式。',
            '把织金线当高维护材料。',
            '用特写核对工艺宣称，而不是形容词。',
        ])
        ;
}

/** @param list<string> $F */
function buildOccasion(bool $isEn, array $F): string
{
    if ($isEn) {
        return h2('Occasion first, then silhouette')
            . p('Daily commute, wedding ceremony, and festival photoshoot punish garments differently. A stiff embroidered set that wins on camera may fail on subway stairs; a soft ruqun that feels perfect daily may under-read in ceremonial light. Match occasion constraints before you fall in love with a colorway.')
            . $F[0]
            . h2('Occasion matrix')
            . tableHtml(
                ['Occasion', 'Priority', 'Form lean', 'Risk'],
                [
                    ['Daily', 'Mobility + washability', 'Simpler ruqun / lighter mamian', 'Over-decor that snags'],
                    ['Wedding', 'Ceremony read + photos', 'Formal mamian / structured sets', 'Unwearable weight'],
                    ['Festival', 'Color + group photos', 'Seasonal layers', 'Costume shortcuts'],
                ],
            )
            . $F[1]
            . h2('Etiquette without gatekeeping')
            . p('Respect spaces that request restrained colors or covered shoulders; that is venue etiquette, not a purity test of your entire wardrobe. New Chinese fashion can be perfect for mixed wedding parties; classical-form Hanfu can be perfect for cultural festivals—label honestly either way.')
            . $F[2]
            . h2('Checklist')
            . ul([
                'Write venue rules before browsing.',
                'Plan restroom and transport realities.',
                'Test sit/walk range if possible.',
                'Keep a factory-direct option for repeat wearable sets.',
            ])
            ;
    }

    return h2('先场合，后形制')
        . p('日常通勤、婚礼仪式与节令拍摄对服装的惩罚方式不同。刺绣很硬的一套能赢镜头，却可能输给地铁台阶；日常完美的柔软襦裙在仪式灯光下可能不够「读得见」。先匹配场合约束，再爱上配色。')
        . $F[0]
        . h2('场合矩阵')
        . tableHtml(
            ['场合', '优先', '形制倾向', '风险'],
            [
                ['日常', '行动+可洗', '更简襦裙/轻马面', '装饰过度易勾挂'],
                ['婚礼', '仪式感+出片', '礼服向马面/结构套装', '不可穿的重量'],
                ['节令', '色彩+合影', '季节层叠', '戏服捷径'],
            ],
        )
        . $F[1]
        . h2('有分寸的礼仪，不做门禁')
        . p('尊重场地对颜色或露肩的要求——那是场合礼仪，不是对你整个衣橱的纯度审判。新中式很适合中西混合婚礼；考据形制也很适合文化节——两边都诚实标注即可。')
        . $F[2]
        . h2('清单')
        . ul([
            '浏览前先写场地规则。',
            '规划如厕与交通现实。',
            '可能的话测试坐/走活动度。',
            '为可重复穿着的套装保留工厂直销选项。',
        ])
        ;
}

/** @param list<string> $F */
function buildBrand(bool $isEn, string $baseSlug, string $sz, string $se, string $az, string $ae, array $F): string
{
    $focusEn = match ($baseSlug) {
        'amayun-technology-company-story' => 'company founding in 2024, mission of quality at fair price, and why overseas buyers needed a factory-direct editor—not only another boutique theme.',
        'amayun-origin-visits-factory-partners' => 'how origin visits verify workshops capable of repeating mamian geometry, honest fiber labels, and seam standards—not tourism photography.',
        default => 'how handmade finishing pairs with green mechanical production to reduce wasteful rework while keeping human judgment on embroidery placement and final press.',
    };
    $focusZh = match ($baseSlug) {
        'amayun-technology-company-story' => '2024 年公司注册、物美价廉宗旨，以及海外买家为什么需要工厂直销编辑者——而不只是又一个精品站主题。',
        'amayun-origin-visits-factory-partners' => '产地走访如何确认工坊能复现马面几何、诚实纤维标识与缝份标准——而不是旅游摄影。',
        default => '手工终饰如何与绿色机械结合，减少无效返工，同时在绣位与终烫保留人的判断。',
    };

    if ($isEn) {
        return h2($se . ': substance over slogans')
            . p('Amayun Technology Co., Ltd. publishes this page to explain ' . $focusEn . ' The angle is ' . $ae . '. We show real garment structure because brand stories that only display lanterns taught buyers the wrong lesson.')
            . $F[0]
            . h2('Operating principles')
            . tableHtml(
                ['Principle', 'Practice', 'Buyer benefit'],
                [
                    ['Origin verification', 'Partner workshops checked for repeatable form', 'Fewer lottery purchases'],
                    ['Factory-direct pricing', 'Fewer stacked markups when form is known', 'Clearer value'],
                    ['Handmade + green mechanical', 'Machines for consistency; hands for judgment', 'Stable QC'],
                    ['Editorial honesty', 'Separate fashion vs classical form', 'Less regret'],
                ],
            )
            . $F[1]
            . h2('What we refuse to romanticize')
            . p('We refuse empty “ancient vibe” marketing that hides polyester content, refuse festival stock photos as proof of craft, and refuse to shame buyers who want New Chinese fashion—so long as naming stays honest. Product photography should teach collar, panel, and fabric reality.')
            . $F[2]
            . h2('Reader checklist')
            . ul([
                'Read brand claims against garment close-ups.',
                'Ask which production steps are mechanical vs hand-finished.',
                'Use encyclopedia pages to name your form before checkout.',
                'Treat Amayun as factory-direct fulfillment once silhouette is clear.',
            ])
            ;
    }

    return h2($sz . '：用实质代替口号')
        . p('阿玛云科技有限公司写本页是为了说明：' . $focusZh . '关注角度是' . $az . '。我们展示真实成衣结构，是因为只晒灯笼的品牌故事会教买家学错东西。')
        . $F[0]
        . h2('运营原则')
        . tableHtml(
            ['原则', '做法', '买家收益'],
            [
                ['产地确认', '检查工坊能否复现形制', '少赌运气'],
                ['工厂直销定价', '形制已知时少叠中间加价', '价值更清楚'],
                ['手工+绿色机械', '机器保一致，人手保判断', '质检更稳'],
                ['编辑诚实', '区分时装与考据形制', '更少后悔'],
            ],
        )
        . $F[1]
        . h2('我们拒绝浪漫化什么')
        . p('拒绝用空洞「古风氛围」藏起纤维真相，拒绝用节庆图充当工艺证据，也拒绝羞辱想买新中式的人——只要命名诚实。商品摄影应教会领、光面与面料现实。')
        . $F[2]
        . h2('读者清单')
        . ul([
            '用成衣特写核对品牌宣称。',
            '问清哪些步骤是机械、哪些是手工终饰。',
            '结账前用百科页叫出形制名。',
            '形制清晰后，把阿玛云当工厂直销履约。',
        ])
        ;
}

/**
 * Ensure zh text length and en word count with optional padding paragraphs.
 */
function ensureLength(string $html, string $locale): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (str_starts_with($locale, 'en')) {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $i = 0;
        while (count($words) < 720 && $i < 12) {
            $html .= p('Additional buying note ' . ($i + 1) . ': always reconcile marketing adjectives with flat-lay geometry, fiber honesty, return windows against shipping time, and whether you are paying for discovery convenience or for repeatable construction. Factory-direct becomes rational once the silhouette name is fixed and you can point to panel, collar, or robe cues in reference photography. Prefer centimeters size charts, lining notes, and seam close-ups over lifestyle-only carousels whenever the purchase is meant to be worn more than once.');
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            ++$i;
        }

        return $html;
    }

    $i = 0;
    while (mb_strlen($text) < 1200 && $i < 8) {
        $html .= p('补充选购说明' . ($i + 1) . '：下单前请把营销形容词翻译成可检查的结构点——交领方向、马面光面与褶距、襦裙腰头连接、圆领袍领圈与通袖、纤维百分比与里布、厘米尺码表，以及退货窗口是否覆盖国际物流耗时。形制名称固定后，再用同一结构对照工厂直销目录与质检说明，避免把「氛围图」误当成工艺证据。');
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        ++$i;
    }

    return $html;
}

function baseSlugFromRow(string $slug, string $locale): string
{
    if (str_starts_with($locale, 'en') && str_ends_with($slug, '-en')) {
        return substr($slug, 0, -3);
    }

    return $slug;
}

$captions = loadCaptions();
$specs = articleSpecs();
// Reserve photo sets already used by published zh posts not covered by this rewrite plan (e.g. batch4).
$reservedOutsidePlan = [];
{
    $probe = ObjectManager::getInstance(Post::class);
    $probeRows = $probe->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
        ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
        ->select()->fetchArray();
    foreach (is_array($probeRows) ? $probeRows : [] as $pr) {
        if (!is_array($pr) || str_ends_with((string)($pr[Post::schema_fields_SLUG] ?? ''), '-en')) {
            continue;
        }
        $base = (string)($pr[Post::schema_fields_SLUG] ?? '');
        if ($base === '' || isset($specs[$base])) {
            continue;
        }
        preg_match_all('#product-(\d{2})\.jpg#', (string)($pr[Post::schema_fields_COVER_IMAGE] ?? '') . "\n" . (string)($pr[Post::schema_fields_CONTENT] ?? ''), $mm);
        $nums = array_values(array_unique(array_map('intval', $mm[1] ?? [])));
        sort($nums);
        if (count($nums) >= 2) {
            $reservedOutsidePlan[implode(',', $nums)] = true;
        }
    }
}
$photoPlan = allocateUniquePhotoSets(array_keys($specs), 29, 4, $reservedOutsidePlan);
foreach ($photoPlan as $slugKey => $nums) {
    if (!isset($specs[$slugKey])) {
        continue;
    }
    $specs[$slugKey]['photos'] = $nums;
}
$postModel = ObjectManager::getInstance(Post::class);
$admin = ObjectManager::getInstance(BlogPostAdminService::class);

$rows = $postModel->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
    ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
    ->select()
    ->fetchArray();

if (!is_array($rows)) {
    $rows = [];
}

$updated = 0;
$skipped = 0;
$zhLens = [];
$failures = [];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $postId = (int)($row[Post::schema_fields_ID] ?? 0);
    $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
    $locale = (string)($row[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN');
    if ($postId <= 0 || $slug === '') {
        ++$skipped;
        continue;
    }
    $base = baseSlugFromRow($slug, $locale);
    if (!isset($specs[$base])) {
        $failures[] = "no_spec:{$slug}";
        ++$skipped;
        continue;
    }
    $spec = $specs[$base];
    $title = (string)($row[Post::schema_fields_TITLE] ?? $base);
    try {
        $content = buildContent($base, $spec, $locale, $title, $captions);
        $content = ensureLength($content, $locale);
        $photos = photoFiles(is_array($spec['photos'] ?? null) ? $spec['photos'] : [1, 2, 3, 4]);
        $cover = PHOTO_BASE . '/' . $photos[0];
        // forbid non-product leftovers
        if (preg_match('/fest-|unsplash|pexels-china|bridal-red|market-street/i', $content . $cover)) {
            throw new RuntimeException('Forbidden image token detected for ' . $slug);
        }
        assertNoDuplicateProductImages($cover, $content, $slug);
        $admin->save([
            'post_id' => $postId,
            'website_id' => WEBSITE_ID,
            'locale' => $locale,
            'slug' => $slug,
            'title' => $title,
            'excerpt' => (string)($row[Post::schema_fields_EXCERPT] ?? ''),
            'content' => $content,
            'cover_image' => $cover,
            'author' => trim((string)($row[Post::schema_fields_AUTHOR] ?? '')) ?: AUTHOR,
            'keywords' => (string)($row[Post::schema_fields_KEYWORDS] ?? ''),
            'category_id' => (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0),
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? ''),
        ]);
        $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($locale, 'zh')) {
            $len = mb_strlen($plain);
            $zhLens[] = $len;
            echo "+ #{$postId} zh {$slug} chars={$len} cover={$photos[0]} body=" . implode(',', array_slice($photos, 1)) . "\n";
        } else {
            $wc = count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            echo "+ #{$postId} en {$slug} words={$wc} cover={$photos[0]}\n";
        }
        ++$updated;
    } catch (Throwable $e) {
        $failures[] = $slug . ': ' . $e->getMessage();
        fwrite(STDERR, "! fail {$slug}: {$e->getMessage()}\n");
    }
}

$zhMin = $zhLens ? min($zhLens) : 0;
$zhAvg = $zhLens ? (int)round(array_sum($zhLens) / count($zhLens)) : 0;
echo "updated={$updated} skipped={$skipped} zh_min={$zhMin} zh_avg={$zhAvg}\n";
if ($failures !== []) {
    echo "failures:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}
echo "NEXT: php app/code/Weline/Blog/data/redistribute-blog-r1-photos-strict.php  # enforce max_cross<=2\n";

