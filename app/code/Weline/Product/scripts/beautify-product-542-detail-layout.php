<?php

declare(strict_types=1);

/**
 * #542 花朝记·重工刺绣大袖衫齐胸诃子裙 · 详情强制重做
 *
 * 卖点表：
 * | 卖点 | 证据 | 原型 |
 * | 重工刺绣 | 细部实拍绣线 | macro + pair |
 * | 大袖生风 | 通袖长/袖口表 + 全身 | poem-aside + feature |
 * | 齐胸诃子 | 尺码表齐胸裙 + 着装 | editorial + size-chart |
 * | 唐制春夏 | 皱皱纱/锦丝皱 | checklist + info |
 *
 * 原型：lead → verse → poem-aside → feature → size → pair → prose → pair(macro)
 *       → quiet → bento → pair → solo → checklist → info → size note → original
 *
 * php app/code/Weline/Product/scripts/beautify-product-542-detail-layout.php --apply
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
$productId = 542;

$fullZhName = '成人汉服重工刺绣大袖衫齐胸裙原创唐制汉服诃子裙超仙气春夏';
$shortZh = '重工刺绣大袖衫·齐胸诃子裙';
$shortEn = 'Heavy-embroidery wide sleeves · chest-high Hezi skirt';

/** @var array<string, array{id:string,w:int,h:int}> $A */
$A = [
    'hero' => ['id' => 'a692df03-b6bd-4762-bb03-53b669d2db02', 'w' => 715, 'h' => 1125],
    'look_cut' => ['id' => '0445c69e-cb78-4d51-8cbf-9302698d1fbb', 'w' => 695, 'h' => 1156],
    'look_a' => ['id' => 'c186cdef-c54c-49a2-a209-4a3f84611a1c', 'w' => 747, 'h' => 1125],
    'look_b' => ['id' => '42fb8b55-f657-42ac-9469-b6c8b4504781', 'w' => 727, 'h' => 1088],
    'macro_a' => ['id' => 'a1d9e441-09c1-4403-a8e2-53351165e539', 'w' => 1200, 'h' => 1200],
    'macro_b' => ['id' => '8f16227d-31c2-4db7-936d-6375f264cab8', 'w' => 1200, 'h' => 1200],
    'look_c' => ['id' => 'ece778c2-7b3b-4f84-8666-9012b87800b5', 'w' => 1200, 'h' => 1200],
    'look_d' => ['id' => 'ebb98f43-b5ca-4b5d-91c5-72535424fd5b', 'w' => 1024, 'h' => 1024],
    'macro_c' => ['id' => '6d69effa-3bfd-4e86-bbb2-e0d134c3f54f', 'w' => 1024, 'h' => 1024],
    'look_extra' => ['id' => '205bd2e6-6f47-4c5f-9a97-03178bef57aa', 'w' => 700, 'h' => 1154],
];

$sizeChartZh = <<<'HTML'
<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="measurement-chart"><h3>尺码信息</h3><p class="weline-detail-text__note">单位：厘米（cm）。手工测量可能存在 1–3 cm 误差，以实物为准。面料：聚酯纤维。</p><h4>齐胸裙</h4><p>主料：锦丝皱印花</p><table><thead><tr><th>尺码</th><th>XS</th><th>S</th><th>M</th><th>L</th><th>XL</th></tr></thead><tbody><tr><th>裙长</th><td>115</td><td>120</td><td>125</td><td>130</td><td>135</td></tr><tr><th>裙头长</th><td>102</td><td>106</td><td>110</td><td>114</td><td>118</td></tr></tbody></table><h4>大袖衫</h4><p>主料：皱皱纱</p><table><thead><tr><th>尺码</th><th>XS</th><th>S</th><th>M</th><th>L</th><th>XL</th></tr></thead><tbody><tr><th>后中长</th><td>120</td><td>122</td><td>124</td><td>126</td><td>128</td></tr><tr><th>胸围</th><td>104</td><td>104</td><td>112</td><td>112</td><td>116</td></tr><tr><th>通袖长</th><td>184</td><td>188</td><td>192</td><td>196</td><td>200</td></tr><tr><th>袖口</th><td>200</td><td>200</td><td>200</td><td>200</td><td>200</td></tr></tbody></table><h4>里大袖</h4><table><thead><tr><th>尺码</th><th>XS</th><th>S</th><th>M</th><th>L</th><th>XL</th></tr></thead><tbody><tr><th>后中长</th><td>111</td><td>113</td><td>115</td><td>117</td><td>119</td></tr><tr><th>胸围</th><td>98</td><td>98</td><td>106</td><td>106</td><td>110</td></tr><tr><th>通袖长</th><td>166</td><td>166</td><td>170</td><td>170</td><td>174</td></tr><tr><th>袖口</th><td>160</td><td>160</td><td>160</td><td>160</td><td>160</td></tr></tbody></table></div>
HTML;

$sizeChartEn = <<<'HTML'
<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="measurement-chart"><h3>Size chart</h3><p class="weline-detail-text__note">Unit: cm. Hand measure may vary by 1–3 cm; the garment is the source of truth. Fabric: polyester.</p><h4>Chest-high skirt</h4><p>Main: printed silk-crepe jacquard</p><table><thead><tr><th>Size</th><th>XS</th><th>S</th><th>M</th><th>L</th><th>XL</th></tr></thead><tbody><tr><th>Skirt length</th><td>115</td><td>120</td><td>125</td><td>130</td><td>135</td></tr><tr><th>Waistband length</th><td>102</td><td>106</td><td>110</td><td>114</td><td>118</td></tr></tbody></table><h4>Wide-sleeve robe</h4><p>Main: crepe gauze</p><table><thead><tr><th>Size</th><th>XS</th><th>S</th><th>M</th><th>L</th><th>XL</th></tr></thead><tbody><tr><th>Center-back length</th><td>120</td><td>122</td><td>124</td><td>126</td><td>128</td></tr><tr><th>Bust</th><td>104</td><td>104</td><td>112</td><td>112</td><td>116</td></tr><tr><th>Sleeve span</th><td>184</td><td>188</td><td>192</td><td>196</td><td>200</td></tr><tr><th>Cuff</th><td>200</td><td>200</td><td>200</td><td>200</td><td>200</td></tr></tbody></table><h4>Inner wide sleeve</h4><table><thead><tr><th>Size</th><th>XS</th><th>S</th><th>M</th><th>L</th><th>XL</th></tr></thead><tbody><tr><th>Center-back length</th><td>111</td><td>113</td><td>115</td><td>117</td><td>119</td></tr><tr><th>Bust</th><td>98</td><td>98</td><td>106</td><td>106</td><td>110</td></tr><tr><th>Sleeve span</th><td>166</td><td>166</td><td>170</td><td>170</td><td>174</td></tr><tr><th>Cuff</th><td>160</td><td>160</td><td>160</td><td>160</td><td>160</td></tr></tbody></table></div>
HTML;

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
    'intro_body' => '唐制齐胸诃子裙配重工刺绣大袖衫：绣线层叠、通袖生风，衣长与纹样以本店实拍为准。',
    'inspire_title' => '设计心源',
    'inspire_lines' => [
        '以重工刺绣写春夏仙气，不以货盘腔调堆砌。',
        '大袖开合、齐胸承裙——形制清楚，气韵可走。',
    ],
    'inspire_note' => '绣与袖是题眼；颜色与花纹请对照规格轴实拍。',
    'poem_eyebrow' => '诗意旁笺',
    'poem_lines' => ['绣线层叠', '大袖生风', '齐胸承春'],
    'look_title' => '通身气韵',
    'look_body' => '全身与半身交叉铺陈：裙幅垂落、袖袂开合、领缘与诃子层次一眼可读。',
    'look_note' => '红色小花等变体以规格图为准，勿凭想象补色。',
    'macro_title' => '细处可辨',
    'macro_body' => '近景可见绣线走向、褶影深浅与皱皱纱触感；以图为证，不编造参数。',
    'bento_title' => '衣袂可记',
    'bento_style_label' => '制式',
    'bento_style' => '唐制',
    'bento_parts_label' => '部件',
    'bento_parts' => '大袖衫 · 齐胸诃子裙',
    'bento_a' => '重工刺绣以实拍细部为准',
    'bento_b' => '尺码按胸围身高对照表挑选',
    'quiet_line' => '衣在身上，韵在步间。',
    'checklist_title' => '护衣小笺',
    'checklist' => [
        '建议手洗、分色洗涤，不可漂白。',
        '悬挂晾干，避暴晒；低温熨烫，垫布为佳。',
        '大袖与绣片宜轻挂，避免长期重压折痕。',
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
    'info_color' => '如图（规格轴可选）',
    'info_style' => '唐制',
    'info_size' => '见尺码表 / 规格轴',
    'info_fabric' => '皱皱纱大袖 · 锦丝皱印花齐胸裙',
    'info_parts' => '大袖衫、里大袖、齐胸诃子裙',
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
    'size_body' => '请按上方测量表与自身胸围、身高挑选；手工测量或有 1–3 cm 出入，以实物为准。',
    'original_title' => '原创心迹',
    'original_body' => '敬请珍惜衣冠、尊重匠心；绣纹与形制以本店实拍为准。',
    'close_caption' => $shortZh . ' · 唐制春夏',
    'alt_hero' => $shortZh . ' · 着装',
    'alt_cut' => $shortZh . ' · 形制',
    'alt_look' => $shortZh . ' · 着装',
    'alt_macro' => $shortZh . ' · 绣纹细部',
    'size_chart_html' => $sizeChartZh,
    'lang' => 'zh-Hans',
];

$copy['en_US'] = [
    'intro_title' => $shortEn,
    'intro_body' => 'Tang-style chest-high Hezi skirt with a heavily embroidered wide-sleeve robe: stacked stitch, airy sleeves—length and motifs follow our studio shoots.',
    'inspire_title' => 'Design wellspring',
    'inspire_lines' => [
        'Heavy embroidery for spring–summer lightness—no wholesale pitch tone.',
        'Wide sleeves open and close; the high waist carries the skirt—clear cut, wearable air.',
    ],
    'inspire_note' => 'Embroidery and sleeves are the motif; match colorways to the variant photos.',
    'poem_eyebrow' => 'Side verse',
    'poem_lines' => ['Stitches layered', 'Sleeves catch wind', 'Spring at the chest'],
    'look_title' => 'Full-body rhythm',
    'look_body' => 'Full and mid shots cross so hem fall, sleeve open-close, collar and Hezi layers read at a glance.',
    'look_note' => 'Color variants (e.g. small red florals) follow the size/style axis—do not invent hues.',
    'macro_title' => 'Readable up close',
    'macro_body' => 'Macro frames show stitch direction, fold depth, and crepe-gauze hand—evidence only, no invented specs.',
    'bento_title' => 'Worth noting',
    'bento_style_label' => 'Cut',
    'bento_style' => 'Tang-style',
    'bento_parts_label' => 'Parts',
    'bento_parts' => 'Wide-sleeve robe · chest-high Hezi skirt',
    'bento_a' => 'Heavy embroidery follows macro photos',
    'bento_b' => 'Pick size by bust and height on the chart',
    'quiet_line' => 'Cloth on the body; air in the step.',
    'checklist_title' => 'Care notes',
    'checklist' => [
        'Hand wash separately; do not bleach.',
        'Hang dry; avoid harsh sun; low iron with a cloth.',
        'Hang wide sleeves and embroidery lightly—avoid long heavy creasing.',
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
    'info_color' => 'As shown (see variant axis)',
    'info_style' => 'Tang-style',
    'info_size' => 'See size chart / variant axis',
    'info_fabric' => 'Crepe-gauze robe · printed silk-crepe skirt',
    'info_parts' => 'Wide-sleeve robe, inner sleeve, chest-high Hezi skirt',
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
    'size_body' => 'Match the measurement tables to bust and height; hand measure may vary by 1–3 cm—the garment wins.',
    'original_title' => 'Original craft',
    'original_body' => 'Honor the cut and craft; embroidery and silhouette follow our studio photos.',
    'close_caption' => $shortEn . ' · Tang spring–summer',
    'alt_hero' => $shortEn . ' · worn',
    'alt_cut' => $shortEn . ' · silhouette',
    'alt_look' => $shortEn . ' · worn',
    'alt_macro' => $shortEn . ' · embroidery detail',
    'size_chart_html' => $sizeChartEn,
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

    $feature = '<div class="weline-detail-feature weline-detail-feature--reverse weline-detail-orient--portrait">'
        . '<div class="weline-detail-feature__media">' . $img($A['look_cut'], (string)$t['alt_cut']) . '</div>'
        . '<div class="weline-detail-feature__copy"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p><p class="weline-detail-feature__note">'
        . $h((string)$t['look_note']) . '</p></div></div>';

    $sizeChart = (string)$t['size_chart_html'];

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

    $soloMacro = $figureStack([
        $img($A['macro_c'], (string)$t['alt_macro'] . ' 3'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><p>' . $h((string)$t['macro_body']) . '</p></div>';

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
        . $intro . $inspire . $poem . $feature . $sizeChart . $pairLooks . $pairMacro
        . $quiet . $bento . $pairMore . $soloMacro . $quietLine . $wash . $info . $sizeNote . $original . $close
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

// Other enabled locales: keep prior true-translate magazine HTML, inject reveal + ensure weds + swap size chart if missing.
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
        // Fallback: English pack (should be rare); mark for manual review in log.
        echo "WARN locale {$locale}: no magazine HTML, using en_US structure\n";
        $html = $enHtml;
    } else {
        // Ensure markers on root
        if (!str_contains($html, 'data-weds')) {
            if (preg_match('/^<div\b[^>]*>/', $html)) {
                $html = preg_replace(
                    '/^<div\b/',
                    '<div data-weds="xq"',
                    $html,
                    1
                ) ?? $html;
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
        // If size chart missing, append English measurement chart before closing root (better than losing tables).
        if (!str_contains($html, 'weline-detail-text--size-chart')) {
            $html = preg_replace(
                '#</div>\s*$#',
                $sizeChartEn . '</div>',
                $html,
                1
            ) ?? ($html . $sizeChartEn);
        }
        // Fix known truncated Chinese title leak
        $html = str_replace('成人汉服重工刺绣大袖衫齐</h3>', $h($shortZh) . '</h3>', $html);
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

// Fix Chinese name
$attributes->writeExplicit($websiteId, 0, 'product', $productId, 'name', 'zh_Hans_CN', $fullZhName, true);
echo "name zh_Hans_CN => {$fullZhName}\n";

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
    $coord->notifyCatalogChanged($websiteId, 'detail_suite_p542', ['product_id' => $productId]);
} catch (Throwable $e) {
    echo 'catalog notify soft-fail: ' . $e->getMessage() . "\n";
}

echo "DONE product {$productId}\n";
