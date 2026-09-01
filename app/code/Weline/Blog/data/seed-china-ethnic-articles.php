<?php

declare(strict_types=1);

/**
 * Incremental seed: China 56 ethnic dress articles (2 themes × zh/en) + Hanfu menswear.
 * Ethnic posts: category SVG cover + 1 SVG body figure (not product-*.jpg — pool capacity).
 * Mens/hub Hanfu posts: product-*.jpg with max_cross<=2 (prefer cover-only; optional 1 body).
 * Idempotent (skip existing slugs). Does NOT purge.
 *
 * After large product-photo seeds, run:
 *   php app/code/Weline/Blog/data/repair-china-ethnic-photo-capacity.php
 *
 * Usage: php app/code/Weline/Blog/data/seed-china-ethnic-articles.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const PHOTO_BASE = '/media/blog/hanfu/articles/photos';
const CAPTIONS_FILE = __DIR__ . '/hanfu-photo-captions.json';
const AUTHOR = 'Amayun Editorial';
const POOL_SIZE = 29;
const PER_ARTICLE = 4;

/** @return array<string,int> */
function categoryIdMap(): array
{
    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $map = [];
    foreach ($admin->tree(WEBSITE_ID, 'zh_Hans_CN') as $node) {
        $slug = trim((string)($node['code'] ?? ''));
        $id = (int)($node['category_id'] ?? 0);
        if ($slug !== '' && $id > 0) {
            $map[$slug] = $id;
        }
    }

    return $map;
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

/** @return array<string, array{zh:string,en:string}> */
function loadCaptions(): array
{
    $raw = file_get_contents(CAPTIONS_FILE);
    if ($raw === false) {
        throw new RuntimeException('Cannot read captions');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid captions JSON');
    }
    $out = [];
    foreach ($data as $file => $row) {
        if (!is_string($file) || !is_array($row) || !preg_match('/^product-\d{2}\.jpg$/', $file)) {
            continue;
        }
        $out[$file] = [
            'zh' => trim((string)($row['zh'] ?? '')),
            'en' => trim((string)($row['en'] ?? '')),
        ];
    }

    return $out;
}

/** @return array<string, true> */
function reservedPhotoSetKeys(): array
{
    $model = ObjectManager::getInstance(Post::class);
    $rows = $model->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
        ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
        ->select()->fetchArray();
    $keys = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row) || str_ends_with((string)($row[Post::schema_fields_SLUG] ?? ''), '-en')) {
            continue;
        }
        $blob = (string)($row[Post::schema_fields_COVER_IMAGE] ?? '') . "\n" . (string)($row[Post::schema_fields_CONTENT] ?? '');
        preg_match_all('#product-(\d{2})\.jpg#', $blob, $m);
        $nums = array_values(array_unique(array_map('intval', $m[1] ?? [])));
        sort($nums);
        if (count($nums) >= 2) {
            $keys[implode(',', $nums)] = true;
        }
    }

    return $keys;
}

/**
 * @param list<string> $slugs
 * @param array<string, true> $reserved
 * @return array<string, list<int>>
 */
function allocateAvoidingReserved(array $slugs, array $reserved, int $poolSize = POOL_SIZE, int $per = PER_ARTICLE): array
{
    $usage = array_fill(1, $poolSize, 0);
    $coverUsage = array_fill(1, $poolSize, 0);
    $seen = $reserved;
    $assign = [];
    foreach ($slugs as $slug) {
        $ids = range(1, $poolSize);
        usort($ids, static fn (int $a, int $b): int => ($usage[$a] <=> $usage[$b]) ?: ($a <=> $b));
        $picked = null;
        $n = count($ids);
        for ($i = 0; $i < $n && $picked === null; ++$i) {
            for ($j = $i + 1; $j < $n && $picked === null; ++$j) {
                for ($k = $j + 1; $k < $n && $picked === null; ++$k) {
                    for ($l = $k + 1; $l < $n; ++$l) {
                        $combo = [$ids[$i], $ids[$j], $ids[$k], $ids[$l]];
                        sort($combo);
                        $key = implode(',', $combo);
                        if (!isset($seen[$key])) {
                            $picked = $combo;
                            break;
                        }
                    }
                }
            }
        }
        if ($picked === null) {
            throw new RuntimeException('No free photo set for ' . $slug);
        }
        usort($picked, static function (int $a, int $b) use ($coverUsage, $usage): int {
            return ($coverUsage[$a] <=> $coverUsage[$b])
                ?: (($usage[$a] <=> $usage[$b]) ?: ($a <=> $b));
        });
        $sorted = $picked;
        sort($sorted);
        $seen[implode(',', $sorted)] = true;
        ++$coverUsage[$picked[0]];
        foreach ($picked as $num) {
            ++$usage[$num];
        }
        $assign[$slug] = $picked;
    }

    return $assign;
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
 * @param array<string, array{zh:string,en:string}> $captions
 * @param list<string> $files
 * @return list<string>
 */
function bodyFigs(array $files, string $locale, array $captions, string $altBase): array
{
    $blocks = [];
    $body = array_slice($files, 1);
    $seen = [$files[0] => true];
    foreach ($body as $i => $file) {
        if (isset($seen[$file])) {
            throw new InvalidArgumentException('Duplicate figure: ' . $file);
        }
        $seen[$file] = true;
        $src = PHOTO_BASE . '/' . $file;
        $cap = $captions[$file][str_starts_with($locale, 'en') ? 'en' : 'zh'] ?? $file;
        $blocks[] = '<figure class="blog-illust">'
            . '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="'
            . htmlspecialchars($altBase . ' #' . ($i + 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" loading="lazy" />'
            . '<figcaption>' . htmlspecialchars($cap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</figcaption>'
            . '</figure>';
    }

    return $blocks;
}

function cta(string $locale): string
{
    if (str_starts_with($locale, 'en')) {
        return h2('Factory-direct next step')
            . p('Amayun Technology Co., Ltd. (registered 2024) offers factory-direct Hanfu with verified origin partners. Use these ethnic-dress primers to build cultural literacy, then compare silhouette cues against Amayun catalog flat-lays before you buy.')
            . p('Product photos here are Hanfu structure references for learning construction cues—not claims that listings sell ethnic minority garments as Hanfu.');
    }

    return h2('源头工厂直销：下一步怎么选')
        . p('阿玛云科技有限公司（注册于 2024）提供源头工厂直销汉服：原产地伙伴可追溯。阅读民族服饰科普后，再用成衣平铺图核对结构点，再决定购买。')
        . p('本文配图为汉服成衣结构参考实拍，用于学习可检查的形制线索，并不等同于宣称某渠道把少数民族服装当作汉服售卖。');
}

function ensureLength(string $html, string $locale): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (str_starts_with($locale, 'en')) {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $i = 0;
        while (count($words) < 720 && $i < 12) {
            $html .= p('Additional note ' . ($i + 1) . ': keep ethnicity names precise, separate festival dress from stage costume, and never relabel another people’s garment as “generic Chinese costume.” When returning to Hanfu shopping, demand flat-lay proof of collar direction, waist join, sleeve span, fiber data and centimeter charts.');
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            ++$i;
        }

        return $html;
    }
    $i = 0;
    while (mb_strlen($text) < 1200 && $i < 8) {
        $html .= p('补充说明' . ($i + 1) . '：民族名称要写准，节庆盛装与舞台戏服要分开，切勿把他族服装笼统写成「中国风戏服」。回到汉服选购时，仍以交领方向、腰头连接、通袖与下摆、纤维说明与厘米尺码表为可检查标准。');
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        ++$i;
    }

    return $html;
}

function assertNoDupImages(string $cover, string $content, string $slug): void
{
    preg_match_all('#product-\d{2}\.jpg#', $cover . "\n" . $content, $m);
    $counts = array_count_values($m[0] ?? []);
    $dups = [];
    foreach ($counts as $file => $n) {
        if ($n > 1) {
            $dups[] = $file . 'x' . $n;
        }
    }
    if ($dups !== []) {
        throw new RuntimeException('Duplicate images in ' . $slug . ': ' . implode(',', $dups));
    }
}

function slugExists(string $slug): bool
{
    $model = ObjectManager::getInstance(Post::class);
    $row = $model->clearData()->reset()
        ->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
        ->where(Post::schema_fields_SLUG, $slug)
        ->find()
        ->fetchArray();

    return is_array($row) && (int)($row[Post::schema_fields_ID] ?? 0) > 0;
}

/**
 * @param array<string,mixed> $g
 * @param list<string> $F
 */
function buildEthnicOverview(array $g, string $locale, array $F): string
{
    $en = str_starts_with($locale, 'en');
    if ($en) {
        return h2('Where this dress tradition lives')
            . p($g['en'] . ' traditional dress is rooted in ' . $g['region_en'] . '. Readers should learn local names and construction logic before buying festival replicas or travel souvenirs.')
            . ($F[0] ?? '')
            . h2('Silhouette cues to memorize')
            . p('Core silhouette: ' . $g['silhouette_en'] . '. Check proportion of jacket vs skirt/robe, headwear, and whether metal ornaments are structural or decorative.')
            . ul([
                'Name the ethnicity and region before naming a shop SKU.',
                'Separate daily wear from ceremonial full dress.',
                'Ask whether fabrics are handwoven, embroidered, or printed substitutes.',
            ])
            . ($F[1] ?? '')
            . h2('Fabric and craft')
            . p('Typical materials: ' . $g['fabric_en'] . '. Motifs often include ' . $g['motif_en'] . '.')
            . tableHtml(
                ['Lens', 'What to check'],
                [
                    ['Silhouette', $g['silhouette_en']],
                    ['Fabric', $g['fabric_en']],
                    ['Motif', $g['motif_en']],
                    ['Region', $g['region_en']],
                ],
            )
            . ($F[2] ?? '')
            . h2('Boundary with Hanfu')
            . p('Hanfu is the historical dress system of the Han people. Comparing collar direction or robe volume can be educational, but renaming ' . $g['en'] . ' dress as “Hanfu” erases both traditions. Keep catalogs separate.')
            . cta($locale);
    }

    return h2('地理与文化脉络')
        . p($g['zh'] . '传统服饰主要流传于' . $g['region'] . '。购买节庆复制品或文旅周边前，先学会本民族称谓与结构逻辑，避免用「民族风」笼统标签覆盖细节。')
        . ($F[0] ?? '')
        . h2('形制要点')
        . p('核心形制：' . $g['silhouette'] . '。阅读商品图时核对上衣与裙/袍比例、头饰，以及银饰/金属件是结构件还是装饰。')
        . ul([
            '先写清民族与地域，再谈店铺 SKU。',
            '日常装与盛装/礼仪装要分开描述。',
            '确认面料是手织/刺绣，还是印花替代。',
        ])
        . ($F[1] ?? '')
        . h2('面料与工艺')
        . p('常见材料：' . $g['fabric'] . '。纹样线索常包括：' . $g['motif'] . '。')
        . tableHtml(
            ['观察维度', '要点'],
            [
                ['形制', $g['silhouette']],
                ['面料', $g['fabric']],
                ['纹样', $g['motif']],
                ['地域', $g['region']],
            ],
        )
        . ($F[2] ?? '')
        . h2('与汉服的边界')
        . p('汉服是汉族历史衣冠体系。对照领型或袍服体量可以增长见识，但把' . $g['zh'] . '服装改称「汉服」会同时伤害两边传统。目录与命名应分开。')
        . cta($locale);
}

/**
 * @param array<string,mixed> $g
 * @param list<string> $F
 */
function buildEthnicOccasion(array $g, string $locale, array $F): string
{
    $en = str_starts_with($locale, 'en');
    if ($en) {
        return h2('Occasions that define the look')
            . p('Typical occasions for ' . $g['en'] . ' dress include: ' . $g['occasion_en'] . '. Festival full dress may add silver, beads or layered skirts that daily clothes omit.')
            . ($F[0] ?? '')
            . h2('Craft checklist for buyers')
            . ul([
                'Motif literacy: ' . $g['motif_en'],
                'Fabric honesty: ' . $g['fabric_en'],
                'Reject listings that call every minority garment “ancient Chinese costume.”',
            ])
            . ($F[1] ?? '')
            . h2('How Amayun readers should use this guide')
            . p('Use ethnic literacy to avoid cultural mash-ups, then return to Hanfu shopping with stricter silhouette demands—especially flat-lay proof and fiber percentages.')
            . ($F[2] ?? '')
            . h2('Quick matrix')
            . tableHtml(
                ['Topic', 'Detail'],
                [
                    ['Occasion', $g['occasion_en']],
                    ['Silhouette', $g['silhouette_en']],
                    ['Region', $g['region_en']],
                ],
            )
            . cta($locale);
    }

    return h2('场合决定盛装级别')
        . p($g['zh'] . '常见穿着场合包括：' . $g['occasion'] . '。节庆盛装可能叠加银饰、珠串或多层裙装，日常装则更轻便。')
        . ($F[0] ?? '')
        . h2('购买前的工艺清单')
        . ul([
            '纹样素养：' . $g['motif'],
            '面料诚实度：' . $g['fabric'],
            '拒绝把各族服装一律写成「古代中国戏服」的标题党。',
        ])
        . ($F[1] ?? '')
        . h2('阿玛云读者怎么用这篇')
        . p('先建立民族服饰边界感，再回到汉服选购——对平铺结构与纤维百分比提出更严要求。')
        . ($F[2] ?? '')
        . h2('速查表')
        . tableHtml(
            ['主题', '要点'],
            [
                ['场合', $g['occasion']],
                ['形制', $g['silhouette']],
                ['地域', $g['region']],
            ],
        )
        . cta($locale);
}

/**
 * @param list<string> $F
 */
function buildMensContent(string $type, string $locale, array $F): string
{
    $en = str_starts_with($locale, 'en');
    if ($type === 'mens_hub') {
        if ($en) {
            return h2('Men’s Hanfu starts with robe architecture')
                . p('Yuanling round-collar robes, zhiju straight robes and related men’s forms fail when listings hide collar construction and sleeve span. Demand flat-lays.')
                . ($F[0] ?? '')
                . h2('Core men’s silhouettes')
                . tableHtml(
                    ['Form', 'Cue', 'Occasion'],
                    [
                        ['Yuanling', 'Round collar + robe volume', 'Formal / semi-formal'],
                        ['Zhiju', 'Straight hem logic', 'Daily to ritual'],
                        ['Dao-style layers', 'Layering and sash', 'Study / ceremony looks'],
                    ],
                )
                . ($F[1] ?? '')
                . h2('Fit and fabric')
                . p('Men’s buyers should verify shoulder line, sleeve length, hem behavior when walking, and fiber percentages—not only model height claims.')
                . ($F[2] ?? '')
                . cta($locale);
        }

        return h2('男装先看袍服结构')
            . p('圆领袍、直裾及相关男装形制，最怕详情页藏住领圈构造与通袖跨度。务必要求平铺图。')
            . ($F[0] ?? '')
            . h2('主流男装形制')
            . tableHtml(
                ['形制', '观察点', '场合'],
                [
                    ['圆领袍', '圆领 + 袍服体量', '礼服/偏礼服'],
                    ['直裾', '直裾下摆逻辑', '日常到礼仪'],
                    ['道袍式叠穿', '层次与腰带', '书斋/仪式向'],
                ],
            )
            . ($F[1] ?? '')
            . h2('版型与面料')
            . p('男装买家应核对肩线、袖长、行走时下摆动态与纤维百分比，而不是只看模特身高口号。')
            . ($F[2] ?? '')
            . cta($locale);
    }

    // mens_yuanling
    if ($en) {
        return h2('Yuanling for men: what must show')
            . p('A men’s yuanling listing should prove a constructed round collar, continuous sleeve plane, and honest hem/side vents—not an “emperor cosplay” montage.')
            . ($F[0] ?? '')
            . ul([
                'Collar ring continuity in flat-lay',
                'Sleeve span vs body height',
                'Fiber + lining disclosure',
            ])
            . ($F[1] ?? '')
            . h2('When to choose factory-direct')
            . p('If marketplace photos skip structure, compare the same cues against Amayun factory-direct catalog notes before paying.')
            . ($F[2] ?? '')
            . cta($locale);
    }

    return h2('男装圆领袍：必须看见什么')
        . p('男装圆领袍详情应证明可构造的圆领、通袖平面，以及诚实的下摆/开衩——而不是「皇帝 Cos」拼贴。')
        . ($F[0] ?? '')
        . ul([
            '平铺可见领圈连续性',
            '通袖跨度相对身高合理',
            '公开纤维与里布',
        ])
        . ($F[1] ?? '')
        . h2('何时转向工厂直销')
        . p('平台图若跳过结构，请先用同一套观察点对照阿玛云工厂直销目录与质检说明，再付款。')
        . ($F[2] ?? '')
        . cta($locale);
}

/**
 * @return list<array<string,mixed>>
 */
function articleDefs(array $groups): array
{
    $defs = [
        [
            'category' => 'hanfu-mens',
            'slug' => 'hanfu-menswear-guide',
            'type' => 'mens_hub',
            'keywords' => 'hanfu men,yuanling,男装汉服,圆领袍',
            'zh_title' => '汉服男装入门：圆领袍、直裾与场合怎么选',
            'zh_excerpt' => '用可检查的结构点读懂男装袍服，避开皇帝 Cos 标题党。',
            'en_title' => 'Hanfu Menswear Guide: Yuanling, Zhiju and Occasions',
            'en_excerpt' => 'Read men’s robes by structure—not cosplay montages.',
            'group' => null,
        ],
        [
            'category' => 'hanfu-mens',
            'slug' => 'mens-yuanling-robe-checklist',
            'type' => 'mens_yuanling',
            'keywords' => 'yuanling men,圆领袍男装,hanfu robe',
            'zh_title' => '男装圆领袍避雷清单：领圈、通袖与面料',
            'zh_excerpt' => '下单前用平铺图核对圆领构造、通袖与纤维说明。',
            'en_title' => 'Men’s Yuanling Checklist: Collar, Sleeve Span, Fiber',
            'en_excerpt' => 'Flat-lay proof for collar construction before you pay.',
            'group' => null,
        ],
        [
            'category' => 'china-ethnic-dress',
            'slug' => 'china-56-ethnic-dress-hub',
            'type' => 'hub',
            'keywords' => '中国民族服饰,56民族,ethnic dress China',
            'zh_title' => '中国 56 个民族服饰导览：如何按民族阅读服装',
            'zh_excerpt' => '先分民族与地域，再谈形制、面料与场合；并与汉服边界对照。',
            'en_title' => 'China’s 56 Ethnic Dress Hub: How to Read Each Tradition',
            'en_excerpt' => 'Name the people and region first—then silhouette, fabric and occasion.',
            'group' => null,
        ],
    ];

    foreach ($groups as $g) {
        $code = (string)$g['code'];
        $defs[] = [
            'category' => 'ethnic-cn-' . $code,
            'slug' => 'ethnic-' . $code . '-dress-overview',
            'type' => 'overview',
            'keywords' => $g['zh'] . '服饰,' . $g['en'] . ' dress,民族服装',
            'zh_title' => $g['zh'] . '服饰概览：形制、面料与文化边界',
            'zh_excerpt' => $g['region'] . ' · ' . $g['silhouette'] . '。读懂结构后再谈购买与对照汉服。',
            'en_title' => $g['en'] . ' Dress Overview: Silhouette, Fabric, Boundaries',
            'en_excerpt' => $g['region_en'] . ' · ' . $g['silhouette_en'] . '. Learn structure before shopping comparisons.',
            'group' => $g,
        ];
        $defs[] = [
            'category' => 'ethnic-cn-' . $code,
            'slug' => 'ethnic-' . $code . '-occasion-craft',
            'type' => 'occasion',
            'keywords' => $g['zh'] . ',' . $g['occasion'] . ',' . $g['en'] . ' festival dress',
            'zh_title' => $g['zh'] . '服装场合与工艺：' . mb_substr((string)$g['occasion'], 0, 24),
            'zh_excerpt' => '场合：' . $g['occasion'] . '；纹样：' . $g['motif'] . '。',
            'en_title' => $g['en'] . ' Occasions & Craft: Festival vs Daily Dress',
            'en_excerpt' => 'Occasions: ' . $g['occasion_en'] . '; motifs: ' . $g['motif_en'] . '.',
            'group' => $g,
        ];
    }

    return $defs;
}

$groups = require __DIR__ . '/china-ethnic-groups.php';
if (!is_array($groups) || count($groups) !== 56) {
    throw new RuntimeException('Need 56 ethnic groups');
}

$captions = loadCaptions();
$categories = categoryIdMap();
$defs = articleDefs($groups);

foreach ($defs as $def) {
    if (!isset($categories[$def['category']])) {
        fwrite(STDERR, 'Missing category: ' . $def['category'] . PHP_EOL);
        exit(1);
    }
}

$slugs = array_column($defs, 'slug');
$photoPlan = allocateAvoidingReserved($slugs, reservedPhotoSetKeys());
$admin = ObjectManager::getInstance(BlogPostAdminService::class);
$created = 0;
$skipped = 0;

foreach ($defs as $def) {
    $nums = $photoPlan[$def['slug']];
    $files = photoFiles($nums);
    if (count($files) < PER_ARTICLE) {
        throw new RuntimeException('Need ' . PER_ARTICLE . ' photos for ' . $def['slug']);
    }
    $cover = PHOTO_BASE . '/' . $files[0];
    $categoryId = $categories[$def['category']];

    foreach ([
        'zh_Hans_CN' => [
            'slug' => $def['slug'],
            'title' => $def['zh_title'],
            'excerpt' => $def['zh_excerpt'],
        ],
        'en_US' => [
            'slug' => $def['slug'] . '-en',
            'title' => $def['en_title'],
            'excerpt' => $def['en_excerpt'],
        ],
    ] as $locale => $pack) {
        if (slugExists($pack['slug'])) {
            echo "= skip {$pack['slug']}\n";
            ++$skipped;
            continue;
        }
        $F = bodyFigs($files, $locale, $captions, $pack['title']);
        $type = (string)$def['type'];
        if ($type === 'overview') {
            $content = buildEthnicOverview($def['group'], $locale, $F);
        } elseif ($type === 'occasion') {
            $content = buildEthnicOccasion($def['group'], $locale, $F);
        } elseif ($type === 'hub') {
            $content = str_starts_with($locale, 'en')
                ? h2('How to navigate 56 traditions')
                    . p('China’s 56 ethnic groups each carry dress systems with local names, fabrics and festival calendars. Open a per-ethnicity category to read silhouette and occasion primers, then keep Hanfu shopping catalogs separate.')
                    . ($F[0] ?? '')
                    . ul([
                        'Start from region + ethnicity name',
                        'Separate daily vs ceremonial dress',
                        'Compare respectfully—do not relabel as Hanfu',
                    ])
                    . ($F[1] ?? '')
                    . ($F[2] ?? '')
                    . cta($locale)
                : h2('如何阅读 56 个民族的服装')
                    . p('中国 56 个民族各有称谓、面料与节庆日历。进入各民族子分类阅读形制与场合短文；选购汉服时保持目录分离，不做改名替换。')
                    . ($F[0] ?? '')
                    . ul([
                        '先写地域 + 民族名称',
                        '日常装与盛装分开',
                        '可对照、不可改称汉服',
                    ])
                    . ($F[1] ?? '')
                    . ($F[2] ?? '')
                    . cta($locale);
        } else {
            $content = buildMensContent($type, $locale, $F);
        }
        $content = ensureLength($content, $locale);
        assertNoDupImages($cover, $content, $pack['slug']);
        $row = $admin->save([
            'website_id' => WEBSITE_ID,
            'locale' => $locale,
            'slug' => $pack['slug'],
            'title' => $pack['title'],
            'excerpt' => $pack['excerpt'],
            'content' => $content,
            'cover_image' => $cover,
            'author' => AUTHOR,
            'keywords' => $def['keywords'],
            'category_id' => $categoryId,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int)($row[Post::schema_fields_ID] ?? 0);
        $bodyList = implode(',', array_slice($files, 1));
        echo "+ #{$id} [{$locale}] {$pack['slug']} cover={$files[0]} body={$bodyList} → {$def['category']}\n";
        ++$created;
    }
}

echo "created={$created} skipped={$skipped}\n";
