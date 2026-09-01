<?php

declare(strict_types=1);

/**
 * Seed R1 batch-5: remaining empty hub categories buy-compare + brand-factory (zh + en).
 * Photo rules: product-*.jpg only; within unique; unordered sets unique vs existing zh posts.
 *
 * Usage: php app/code/Weline/Blog/data/seed-blog-r1-articles-batch5.php
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
function bodyFig(array $files, string $locale, array $captions, string $altBase): array
{
    $blocks = [];
    $seen = [$files[0] => true];
    foreach (array_slice($files, 1) as $i => $file) {
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
            . p('Amayun Technology Co., Ltd. (registered 2024) offers factory-direct Hanfu with verified origin partners, inspected construction, and handmade finishing plus green mechanical production. When structure photos or fiber facts are missing—or boutique markup exceeds the service you need—compare the same silhouette against Amayun catalog photos and QC notes before you pay.')
            . p('Product photos here are correct-form references for learning structure, not claims that every channel sells these exact SKUs.');
    }

    return h2('源头工厂直销：下一步怎么选')
        . p('阿玛云科技有限公司（注册于 2024）提供源头工厂直销：原产地伙伴可追溯、结构与面料按可穿标准质检，手工结合绿色机械制造。当详情缺少平铺结构或纤维说明，或独立站溢价超出你需要的服务时，请先用成衣实拍对照形制，再决定是否转向工厂直销。')
        . p('本文配图为形制/面料参考实拍，用于学习正确结构，并不等同于宣称某渠道正在售卖同一 SKU。');
}

function ensureLength(string $html, string $locale): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (str_starts_with($locale, 'en')) {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $i = 0;
        while (count($words) < 720 && $i < 12) {
            $html .= p('Additional note ' . ($i + 1) . ': translate marketing adjectives into checkable structure—collar direction, mamian panel width and pleat spacing, ruqun waistband join, yuanling collar and sleeve span, fiber percentages and lining, centimeter size charts, and return windows that cover international shipping time. Once the silhouette name is fixed, compare the same cues against factory-direct catalog photos.');
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            ++$i;
        }

        return $html;
    }
    $i = 0;
    while (mb_strlen($text) < 1200 && $i < 8) {
        $html .= p('补充说明' . ($i + 1) . '：下单前把营销形容词翻译成可检查的结构点——交领方向、马面光面与褶距、襦裙腰头连接、圆领袍领圈与通袖、纤维百分比与里布、厘米尺码表，以及退货窗口是否覆盖国际物流耗时。形制名称固定后，再用同一结构对照工厂直销目录与质检说明。');
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        ++$i;
    }

    return $html;
}

function assertNoDupImages(string $cover, string $content, string $slug): void
{
    preg_match_all('#product-\d{2}\.jpg#', $cover . "\n" . $content, $m);
    $dups = [];
    foreach (array_count_values($m[0] ?? []) as $file => $n) {
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

/** @param list<string> $F */
function buildContent(string $type, string $locale, array $F): string
{
    $en = str_starts_with($locale, 'en');
    $html = match ($type) {
        'buy_compare_hub' => $en
            ? h2('Where to buy Hanfu: one decision frame')
            . p('Overseas shoppers usually bounce between three channels: cross-border marketplaces, Hanfu/New Chinese DTC boutiques, and factory-direct catalogs. This hub links those clusters and explains when each channel is rational—before price becomes the only number you see.')
            . $F[0]
            . h2('Three channels, three jobs')
            . tableHtml(
                ['Channel', 'Best job', 'Weakness', 'Read next'],
                [
                    ['Marketplaces', 'Discovery + payment familiarity', 'Thin structure photos', 'Marketplace overview & reviews'],
                    ['Vertical DTC', 'Education + curated looks', 'Retail markup', 'Vertical store overview & reviews'],
                    ['Amayun factory-direct', 'Repeatable construction + fair price', 'You must know the silhouette name', 'Why Amayun'],
                ]
            )
            . $F[1]
            . h2('Decision order that saves money')
            . ul([
                'Name the silhouette (mamian / ruqun / yuanling / honest New Chinese).',
                'Demand flat-lay geometry and fiber percentages.',
                'Price the service: dispute tools, styling help, or QC notes.',
                'Only then compare checkout totals including shipping and returns.',
            ])
            . $F[2]
            . h2('How to use this pillar')
            . p('Start with the marketplace and vertical overviews if you are still browsing. Jump to Why Amayun when the form is fixed and you want factory-direct value without boutique theater. Product photos on comparison pages are form references—learn panels and collars, then choose the channel that matches your risk.')
            : h2('汉服去哪买：先有决策框架')
            . p('海外买家通常在三类渠道间跳转：跨境综合平台、汉服/新中式垂直独立站、源头工厂直销目录。本支柱把这三类集群串起来，说明各自何时合理——而不是只看标价。')
            . $F[0]
            . h2('三类渠道，三种工作')
            . tableHtml(
                ['渠道', '最擅长', '短板', '接着读'],
                [
                    ['综合平台', '发现 + 支付熟悉度', '结构图稀薄', '平台总览与评测'],
                    ['垂直独立站', '教育 + 精选造型', '零售加价', '独立站总览与评测'],
                    ['阿玛云工厂直销', '可重复结构 + 公道价', '需要先懂形制名', '为什么选我们'],
                ]
            )
            . $F[1]
            . h2('省钱的决策顺序')
            . ul([
                '先定形制名（马面 / 襦裙 / 圆领 / 诚实的新中式）。',
                '要平铺几何与纤维百分比。',
                '给服务定价：纠纷工具、造型辅导，还是质检说明。',
                '最后才比含运费与退货的实付总额。',
            ])
            . $F[2]
            . h2('本支柱怎么用')
            . p('仍在浏览时先读平台与独立站总览；形制固定后跳到「为什么选我们」评估工厂直销。对比页配图是形制参考——先学光面与领型，再选匹配风险偏好的渠道。'),

        'brand_factory_hub' => $en
            ? h2('Brand & factory trust is evidence, not adjectives')
            . p('Amayun Technology Co., Ltd. (registered 2024) exists to deliver quality Hanfu and Chinese-style goods at fair prices. This hub links company story, origin partners, and manufacturing method so trust claims stay checkable.')
            . $F[0]
            . h2('Three trust pages')
            . tableHtml(
                ['Page', 'What it proves', 'What it is not'],
                [
                    ['About Amayun', 'Mission, founding year, value promise', 'Empty lifestyle manifesto'],
                    ['Partners & origins', 'Visited supply + workshop partners', 'Anonymous “factory” stock photos'],
                    ['Manufacturing', 'Handmade finishing + green mechanical lines', '“All handmade” capacity myths'],
                ]
            )
            . $F[1]
            . h2('How factory-direct connects to catalog photos')
            . p('When you read structure close-ups—mamian panels, ruqun joins, fabric sheen—you are looking at the same literacy our QC uses. Brand pages explain who makes and inspects; buying guides explain how you verify before checkout.')
            . $F[2]
            . h2('Read order for skeptical buyers')
            . ul([
                'Company story for intent and timeline.',
                'Partners for origin transparency.',
                'Manufacturing for how consistency is produced.',
                'Then open silhouette categories with flat-lay expectations intact.',
            ])
            : h2('品牌与工厂：信任要证据，不要形容词')
            . p('阿玛云科技有限公司（注册于 2024）宗旨是为客户提供物美价廉的汉服与国风产品。本支柱串联公司故事、产地伙伴与制造方式，让信任主张可核对。')
            . $F[0]
            . h2('三篇信任页')
            . tableHtml(
                ['页面', '证明什么', '不是什么'],
                [
                    ['公司故事', '宗旨、成立年份、价值承诺', '空洞生活方式宣言'],
                    ['产地与合作伙伴', '走访货源 + 车间伙伴', '匿名「工厂」库存图'],
                    ['制造方式', '手工收尾 + 绿色机械产线', '「全手工」产能神话'],
                ]
            )
            . $F[1]
            . h2('工厂直销如何连到成衣图')
            . p('当你阅读结构特写——马面光面、襦裙连接、面料光泽——你在用与质检相同的素养。品牌页说明谁在做、谁在检；购买指南说明结账前如何自检。')
            . $F[2]
            . h2('给审慎买家的阅读顺序')
            . ul([
                '公司故事：意图与时间线。',
                '合作伙伴：产地透明度。',
                '制造方式：稳定性如何被生产出来。',
                '再带着平铺预期打开形制类目。',
            ]),

        default => throw new InvalidArgumentException('Unknown type ' . $type),
    };

    return ensureLength($html . cta($locale), $locale);
}

/** @return list<array<string,mixed>> */
function articleDefs(): array
{
    return [
        [
            'category' => 'buy-compare',
            'slug' => 'where-to-buy-hanfu-decision-hub',
            'type' => 'buy_compare_hub',
            'keywords' => 'where to buy hanfu,hanfu marketplace vs dtc,汉服渠道对比',
            'zh_title' => '汉服渠道对比总枢纽：平台、独立站与工厂直销怎么选',
            'zh_excerpt' => '用一张决策框架串起跨境平台、垂直独立站与阿玛云工厂直销，先定形制再比渠道。',
            'en_title' => 'Where to Buy Hanfu: Marketplaces, DTC & Factory-Direct Hub',
            'en_excerpt' => 'One decision frame linking marketplaces, vertical stores, and Amayun factory-direct—silhouette before channel.',
        ],
        [
            'category' => 'brand-factory',
            'slug' => 'amayun-brand-factory-trust-hub',
            'type' => 'brand_factory_hub',
            'keywords' => 'Amayun factory,brand story,阿玛云品牌工厂',
            'zh_title' => '阿玛云品牌与工厂信任枢纽：故事·伙伴·制造',
            'zh_excerpt' => '把公司故事、产地伙伴与制造方式连成可核对的信任证据链，服务工厂直销转化。',
            'en_title' => 'Amayun Brand & Factory Trust Hub: Story, Partners, Making',
            'en_excerpt' => 'Link company story, origin partners, and manufacturing into checkable trust evidence.',
        ],
    ];
}

$captions = loadCaptions();
$categories = categoryIdMap();
$defs = articleDefs();
foreach ($defs as $def) {
    if (!isset($categories[$def['category']])) {
        fwrite(STDERR, 'Missing category: ' . $def['category'] . PHP_EOL);
        exit(1);
    }
}

$photoPlan = allocateAvoidingReserved(array_column($defs, 'slug'), reservedPhotoSetKeys());
$admin = ObjectManager::getInstance(BlogPostAdminService::class);
$created = 0;
$skipped = 0;

foreach ($defs as $def) {
    $files = photoFiles($photoPlan[$def['slug']]);
    if (count($files) < PER_ARTICLE) {
        throw new RuntimeException('Need photos for ' . $def['slug']);
    }
    $cover = PHOTO_BASE . '/' . $files[0];
    $categoryId = $categories[$def['category']];
    foreach ([
        'zh_Hans_CN' => ['slug' => $def['slug'], 'title' => $def['zh_title'], 'excerpt' => $def['zh_excerpt']],
        'en_US' => ['slug' => $def['slug'] . '-en', 'title' => $def['en_title'], 'excerpt' => $def['en_excerpt']],
    ] as $locale => $pack) {
        if (slugExists($pack['slug'])) {
            echo "= skip {$pack['slug']}\n";
            ++$skipped;
            continue;
        }
        $F = bodyFig($files, $locale, $captions, $pack['title']);
        $content = buildContent($def['type'], $locale, $F);
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
        echo "+ #{$id} [{$locale}] {$pack['slug']} cover={$files[0]} → {$def['category']}\n";
        ++$created;
    }
}

echo "created={$created} skipped={$skipped}\n";
