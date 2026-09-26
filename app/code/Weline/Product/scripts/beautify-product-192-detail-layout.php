<?php

declare(strict_types=1);

/**
 * #192 花朝记【洛川】唐制齐胸诃子裙婚服 · 详情杂志排版强制重做
 *
 * 闸因：已有 data-weds 但 §5.4 无 reveal；尺码/试穿 HTML 误塞 feature/figure 媒体槽。
 *
 * 分流（详情内）：
 * | 资产 | 类型 | 动作 |
 * | a3d978d9 / 72b4e027 / 7e43edda / cddbae47 / d994e09a / fe6dc8bb / 52b5117f / 02134a98 | photo | 入杂志 |
 * | 188a08ee | info_chart 诃子裙 | textify→HTML，禁入图槽 |
 * | d170f95d / d4afc742 / 1e9a2883 | caption_board / care | 抽文删图 |
 *
 * 卖点：齐胸诃子金绣 · 朱红大袖绣纹 · 裙头绣花飘片 · 香槟褶裙 · 婚服气场
 * 原型：lead → verse → poem-aside → feature → size → pair → prose → pair → quiet → bento → checklist → tryon → info → close
 *
 * 父槽②：可落源语/结构（zh+en 真写；其它启用语先落源语结构，翻译交③）
 *
 * php app/code/Weline/Product/scripts/beautify-product-192-detail-layout.php --dry-run
 * php app/code/Weline/Product/scripts/beautify-product-192-detail-layout.php --apply --website=0
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = 192;

$fullZhName = '花朝记【洛川】唐制齐胸诃子裙婚服套装';
$shortZh = '洛川 · 唐制齐胸诃子裙婚服';
$shortEn = 'Luochuan · Tang chest-high Hezi bridal set';

/** @var array<string, array{id:string,w:int,h:int}> $A */
$A = [
    'hero' => ['id' => 'a3d978d9-71ae-4250-97c8-c6a1e4869243', 'w' => 750, 'h' => 1068],
    'feature' => ['id' => '72b4e027-60aa-4c19-b357-dee7ab04088e', 'w' => 642, 'h' => 950],
    'look_a' => ['id' => '7e43edda-d8b2-4ede-b95b-3f974fd78a78', 'w' => 701, 'h' => 1055],
    'look_b' => ['id' => 'cddbae47-9a2e-4476-b42d-bb3daff5f35f', 'w' => 704, 'h' => 1042],
    'look_c' => ['id' => 'd994e09a-4ef1-4c32-9c3e-c6876c1a2159', 'w' => 750, 'h' => 1094],
    'look_back' => ['id' => 'fe6dc8bb-6f4d-4813-832d-01bf6aa520eb', 'w' => 750, 'h' => 1148],
    'macro_sit' => ['id' => '52b5117f-391c-41d7-8b8a-079f749bbd05', 'w' => 633, 'h' => 986],
    'macro_stand' => ['id' => '02134a98-2b50-41c8-ad28-82918316d8e7', 'w' => 750, 'h' => 1008],
];

$sizeTables = [
    [
        'title_zh' => '诃子裙',
        'title_en' => 'Hezi skirt',
        'headers_zh' => ['尺码', '裙长（不含裙头）', '裙头', '最大胸围', '裙摆'],
        'headers_en' => ['Size', 'Skirt length (excl. band)', 'Band', 'Max bust', 'Hem'],
        'rows_zh' => [
            ['XS', '106', '19', '80', '406'],
            ['S', '109', '19', '84', '410'],
            ['M', '112', '19', '88', '414'],
            ['L', '115', '19', '92', '418'],
            ['XL', '118', '19', '96', '422'],
        ],
        'rows_en' => [
            ['XS', '106', '19', '80', '406'],
            ['S', '109', '19', '84', '410'],
            ['M', '112', '19', '88', '414'],
            ['L', '115', '19', '92', '418'],
            ['XL', '118', '19', '96', '422'],
        ],
    ],
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$img = static function (array $a, string $alt) use ($h): string {
    return '<img src="asset://' . $h((string)$a['id']) . '" alt="' . $h($alt)
        . '" loading="lazy" decoding="async" width="' . (int)$a['w'] . '" height="' . (int)$a['h'] . '">';
};

$figureStack = static function (array $imgs, string $mod = ''): string {
    $rows = '';
    $n = count($imgs);
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
    $cls = 'weline-detail-figure-stack' . ($mod !== '' ? ' ' . $mod : '');

    return '<div class="' . $cls . '">' . $rows . '</div>';
};

$tagReveal = static function (string $html): string {
    $out = preg_replace_callback(
        '/<(div)\s+class="(weline-detail-(?:prose|feature|figure-stack|figure-row|bento|text|quiet-spacer)[^"]*)"/i',
        static function (array $m): string {
            $cls = $m[2];
            if (str_contains($cls, 'weline-detail-reveal')) {
                return '<div class="' . $cls . '"';
            }

            return '<div class="' . $cls . ' weline-detail-reveal" data-weline-detail-reveal="1"';
        },
        $html
    );

    return is_string($out) ? $out : $html;
};

$sizeChartFor = static function (string $locale) use ($sizeTables): string {
    $isZh = str_starts_with($locale, 'zh');
    $tables = [];
    foreach ($sizeTables as $t) {
        $tables[] = [
            'title' => $isZh ? $t['title_zh'] : $t['title_en'],
            'headers' => $isZh ? $t['headers_zh'] : $t['headers_en'],
            'rows' => $isZh ? $t['rows_zh'] : $t['rows_en'],
        ];
    }
    if ($isZh) {
        return DetailDescriptionTextifier::buildMeasurementSizeChartZh(
            $tables,
            '尺码参考表',
            '面料：聚酯纤维。下裙印花压皱，裙头绣花，后背松紧；轻薄飘逸，附防走光里衬。单位：厘米。裙摆为下摆周长。手工测量或有 1–3 cm 误差。'
        );
    }

    return DetailDescriptionTextifier::buildMeasurementSizeChartEn(
        $tables,
        'Size reference chart',
        'Fabric: polyester. Printed/pleated skirt, embroidered band, elastic back; light with anti-show lining. Unit: cm. Hem is circumference. Hand measure may vary 1–3 cm.'
    );
};

/**
 * @return array<string, mixed>
 */
$baseCopy = static function (string $locale) use ($shortZh, $shortEn, $sizeChartFor): array {
    $packs = [];

    $packs['zh_Hans_CN'] = [
        'intro_title' => $shortZh,
        'intro_body' => '唐制齐胸诃子裙婚服套装：朱红大袖、金绣诃子、香槟褶裙与绣花飘片。以本店实拍为准，细节可近观。',
        'inspire_title' => '设计心源',
        'inspire_lines' => [
            '以「洛川」为题——齐胸承礼，大袖生风，绣在诃子与裙头。',
            '朱砂映金、香槟褶影；婚服气场端庄，不袭货盘腔调。',
        ],
        'inspire_note' => '绣纹、飘片与配色请对照实拍，不编造参数。',
        'poem_eyebrow' => '诗意旁笺',
        'poem_lines' => ['油壁香车不再逢', '峡云无迹任西东', '梨花院落溶溶月', '柳絮池塘淡淡风'],
        'look_title' => '通身气韵',
        'look_body' => '全身与侧影交叉：诃子金绣牡丹、大袖开合、裙幅垂落一目可读；举手扬袖见薄纱里色。',
        'look_note' => '规格轴「洛川大」等变体以变体图为准。',
        'macro_title' => '细处可辨',
        'macro_body' => '近景可见裙头绣花与绣花飘片、袖缘绣纹与褶影层次；证据在图。',
        'bento_title' => '衣袂可记',
        'bento_style_label' => '制式',
        'bento_style' => '唐制齐胸',
        'bento_parts_label' => '部件',
        'bento_parts' => '大袖衫 · 齐胸诃子裙',
        'bento_a' => '诃子金绣与裙头飘片以实拍为准',
        'bento_b' => '按胸围与身高对照尺码表',
        'quiet_line' => '衣在身上，韵在步间。',
        'checklist_title' => '护衣小笺',
        'checklist' => [
            '中性温和洗涤剂，勿长时间浸泡。',
            '深色首次或有浮色，与浅色分洗；不可漂白。',
            '通风处悬挂晾干，避暴晒；熨烫最高约 110℃，垫布为佳。',
        ],
        'tryon_title' => '试穿参考',
        'tryon_model' => '模特',
        'tryon_model_v' => '栗子',
        'tryon_height' => '身高',
        'tryon_height_v' => '161 cm',
        'tryon_weight' => '体重',
        'tryon_weight_v' => '40 kg',
        'tryon_measures' => '三围',
        'tryon_measures_v' => '72 / 63 / 80',
        'tryon_size' => '试穿尺码',
        'tryon_size_v' => 'M',
        'info_title' => '形制一览',
        'info_basics' => '基本信息',
        'info_comfort' => '舒适度',
        'label_brand' => '品牌',
        'label_name' => '品名',
        'label_color' => '颜色',
        'label_style' => '制式',
        'label_size' => '尺码',
        'label_fabric' => '面料',
        'label_parts' => '部件',
        'info_brand' => '花朝记 · 长安汉服',
        'info_name' => '洛川',
        'info_color' => '朱红 · 金绣 · 香槟粉（如图）',
        'info_style' => '唐制齐胸',
        'info_size' => 'XS–XL（见尺码表）',
        'info_fabric' => '聚酯纤维（如图）',
        'info_parts' => '大袖衫、齐胸诃子裙',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '适中',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
        'c_soft' => '手感',
        'c_soft_opts' => ['偏软', '适中', '偏硬'],
        'c_soft_sel' => '偏软',
        'c_fit' => '版型',
        'c_fit_opts' => ['修身', '合身', '宽松'],
        'c_fit_sel' => '宽松',
        'size_title' => '尺码参照',
        'size_body' => '请按测量表与胸围、身高挑选；手工测量或有 1–3 cm 出入，以实物为准。',
        'original_title' => '原创心迹',
        'original_body' => '花朝记「洛川」形制与绣纹以本店实拍为准；敬请珍惜衣冠、尊重匠心。',
        'close_caption' => $shortZh . ' · 婚服',
        'alt_hero' => $shortZh . ' · 着装',
        'alt_look' => $shortZh . ' · 着装',
        'alt_macro' => $shortZh . ' · 细部',
        'size_chart_html' => $sizeChartFor('zh_Hans_CN'),
        'lang' => 'zh-Hans',
    ];

    $packs['en_US'] = [
        'intro_title' => $shortEn,
        'intro_body' => 'Tang chest-high Hezi bridal set: crimson wide sleeves, gold-embroidered Hezi, champagne pleats and flutter panels—follow our studio photos.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => [
            'Named “Luochuan”: rite at the chest, air in the sleeves, stitch on Hezi and band.',
            'Vermilion against gold, champagne folds—bridal poise, not marketplace pitch.',
        ],
        'inspire_note' => 'Match embroidery, flutter panels and palette to the photos—no invented specs.',
        'poem_eyebrow' => 'Side verse',
        'poem_lines' => [
            'The lacquered carriage we meet no more',
            'Gorge clouds drift east or west at will',
            'Pear-blossom courts under melting moon',
            'Willow fluff, a pond of gentle wind',
        ],
        'look_title' => 'Full-body rhythm',
        'look_body' => 'Full and profile shots cross so gold peony Hezi, sleeve open-close and hem fall read at a glance; raise an arm to see the sheer lining.',
        'look_note' => 'Variants such as “Luochuan Da” follow the style axis.',
        'macro_title' => 'Readable up close',
        'macro_body' => 'Close frames show band embroidery, flutter panels, cuff stitch and pleat layers—evidence only.',
        'bento_title' => 'Worth noting',
        'bento_style_label' => 'Cut',
        'bento_style' => 'Tang chest-high',
        'bento_parts_label' => 'Parts',
        'bento_parts' => 'Wide-sleeve robe · chest-high Hezi skirt',
        'bento_a' => 'Gold Hezi & flutter panels follow macros',
        'bento_b' => 'Pick size by bust and height on the chart',
        'quiet_line' => 'Cloth on the body; grace in the step.',
        'checklist_title' => 'Care notes',
        'checklist' => [
            'Mild detergent; do not soak long.',
            'Dark dyes may bleed on first wash—separate lights; do not bleach.',
            'Hang dry in air; avoid harsh sun; iron ≤110°C with a cloth.',
        ],
        'tryon_title' => 'Fit reference',
        'tryon_model' => 'Model',
        'tryon_model_v' => 'Lizi',
        'tryon_height' => 'Height',
        'tryon_height_v' => '161 cm',
        'tryon_weight' => 'Weight',
        'tryon_weight_v' => '40 kg',
        'tryon_measures' => 'Bust / waist / hip',
        'tryon_measures_v' => '72 / 63 / 80',
        'tryon_size' => 'Tried size',
        'tryon_size_v' => 'M',
        'info_title' => 'At a glance',
        'info_basics' => 'Basics',
        'info_comfort' => 'Comfort & fit',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_color' => 'Color',
        'label_style' => 'Style',
        'label_size' => 'Size',
        'label_fabric' => 'Fabric',
        'label_parts' => 'Parts',
        'info_brand' => "Huazhaoji · Chang'an Hanfu",
        'info_name' => 'Luochuan',
        'info_color' => 'Crimson · gold stitch · champagne pink (as shown)',
        'info_style' => 'Tang chest-high',
        'info_size' => 'XS–XL (see chart)',
        'info_fabric' => 'Polyester (as shown)',
        'info_parts' => 'Wide-sleeve robe, chest-high Hezi skirt',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Moderate', 'Thick'],
        'c_thick_sel' => 'Moderate',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
        'c_soft' => 'Hand feel',
        'c_soft_opts' => ['Softer', 'Moderate', 'Firmer'],
        'c_soft_sel' => 'Softer',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Slim', 'Regular', 'Relaxed'],
        'c_fit_sel' => 'Relaxed',
        'size_title' => 'Sizing',
        'size_body' => 'Match the tables to bust and height; hand measure may vary by 1–3 cm—the garment wins.',
        'original_title' => 'Original craft',
        'original_body' => 'Huazhaoji “Luochuan” cut and embroidery follow our studio photos—honor the craft.',
        'close_caption' => $shortEn . ' · bridal',
        'alt_hero' => $shortEn . ' · worn',
        'alt_look' => $shortEn . ' · worn',
        'alt_macro' => $shortEn . ' · detail',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'en',
    ];

    if (!isset($packs[$locale])) {
        // 父槽②：其它启用语先落源语结构，字段级真译交③
        return $packs['zh_Hans_CN'];
    }

    return $packs[$locale];
};

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

    $feature = '<div class="weline-detail-feature weline-detail-feature--reverse weline-detail-orient--portrait">'
        . '<div class="weline-detail-feature__media">' . $img($A['feature'], (string)$t['alt_look']) . '</div>'
        . '<div class="weline-detail-feature__copy"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p><p class="weline-detail-feature__note">'
        . $h((string)$t['look_note']) . '</p></div></div>';

    $sizeChart = (string)$t['size_chart_html'];

    $pairLooks = $figureStack([
        $img($A['look_a'], (string)$t['alt_look'] . ' · a'),
        $img($A['look_b'], (string)$t['alt_look'] . ' · b'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p></div>';

    $pairMacro = $figureStack([
        $img($A['look_c'], (string)$t['alt_look'] . ' · front'),
        $img($A['look_back'], (string)$t['alt_look'] . ' · back'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $pairDetail = $figureStack([
        $img($A['macro_sit'], (string)$t['alt_macro'] . ' · sit'),
        $img($A['macro_stand'], (string)$t['alt_macro'] . ' · stand'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
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

    $quietLine = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';

    $wash = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ((array)$t['checklist'] as $line) {
        $wash .= '<li>' . $h((string)$line) . '</li>';
    }
    $wash .= '</ul></div>';

    $tryon = '<div class="weline-detail-text weline-detail-text--product-info" data-weline-detail-text="product-info"><h3>'
        . $h((string)$t['tryon_title']) . '</h3><table class="weline-detail-text__defs"><tbody>'
        . '<tr><th>' . $h((string)$t['tryon_model']) . '</th><td>' . $h((string)$t['tryon_model_v']) . '</td></tr>'
        . '<tr><th>' . $h((string)$t['tryon_height']) . '</th><td>' . $h((string)$t['tryon_height_v']) . '</td></tr>'
        . '<tr><th>' . $h((string)$t['tryon_weight']) . '</th><td>' . $h((string)$t['tryon_weight_v']) . '</td></tr>'
        . '<tr><th>' . $h((string)$t['tryon_measures']) . '</th><td>' . $h((string)$t['tryon_measures_v']) . '</td></tr>'
        . '<tr><th>' . $h((string)$t['tryon_size']) . '</th><td>' . $h((string)$t['tryon_size_v']) . '</td></tr>'
        . '</tbody></table></div>';

    $infoBasics = [
        (string)$t['label_brand'] => (string)$t['info_brand'],
        (string)$t['label_name'] => (string)$t['info_name'],
        (string)$t['label_color'] => (string)$t['info_color'],
        (string)$t['label_style'] => (string)$t['info_style'],
        (string)$t['label_size'] => (string)$t['info_size'],
        (string)$t['label_fabric'] => (string)$t['info_fabric'],
        (string)$t['label_parts'] => (string)$t['info_parts'],
    ];
    $infoComfort = [
        ['label' => (string)$t['c_thick'], 'options' => (array)$t['c_thick_opts'], 'selected' => (string)$t['c_thick_sel']],
        ['label' => (string)$t['c_stretch'], 'options' => (array)$t['c_stretch_opts'], 'selected' => (string)$t['c_stretch_sel']],
        ['label' => (string)$t['c_soft'], 'options' => (array)$t['c_soft_opts'], 'selected' => (string)$t['c_soft_sel']],
        ['label' => (string)$t['c_fit'], 'options' => (array)$t['c_fit_opts'], 'selected' => (string)$t['c_fit_sel']],
    ];
    $info = str_starts_with((string)$t['lang'], 'zh')
        ? DetailDescriptionTextifier::buildProductInfoPanelZh(
            $infoBasics,
            $infoComfort,
            (string)$t['info_title'],
            (string)$t['info_basics'],
            (string)$t['info_comfort'],
        )
        : DetailDescriptionTextifier::buildProductInfoPanelEn(
            $infoBasics,
            $infoComfort,
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
        . $intro . $inspire . $poem . $feature . $sizeChart . $pairLooks . $pairMacro . $pairDetail
        . $quiet . $bento . $quietLine . $wash . $tryon . $info . $sizeNote . $original . $close
        . '</div>';
};

/** @var \Weline\Websites\Model\Website $website */
$website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->loadById($websiteId)
    ?: ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->load($websiteId);
$enabledLocales = $website ? array_values(array_filter(array_map('strval', (array)$website->getLanguageCodes()))) : [];
if ($enabledLocales === []) {
    $enabledLocales = ['zh_Hans_CN', 'en_US'];
}
$defaultLang = $website ? (string)$website->getDefaultLanguage() : 'zh_Hans_CN';
if ($defaultLang === '') {
    $defaultLang = 'zh_Hans_CN';
}

echo "Website {$websiteId} default={$defaultLang}\n";
echo 'Enabled (' . count($enabledLocales) . '): ' . implode(', ', $enabledLocales) . "\n";

$forbiddenAssets = [
    '188a08ee-e8f1-49b5-acb8-d541abe553b3', // 诃子裙尺码烤图
    'd170f95d-f057-48f3-990c-a280a85149f4', // 设计灵感字板
    'd4afc742-46bc-4f41-beba-c02f434f4b6c', // 圆框拼版字
    '1e9a2883-159b-47df-b0a2-5c729184cca7', // 洗涤情况说明烤图
];

$writes = [];
foreach (array_values(array_unique(array_merge([''], $enabledLocales))) as $locale) {
    $packLocale = $locale === '' ? $defaultLang : $locale;
    if ($packLocale === 'en_US' || str_starts_with($packLocale, 'en_')) {
        $packLocale = 'en_US';
    } elseif (str_starts_with($packLocale, 'zh')) {
        $packLocale = 'zh_Hans_CN';
    } else {
        // 非中英：落源语结构，翻译交槽③
        $packLocale = 'zh_Hans_CN';
    }
    $html = $tagReveal($assemble($baseCopy($packLocale)));
    $ok = str_contains($html, 'prose--lead')
        && str_contains($html, 'poem-aside')
        && str_contains($html, 'figure-row--pair')
        && str_contains($html, 'prose--checklist')
        && str_contains($html, 'data-weline-detail-reveal')
        && str_contains($html, 'data-weds="xq"')
        && str_contains($html, 'measurement-chart');
    foreach ($forbiddenAssets as $bad) {
        if (str_contains($html, $bad)) {
            $ok = false;
            break;
        }
    }
    $writes[] = ['locale' => $locale, 'html' => $html, 'ok' => $ok, 'pack' => $packLocale];
    $label = $locale === '' ? '(empty→' . $defaultLang . ')' : $locale;
    echo "  {$label} pack={$packLocale} len=" . strlen($html) . ' struct=' . ($ok ? 'PASS' : 'FAIL') . "\n";
    if (!$ok) {
        fwrite(STDERR, "structure fail for {$label}\n");
        exit(2);
    }
}

echo 'plan writes=' . count($writes) . ' apply=' . ($apply ? 'yes' : 'dry-run') . "\n";
if (!$apply) {
    echo "dry-run only\n";
    exit(0);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$attributes->writeExplicit($websiteId, 0, 'product', $productId, 'name', 'zh_Hans_CN', $fullZhName, true);
echo "name zh_Hans_CN => {$fullZhName}\n";

foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    if ($locale !== '') {
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    } else {
        LocalDescription::upsertQuiet($productId, '', [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    try {
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
    } catch (Throwable $e) {
        echo 'EAV write soft-fail ' . ($locale === '' ? '(empty)' : $locale) . ': ' . $e->getMessage() . "\n";
    }
    echo 'wrote description ' . ($locale === '' ? '(empty)' : $locale) . ' len=' . strlen($html) . "\n";
}

try {
    /** @var ProductStorefrontCacheInvalidator $inv */
    $inv = ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class);
    $inv->clearForCatalogChange('detail_suite_p192');
} catch (Throwable $e) {
    echo 'cache invalidate soft-fail: ' . $e->getMessage() . "\n";
}

// upsertQuiet 会把空 locale 归一化掉；店面若读空码则直接同步源语长文
try {
    $env = include dirname(__DIR__, 5) . '/app/etc/env.php';
    $db = $env['db']['master'] ?? [];
    $pdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            (string)($db['hostname'] ?? '127.0.0.1'),
            (string)($db['hostport'] ?? '5432'),
            (string)($db['database'] ?? ''),
        ),
        (string)($db['username'] ?? ''),
        (string)($db['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $src = null;
    foreach ($writes as $w) {
        if ((string)$w['locale'] === 'zh_Hans_CN') {
            $src = (string)$w['html'];
            break;
        }
    }
    if (is_string($src) && $src !== '') {
        $upd = $pdo->prepare('UPDATE w_weline_product_local SET description = ? WHERE product_id = ? AND local_code = ?');
        $upd->execute([$src, $productId, '']);
        echo "synced empty locale from zh_Hans_CN len=" . strlen($src) . "\n";
    }
} catch (Throwable $e) {
    echo 'empty-locale sync soft-fail: ' . $e->getMessage() . "\n";
}
try {
    /** @var StorefrontCatalogCacheCoordinator $coord */
    $coord = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
    $coord->notifyCatalogChanged($websiteId, 'detail_suite_p192', ['product_id' => $productId]);
} catch (Throwable $e) {
    echo 'catalog notify soft-fail: ' . $e->getMessage() . "\n";
}

// 清 product-info 编译模板，避免旧 cover 裁脸
$tplGlob = dirname(__DIR__) . '/view/tpl/**/com_product-info.phtml';
foreach (glob($tplGlob) ?: [] as $tpl) {
    @unlink($tpl);
    echo 'cleared tpl ' . $tpl . "\n";
}

echo "DONE product {$productId}\n";
