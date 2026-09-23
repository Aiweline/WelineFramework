<?php

declare(strict_types=1);

/**
 * Seed DaoCharms pendant taxonomy (intent + symbol + shape + material).
 *
 * Usage:
 *   php app/code/Weline/Product/data/seed-daocharms-categories.php
 *   php app/code/Weline/Product/data/seed-daocharms-categories.php daocharms
 *
 * Idempotent by category code. Never writes Website::ID_DEFAULT (0).
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_CODE = 'daocharms';
const LOCALES = ['zh_Hans_CN', 'en_US'];
const MEDIA_BASE = '/pub/media/catalog/daocharms/categories';

$requestedCode = trim((string)($argv[1] ?? WEBSITE_CODE));
if ($requestedCode === '' || $requestedCode === '0' || $requestedCode === 'default') {
    fwrite(STDERR, "Refuse: DaoCharms seed must target website code daocharms, not default/0.\n");
    exit(1);
}

/** @var Website $websiteModel */
$websiteModel = ObjectManager::getInstance(Website::class);
$websiteRow = $websiteModel->reset()->where(Website::schema_primary_key, 0, '>=')->select()->fetchArray();
$websiteId = 0;
foreach ($websiteRow as $row) {
    if ((string)($row['code'] ?? '') === $requestedCode) {
        $websiteId = (int)($row['website_id'] ?? 0);
        break;
    }
}
if ($websiteId <= 0) {
    fwrite(STDERR, "Website not found for code={$requestedCode}\n");
    exit(1);
}
if ($websiteId === Website::ID_DEFAULT) {
    fwrite(STDERR, "Refuse: resolved website is default (0).\n");
    exit(1);
}

/**
 * @return list<array<string, mixed>>
 */
function daocharmsTree(): array
{
    $n = static function (
        string $code,
        string $zhName,
        string $zhSummary,
        string $zhDesc,
        string $enName,
        string $enSummary,
        string $enDesc,
        array $children = [],
    ): array {
        return [
            'code' => $code,
            'i18n' => [
                'zh_Hans_CN' => [
                    'name' => $zhName,
                    'summary' => $zhSummary,
                    'description' => $zhDesc,
                ],
                'en_US' => [
                    'name' => $enName,
                    'summary' => $enSummary,
                    'description' => $enDesc,
                ],
            ],
            'children' => $children,
        ];
    };

    // Flat roots: no "All Pendants" wrapper — shop only sells pendants.
    return [
        $n(
            'intent-balance-harmony',
            '阴阳平衡',
            '日常调和与中心感的佩戴仪式。',
            '适合作为一天里提醒自己找回中心的小物件。',
            'Balance & Harmony',
            'Everyday balance and centered dualities.',
            'A quiet wearable cue for finding center in a busy day.',
        ),
        $n(
            'intent-calm-wellness',
            '静心安康',
            '静心、安康的象征表达（非医疗）。',
            '用材质与造型营造平静感；不宣称治疗或诊断。',
            'Calm & Wellness',
            'Calm, reflective wellness symbolism—not medical claims.',
            'Objects that invite quiet body awareness. Not a cure, treatment, or diagnosis.',
        ),
        $n(
            'intent-practice-training',
            '修习训练',
            '太极、冥想等修习时的陪伴吊坠。',
            '为练习时段提供可触摸的提醒物，而非运动装备品类。',
            'Practice & Training',
            'Companions for Tai Chi, meditation, and mindful practice.',
            'A tactile cue for practice sessions—not sports gear.',
        ),
        $n(
            'intent-grounding-boundaries',
            '安稳边界',
            '安稳、边界与向内反思的象征。',
            '常见于黑曜石等深色石材吊坠的仪式表述。',
            'Grounding & Boundaries',
            'Grounding, boundaries, and inward reflection.',
            'Symbolic language around steadiness and personal boundaries.',
        ),
        $n(
            'intent-focus-clarity',
            '专注清明',
            '学习或工作开始前的专注仪式。',
            '提醒放慢节奏、理清意图，不作提神药效承诺。',
            'Focus & Clarity',
            'Clarity cues for study or work rituals.',
            'A reminder to begin with intention—not a stimulant claim.',
        ),
        $n(
            'by-symbol',
            '按图腾',
            '按符号与图腾浏览（导入主分类）。',
            '每款吊坠主挂一个最具体的图腾分类。',
            'By Symbol',
            'Browse by symbolic motif (primary import category).',
            'Assign exactly one most-specific symbol as the primary category.',
            [
                $n('symbol-yinyang', '阴阳 / 太极', '阴阳鱼与太极图。', '视觉主体为阴阳鱼或太极图的吊坠。', 'Yin-Yang', 'Yin-yang and taiji motifs.', 'Pendants whose visual focus is the yin-yang / taiji mark.'),
                $n('symbol-bagua', '八卦', '八卦牌与卦象环。', '八卦牌、九宫八卦等形制。', 'Bagua', 'Bagua plaques and trigram rings.', 'Eight-trigram plaques and related bagua forms.'),
                $n('symbol-five-elements', '五行', '金木水火土意象。', '五行刻纹或五色石组合的吊坠（非手串）。', 'Five Elements', 'Five-elements inspired pendants.', 'Element motifs or five-tone stone compositions as pendants—not bracelets.'),
                $n('symbol-peace-disc', '平安扣 / 璧', '圆环怀古与平安扣。', '圆形中空或璧式吊坠。', 'Peace Disc', 'Round peace-disc / bi forms.', 'Circular bi-style or peace-disc pendants.'),
                $n('symbol-plain-tablet', '无事牌', '素面牌型吊坠。', '少纹或无纹的长方/椭圆牌。', 'Plain Tablet', 'Minimal tablet plaques.', 'Plain rectangular or oval tablets with little or no carving.'),
            ],
        ),
        $n(
            'by-shape',
            '按塑形',
            '按造型轮廓选择（编辑商品时可勾选）。',
            '圆、牌、水滴、尖锥、立体雕件等物理塑形。',
            'By Shape',
            'Choose by silhouette—selectable when editing products.',
            'Physical shapes: disc, tablet, drop, point, carved motif.',
            [
                $n('shape-round-disc', '圆 / 扣', '圆形或平安扣式外轮廓。', '正圆、怀古扣等圆盘造型。', 'Round / Disc', 'Circular or peace-disc outline.', 'Round discs and bi-style outlines.'),
                $n('shape-tablet', '牌型', '扁牌、长方或椭圆牌。', '适合八卦牌、无事牌等扁片造型。', 'Tablet', 'Flat rectangular or oval plaques.', 'Flat tablets common for bagua and plain plaques.'),
                $n('shape-drop', '水滴', '水滴 / 泪滴轮廓。', '下尖上圆的水滴吊坠。', 'Drop', 'Teardrop silhouette.', 'Teardrop / drop-shaped pendants.'),
                $n('shape-point', '尖锥 / 柱', '尖锥、柱状或原矿尖。', '向上或向下的尖锥造型。', 'Point', 'Point, pillar, or crystal tip.', 'Obelisk, pillar, or pointed crystal forms.'),
                $n('shape-carved-motif', '立体雕件', '立体雕花或异形雕件。', '鱼、如意等立体感较强的雕件。', 'Carved Motif', 'Dimensional carved forms.', 'Sculpted motifs such as fish, ruyi, and other dimensional carvings.'),
            ],
        ),
        $n(
            'by-material',
            '按材质',
            '按主材质筛选。',
            '每款挂一个主材质分类。',
            'By Material',
            'Filter by primary material.',
            'Assign exactly one primary material category.',
            [
                $n('material-obsidian', '黑曜石', '火山玻璃黑曜石。', '首波主推天然黑曜石吊坠。', 'Black Obsidian', 'Volcanic glass obsidian.', 'Primary first-wave natural black obsidian pendants.'),
                $n('material-jade', '玉石系', '和田玉、翡翠等玉质。', '温润玉石系吊坠。', 'Jade Family', 'Nephrite / jadeite family.', 'Warm jade-family pendants.'),
                $n('material-crystal', '水晶 / 石英族', '水晶与石英族。', '透明或半透明石英水晶吊坠。', 'Quartz & Crystal', 'Quartz-family crystals.', 'Clear or translucent quartz-family pendants.'),
                $n('material-other-stone', '其他天然石', '玛瑙及其他天然石。', '非黑曜石/玉/水晶主类的天然石。', 'Other Stone', 'Agate and other natural stones.', 'Natural stones outside obsidian, jade, and quartz primaries.'),
                $n('material-alloy', '合金点缀', '石/玉为主体、合金为配件。', '坠体仍以石玉为主，合金仅作托/环点缀。', 'Alloy Accents', 'Alloy findings on stone/jade bodies.', 'Stone or jade body with alloy bail or accent only.'),
            ],
        ),
    ];
}

/**
 * @return array<string, int> code => category_id
 */
function indexCodesById(
    CategoryRepository $categories,
    ProductCategoryAttributeService $attributes,
    int $websiteId,
): array {
    $ids = [];
    foreach ($categories->listAll($websiteId) as $row) {
        $id = (int)($row['category_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        return [];
    }
    $map = $attributes->readCodeMap($websiteId, $ids, 'en_US');
    $zhMap = $attributes->readCodeMap($websiteId, $ids, 'zh_Hans_CN');
    $out = [];
    foreach ($ids as $id) {
        $code = strtolower(trim((string)($map[$id] ?? '')));
        if ($code === '') {
            $code = strtolower(trim((string)($zhMap[$id] ?? '')));
        }
        if ($code !== '') {
            $out[$code] = $id;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $nodes
 * @param array<string, int> $codeIndex
 */
function seedNodes(
    ProductCategoryAdminService $admin,
    ProductCategoryAttributeService $attributes,
    CategoryRepository $categories,
    int $websiteId,
    array $nodes,
    int $parentId,
    array &$codeIndex,
): int {
    $count = 0;
    foreach ($nodes as $node) {
        $code = strtolower(trim((string)($node['code'] ?? '')));
        $i18n = is_array($node['i18n'] ?? null) ? $node['i18n'] : [];
        $zh = $i18n['zh_Hans_CN'] ?? null;
        $en = $i18n['en_US'] ?? null;
        if (!is_array($zh) || trim((string)($zh['name'] ?? '')) === '') {
            throw new RuntimeException('missing zh name for ' . $code);
        }
        if (!is_array($en) || trim((string)($en['name'] ?? '')) === '') {
            throw new RuntimeException('missing en name for ' . $code);
        }

        $existingId = (int)($codeIndex[$code] ?? 0);
        $icon = MEDIA_BASE . '/icons/' . $code . '.webp';
        $banner = MEDIA_BASE . '/banners/' . $code . '.webp';
        $created = $admin->save(
            $websiteId,
            $existingId,
            $parentId,
            (string)$en['name'],
            'active',
            $code,
            'en_US',
            null,
            is_file(dirname(__DIR__, 4) . $icon) ? $icon : null,
            is_file(dirname(__DIR__, 4) . $banner) ? $banner : null,
            (string)($en['summary'] ?? ''),
            (string)($en['description'] ?? ''),
        );
        $categoryId = (int)($created['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('failed upsert ' . $code);
        }

        $attributes->writeName($websiteId, $categoryId, (string)$zh['name'], 'zh_Hans_CN');
        $attributes->writeSummary($websiteId, $categoryId, (string)($zh['summary'] ?? ''), 'zh_Hans_CN');
        $attributes->writeDescription($websiteId, $categoryId, (string)($zh['description'] ?? ''), 'zh_Hans_CN');
        $attributes->writeCode($websiteId, $categoryId, $code, 'zh_Hans_CN');

        $codeIndex[$code] = $categoryId;
        $action = $existingId > 0 ? '~' : '+';
        echo "{$action} #{$categoryId} {$code} {$zh['name']} / {$en['name']}" . PHP_EOL;
        ++$count;

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children !== []) {
            $count += seedNodes($admin, $attributes, $categories, $websiteId, $children, $categoryId, $codeIndex);
        }
    }

    return $count;
}

$admin = ObjectManager::getInstance(ProductCategoryAdminService::class);
$attributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);
$categories = ObjectManager::getInstance(CategoryRepository::class);

$codeIndex = indexCodesById($categories, $attributes, $websiteId);
$total = seedNodes($admin, $attributes, $categories, $websiteId, daocharmsTree(), 0, $codeIndex);

echo json_encode([
    'ok' => true,
    'website_id' => $websiteId,
    'website_code' => $requestedCode,
    'upserted' => $total,
    'codes' => array_keys($codeIndex),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
