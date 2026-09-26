<?php

declare(strict_types=1);

/**
 * #543 长乐公主 · 唐制褙子 + 齐腰八破裙 · 详情强制重做（槽②）
 *
 * 卖点表：
 * | 卖点 | 证据 | 原型 |
 * | 唐制褙子开合 | 全身/半身实拍 | poem-aside + feature |
 * | 齐腰八破裙幅 | 裙摆垂落实拍 | pair + stack |
 * | 婚庆喜色气韵 | 喜服红系变体轴 | editorial + bento |
 * | 细部面料褶影 | 近景绣纹/褶 | pair macro |
 *
 * 原型：lead → verse → poem-aside → feature → solo set → pair looks → prose
 *       → pair macro → quiet → bento → pair more → quiet → checklist
 *       → product-info → size note → original → close
 *
 * php app/code/Weline/Product/scripts/beautify-product-543-detail-layout.php --dry-run
 * php app/code/Weline/Product/scripts/beautify-product-543-detail-layout.php --apply --website=0
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = 543;

$shortZh = '长乐公主 · 唐制褙子';
$shortEn = 'Princess Changle · Tang-style Beizi';

/** @var array<string, array{id:string,w:int,h:int}> $A */
$A = [
    'hero' => ['id' => 'f0051003-2a18-4c2f-9753-1bae6ab6c590', 'w' => 790, 'h' => 955],
    'look_cut' => ['id' => '92148abe-e1c0-4fab-a6b2-5abc5bc6c24b', 'w' => 1200, 'h' => 1200],
    'look_set' => ['id' => 'edfbd386-fbd5-486b-b125-4117d7c94e51', 'w' => 1200, 'h' => 1200],
    'look_a' => ['id' => '57862585-4178-4fd4-8304-67fc177ae231', 'w' => 790, 'h' => 973],
    'look_b' => ['id' => 'eed772b8-b603-40fc-a6de-8bdded38f664', 'w' => 776, 'h' => 1460],
    'macro_a' => ['id' => '6dc67088-90a9-455d-af7e-8d0c71bc2ff5', 'w' => 790, 'h' => 970],
    'macro_b' => ['id' => '64a07cdb-651f-476d-b20f-065b9526bfb5', 'w' => 1200, 'h' => 1200],
    'look_c' => ['id' => '147ccb35-c57c-4194-b3c6-7168e9c26618', 'w' => 1200, 'h' => 1200],
    'look_d' => ['id' => '90ce57b0-ac49-49a4-96cf-071a7cbeb5b3', 'w' => 1200, 'h' => 1200],
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$img = static function (array $a, string $alt) use ($h): string {
    return '<img src="asset://' . $h((string)$a['id']) . '" alt="' . $h($alt)
        . '" loading="lazy" decoding="async" width="' . (int)$a['w'] . '" height="' . (int)$a['h'] . '">';
};

$figureStack = static function (array $imgs, string $mod = '') use ($h): string {
    $rows = '';
    $n = count($imgs);
    if ($n >= 3) {
        $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--triptych">';
        foreach ($imgs as $one) {
            $rows .= '<div class="weline-detail-figure">' . $one . '</div>';
        }
        $rows .= '</div>';
    } else {
        for ($i = 0; $i < $n; $i += 2) {
            if ($i + 1 < $n) {
                $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--pair">'
                    . '<div class="weline-detail-figure">' . $imgs[$i] . '</div>'
                    . '<div class="weline-detail-figure">' . $imgs[$i + 1] . '</div>'
                    . '</div>';
            } else {
                $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--solo">'
                    . '<div class="weline-detail-figure">' . $imgs[$i] . '</div>'
                    . '</div>';
            }
        }
    }
    $cls = 'weline-detail-figure-stack' . ($mod !== '' ? ' ' . $mod : '');

    return '<div class="' . $h($cls) . '">' . $rows . '</div>';
};

$tagReveal = static function (string $html): string {
    $out = preg_replace_callback(
        '/<(div|section)\s+class="(weline-detail-(?:prose|feature|figure-stack|figure-row|bento|text|quiet-spacer)[^"]*)"/i',
        static function (array $m): string {
            $tag = $m[1];
            $cls = $m[2];
            if (str_contains($cls, 'weline-detail-reveal')) {
                return '<' . $tag . ' class="' . $cls . '"';
            }

            return '<' . $tag . ' class="' . $cls . ' weline-detail-reveal" data-weline-detail-reveal="1"';
        },
        $html
    );

    return is_string($out) ? $out : $html;
};

$copy = [];

$copy['zh_Hans_CN'] = [
    'intro_title' => $shortZh,
    'intro_body' => '唐制褙子配齐腰八破裙：开合有度、裙幅垂落，喜服气韵以本店实拍为准。衣长、绣纹与颜色请对照规格轴，不袭货盘腔调。',
    'inspire_title' => '设计心源',
    'inspire_lines' => [
        '以「长乐」为题，写唐制褙子的端庄与八破裙的流动。',
        '婚庆喜色可走，日常亦可：形制清楚，气韵不虚。',
    ],
    'inspire_note' => '题眼在形制与色韵；纹样细节请近观实拍。',
    'poem_eyebrow' => '诗意旁笺',
    'poem_lines' => ['长乐承唐', '褙子开合', '八破垂光'],
    'look_title' => '通身气韵',
    'look_body' => '全身与半身交叉铺陈：褙子领缘层次、袖袂开合、齐腰裙幅垂落一眼可读。喜服红系等变体以规格图为准。',
    'look_note' => '颜色与烫纸纹样请对照规格轴实拍，勿凭想象补色。',
    'set_title' => '套装全貌',
    'set_body' => '褙子与八破裙同框：上下呼应、腰线清楚，婚庆礼仪与日常礼见皆可落。',
    'macro_title' => '细处可辨',
    'macro_body' => '近景可见面料褶影、纹样走向与缝线层次；以图为证，不编造不可见图参数。',
    'bento_title' => '衣袂可记',
    'bento_style_label' => '制式',
    'bento_style' => '唐制',
    'bento_parts_label' => '部件',
    'bento_parts' => '褙子 · 齐腰八破裙',
    'bento_a' => '喜服气韵以实拍色系为准',
    'bento_b' => '尺码按规格轴胸围身高挑选',
    'quiet_line' => '衣在身上，喜在眉间。',
    'checklist_title' => '护衣小笺',
    'checklist' => [
        '建议手洗、分色洗涤，不可漂白。',
        '悬挂晾干，避暴晒；低温熨烫，垫布为佳。',
        '褙子与裙幅宜轻挂，避免长期重压折痕。',
    ],
    'info_title' => '形制一览',
    'info_basics' => '基本',
    'info_comfort' => '穿着感受',
    'label_brand' => '品牌',
    'label_name' => '品名',
    'label_color' => '颜色',
    'label_style' => '制式',
    'label_size' => '尺码',
    'label_fabric' => '面料',
    'label_parts' => '部件',
    'info_brand' => '长安汉服',
    'info_name' => $shortZh,
    'info_color' => '如图（规格轴可选，含喜色系）',
    'info_style' => '唐制',
    'info_size' => '见规格轴尺码',
    'info_fabric' => '精选面料（以实拍质感为准）',
    'info_parts' => '褙子、齐腰八破裙',
    'c_thick' => '厚度',
    'c_thick_opts' => ['薄', '适中', '厚'],
    'c_thick_sel' => '适中',
    'c_fit' => '版型',
    'c_fit_opts' => ['修身', '合身', '宽松'],
    'c_fit_sel' => '合身',
    'c_soft' => '手感',
    'c_soft_opts' => ['偏软', '适中', '偏硬'],
    'c_soft_sel' => '偏软',
    'c_stretch' => '弹性',
    'c_stretch_opts' => ['无弹', '微弹', '高弹'],
    'c_stretch_sel' => '无弹',
    'size_title' => '尺码参照',
    'size_body' => '请按规格轴尺码与自身胸围、身高挑选；手工测量或有 1–3 cm 出入，以实物为准。本品无独立烤图尺码表，以规格轴为准。',
    'original_title' => '原创心迹',
    'original_body' => '敬请珍惜衣冠、尊重匠心；唐制褙子与八破裙形制以本店实拍为准。',
    'close_caption' => $shortZh . ' · 古风婚庆',
    'alt_hero' => $shortZh . ' · 着装',
    'alt_cut' => $shortZh . ' · 形制',
    'alt_set' => $shortZh . ' · 套装',
    'alt_look' => $shortZh . ' · 着装',
    'alt_macro' => $shortZh . ' · 细部',
    'lang' => 'zh-Hans',
];

$copy['en_US'] = [
    'intro_title' => $shortEn,
    'intro_body' => 'Tang-style Beizi with a waist-high eight-panel skirt: measured open-close, falling panels—wedding air follows our studio shoots. Match length, motifs, and color to the variant axis—no wholesale pitch.',
    'inspire_title' => 'Design wellspring',
    'inspire_lines' => [
        'Named for Changle: the poised Beizi and the flowing eight-panel skirt.',
        'Wedding reds can walk; daily rites can too—clear cut, honest air.',
    ],
    'inspire_note' => 'The motif is cut and color; read motifs up close in the photos.',
    'poem_eyebrow' => 'Side verse',
    'poem_lines' => ['Changle holds Tang', 'Beizi opens', 'Eight panels fall'],
    'look_title' => 'Full-body rhythm',
    'look_body' => 'Full and mid shots cross so collar layers, sleeve open-close, and waist-high panels read at a glance. Wedding reds follow the variant photos.',
    'look_note' => 'Match colorways and stamped motifs to the size/style axis—do not invent hues.',
    'set_title' => 'Full set',
    'set_body' => 'Beizi and eight-panel skirt in one frame: upper and lower echo, waist line clear—fit for rites and quieter days.',
    'macro_title' => 'Readable up close',
    'macro_body' => 'Macro frames show fold depth, motif direction, and seam layers—evidence only, no invented specs.',
    'bento_title' => 'Worth noting',
    'bento_style_label' => 'Cut',
    'bento_style' => 'Tang-style',
    'bento_parts_label' => 'Parts',
    'bento_parts' => 'Beizi · waist-high eight-panel skirt',
    'bento_a' => 'Wedding air follows studio colorways',
    'bento_b' => 'Pick size by bust and height on the axis',
    'quiet_line' => 'Cloth on the body; joy at the brow.',
    'checklist_title' => 'Care notes',
    'checklist' => [
        'Hand wash separately; do not bleach.',
        'Hang dry; avoid harsh sun; low iron with a cloth.',
        'Hang Beizi and panels lightly—avoid long heavy creasing.',
    ],
    'info_title' => 'At a glance',
    'info_basics' => 'Basics',
    'info_comfort' => 'Feel',
    'label_brand' => 'Brand',
    'label_name' => 'Name',
    'label_color' => 'Color',
    'label_style' => 'Style',
    'label_size' => 'Size',
    'label_fabric' => 'Fabric',
    'label_parts' => 'Parts',
    'info_brand' => "Chang'an Hanfu",
    'info_name' => $shortEn,
    'info_color' => 'As shown (variant axis; wedding reds available)',
    'info_style' => 'Tang-style',
    'info_size' => 'See variant axis sizes',
    'info_fabric' => 'Selected fabric (hand follows studio photos)',
    'info_parts' => 'Beizi, waist-high eight-panel skirt',
    'c_thick' => 'Thickness',
    'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
    'c_thick_sel' => 'Medium',
    'c_fit' => 'Fit',
    'c_fit_opts' => ['Slim', 'Regular', 'Relaxed'],
    'c_fit_sel' => 'Regular',
    'c_soft' => 'Hand',
    'c_soft_opts' => ['Softer', 'Medium', 'Firmer'],
    'c_soft_sel' => 'Softer',
    'c_stretch' => 'Stretch',
    'c_stretch_opts' => ['None', 'Slight', 'High'],
    'c_stretch_sel' => 'None',
    'size_title' => 'Sizing',
    'size_body' => 'Match the variant axis to bust and height; hand measure may vary by 1–3 cm—the garment wins. No separate baked size-chart image; trust the axis.',
    'original_title' => 'Original craft',
    'original_body' => 'Honor the cut and craft; Tang Beizi and eight-panel silhouette follow our studio photos.',
    'close_caption' => $shortEn . ' · wedding Tang air',
    'alt_hero' => $shortEn . ' · worn',
    'alt_cut' => $shortEn . ' · silhouette',
    'alt_set' => $shortEn . ' · set',
    'alt_look' => $shortEn . ' · worn',
    'alt_macro' => $shortEn . ' · detail',
    'lang' => 'en',
];

$assemble = static function (array $t) use ($A, $img, $figureStack, $h): string {
    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $poem = '<div class="weline-detail-feature weline-detail-feature--poem-aside weline-detail-orient--portrait">'
        . '<div class="weline-detail-feature__copy"><div class="weline-detail-prose weline-detail-prose--verse-vertical" lang="'
        . $h((string)$t['lang']) . '"><span class="weline-detail-prose__eyebrow">' . $h((string)$t['poem_eyebrow']) . '</span>';
    foreach ((array)$t['poem_lines'] as $line) {
        $poem .= '<p>' . $h((string)$line) . '</p>';
    }
    $poem .= '</div></div><div class="weline-detail-feature__media">'
        . $img($A['hero'], (string)$t['alt_hero']) . '</div></div>';

    $feature = '<div class="weline-detail-feature weline-detail-feature--reverse weline-detail-orient--squareish">'
        . '<div class="weline-detail-feature__media">' . $img($A['look_cut'], (string)$t['alt_cut']) . '</div>'
        . '<div class="weline-detail-feature__copy"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p><p class="weline-detail-feature__note">'
        . $h((string)$t['look_note']) . '</p></div></div>';

    $soloSet = $figureStack([
        $img($A['look_set'], (string)$t['alt_set']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--squareish')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['set_title']) . '</h3><p>'
        . $h((string)$t['set_body']) . '</p></div>';

    $pairLooks = $figureStack([
        $img($A['look_a'], (string)$t['alt_look'] . ' 1'),
        $img($A['look_b'], (string)$t['alt_look'] . ' 2'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p></div>';

    $pairMacro = $figureStack([
        $img($A['macro_a'], (string)$t['alt_macro'] . ' 1'),
        $img($A['macro_b'], (string)$t['alt_macro'] . ' 2'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose weline-detail-prose--macro"><h3>' . $h((string)$t['macro_title']) . '</h3><p>'
        . $h((string)$t['macro_body']) . '</p></div>';

    $quiet = '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>';

    $bento = '<div class="weline-detail-bento"><h3 class="weline-detail-bento__heading">' . $h((string)$t['bento_title']) . '</h3>'
        . '<div class="weline-detail-bento__grid">'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--accent weline-detail-bento__card--kv"><h4>'
        . $h((string)$t['bento_style_label']) . '</h4><p>' . $h((string)$t['bento_style']) . '</p></div>'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--kv"><h4>'
        . $h((string)$t['bento_parts_label']) . '</h4><p>' . $h((string)$t['bento_parts']) . '</p></div>'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--wide"><h4>' . $h((string)$t['bento_a']) . '</h4></div>'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--wide"><h4>' . $h((string)$t['bento_b']) . '</h4></div>'
        . '</div></div>';

    $pairMore = $figureStack([
        $img($A['look_c'], (string)$t['alt_look'] . ' 3'),
        $img($A['look_d'], (string)$t['alt_look'] . ' 4'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><p>' . $h((string)$t['look_body']) . '</p></div>';

    $quietLine = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';

    $wash = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ((array)$t['checklist'] as $line) {
        $wash .= '<li>' . $h((string)$line) . '</li>';
    }
    $wash .= '</ul></div>';

    $info = DetailDescriptionTextifier::buildProductInfoPanelZh(
        [
            (string)$t['label_brand'] => (string)$t['info_brand'],
            (string)$t['label_name'] => (string)$t['info_name'],
            (string)$t['label_color'] => (string)$t['info_color'],
            (string)$t['label_style'] => (string)$t['info_style'],
            (string)$t['label_size'] => (string)$t['info_size'],
            (string)$t['label_fabric'] => (string)$t['info_fabric'],
            (string)$t['label_parts'] => (string)$t['info_parts'],
        ],
        [
            ['label' => (string)$t['c_thick'], 'options' => (array)$t['c_thick_opts'], 'selected' => (string)$t['c_thick_sel']],
            ['label' => (string)$t['c_fit'], 'options' => (array)$t['c_fit_opts'], 'selected' => (string)$t['c_fit_sel']],
            ['label' => (string)$t['c_soft'], 'options' => (array)$t['c_soft_opts'], 'selected' => (string)$t['c_soft_sel']],
            ['label' => (string)$t['c_stretch'], 'options' => (array)$t['c_stretch_opts'], 'selected' => (string)$t['c_stretch_sel']],
        ],
        (string)$t['info_title'],
        (string)$t['info_basics'],
        (string)$t['info_comfort'],
    );

    $sizeNote = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';
    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';
    $close = '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weds="xq">'
        . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
        . $intro . $inspire . $poem . $feature . $soloSet . $pairLooks . $pairMacro
        . $quiet . $bento . $pairMore . $quietLine . $wash . $info . $sizeNote . $original . $close
        . '</div>';
};

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'] ?? '127.0.0.1', $db['hostport'] ?? '5432', $db['database'] ?? ''),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$enabled = $pdo->query("SELECT language_code FROM w_weline_websites_website_language WHERE website_id={$websiteId} ORDER BY language_code")
    ->fetchAll(PDO::FETCH_COLUMN);
$enabled = array_values(array_filter(array_map('strval', $enabled)));
if ($enabled === []) {
    $enabled = ['zh_Hans_CN', 'en_US'];
}

$zhHtml = $tagReveal($assemble($copy['zh_Hans_CN']));
$enHtml = $tagReveal($assemble($copy['en_US']));

$writes = [
    ['locale' => 'zh_Hans_CN', 'html' => $zhHtml],
    ['locale' => 'en_US', 'html' => $enHtml],
    ['locale' => '', 'html' => $enHtml],
];

$stAll = $pdo->prepare(
    "SELECT locale, COALESCE(value_text, value_string) AS html
     FROM w_product_ws_0_attribute_value
     WHERE entity_id=? AND attribute_code='description'"
);
$stAll->execute([(string)$productId]);
$existing = [];
foreach ($stAll as $row) {
    $existing[(string)$row['locale']] = (string)$row['html'];
}

foreach ($enabled as $locale) {
    if ($locale === 'zh_Hans_CN' || $locale === 'en_US') {
        continue;
    }
    $html = $existing[$locale] ?? '';
    if ($html === '' || (!str_contains($html, 'weline-detail-feature') && !str_contains($html, 'data-weds'))) {
        echo "WARN locale {$locale}: no magazine HTML, using en_US structure (slot③ should true-translate)\n";
        $html = $enHtml;
    } else {
        if (!str_contains($html, 'data-weds')) {
            if (preg_match('/^<div\b[^>]*>/', $html)) {
                $html = preg_replace('/^<div\b/', '<div data-weds="xq"', $html, 1) ?? $html;
                if (!str_contains($html, '<!--weds:xq-->')) {
                    $html = preg_replace(
                        '/^(<div\b[^>]*>)/',
                        '$1<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>',
                        $html,
                        1
                    ) ?? $html;
                }
            } else {
                $html = '<div data-weline-product-description="1688" data-weds="xq"><!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
                    . $html . '</div>';
            }
        }
        // Soft upgrade poem side if still generic note-as-verse
        $html = preg_replace(
            '#(<div class="weline-detail-prose weline-detail-prose--verse-vertical"[^>]*>.*?<[^>]+class="weline-detail-prose__eyebrow"[^>]*>.*?</[^>]+>)\s*<p>[^<]*</p>#us',
            '$1<p>Changle · Tang</p><p>Beizi opens</p><p>Panels fall</p>',
            $html,
            1
        ) ?? $html;
        $html = $tagReveal($html);
    }
    $writes[] = ['locale' => $locale, 'html' => $html];
}

echo 'plan writes=' . count($writes) . ' apply=' . ($apply ? 'yes' : 'dry-run') . "\n";
foreach ($writes as $w) {
    $loc = $w['locale'] === '' ? '(empty)' : $w['locale'];
    $okStruct = str_contains($w['html'], 'poem-aside')
        && str_contains($w['html'], 'figure-row--pair')
        && str_contains($w['html'], 'prose--lead')
        && str_contains($w['html'], 'prose--checklist')
        && str_contains($w['html'], 'data-weline-detail-reveal');
    echo "  {$loc} len=" . strlen($w['html']) . ' struct=' . ($okStruct ? 'PASS' : 'FAIL') . "\n";
    if (!$okStruct && in_array($w['locale'], ['zh_Hans_CN', 'en_US', ''], true)) {
        fwrite(STDERR, "structure fail for {$loc}\n");
        exit(2);
    }
}

if (!$apply) {
    echo "dry-run only\n";
    exit(0);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    if ($locale !== '') {
        \Weline\Product\Model\Product\LocalDescription::upsertQuiet($productId, $locale, [
            \Weline\Product\Model\Product\LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
    echo 'wrote description ' . ($locale === '' ? '(empty)' : $locale) . ' len=' . strlen($html) . "\n";
}

try {
    /** @var ProductStorefrontCacheInvalidator $inv */
    $inv = ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class);
    $inv->invalidateProduct($productId, $websiteId);
} catch (Throwable $e) {
    echo 'cache invalidate soft-fail: ' . $e->getMessage() . "\n";
}
try {
    /** @var StorefrontCatalogCacheCoordinator $coord */
    $coord = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
    $coord->notifyCatalogChanged($websiteId, 'detail_suite_p543', ['product_id' => $productId]);
} catch (Throwable $e) {
    echo 'catalog notify soft-fail: ' . $e->getMessage() . "\n";
}

echo "DONE product {$productId}\n";
