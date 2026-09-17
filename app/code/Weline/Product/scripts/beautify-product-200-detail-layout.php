<?php

declare(strict_types=1);

/**
 * #200 凤鸣在竹 · 强制重做详情版式（反千篇一律大图滑梯）
 *
 * 卖点表：
 * | 卖点 | 证据 | 原型 |
 * | 凤绣马面 | 红裙金绣凤纹全身 | fullbleed + pair |
 * | 竹叶肩绣 | 白衣肩胸竹绣 | macro |
 * | 明制形制 | 交领上衣+马面 | editorial + checklist |
 * | 多色着装 | 红裙/绿裙变体 | pair + stack |
 *
 * 原型：editorial → fullbleed → verse → pair → prose → macro pair → quiet
 *       → checklist → pair → stack_caption → quiet → wash → spec → size → original
 * （禁止连续 ≥3 solo/fullbleed）
 *
 * php app/code/Weline/Product/scripts/beautify-product-200-detail-layout.php --apply
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
$productId = 200;

/** @var array<string, array{id:string,w:int,h:int}> $A */
$A = [
    'hero' => ['id' => '4a27beee-7bb0-41b2-93de-f3fed18d47e7', 'w' => 1024, 'h' => 1024], // 04 HD
    'look_red' => ['id' => '8740b5b9-c6c6-4b52-8e95-dbf0491c71bb', 'w' => 864, 'h' => 1152], // 01 HD
    'look_side' => ['id' => '505b9d8f-2d82-4a9c-b456-9cf6d3acd7cd', 'w' => 1024, 'h' => 1024], // 05 HD
    'look_close' => ['id' => '9ebbd243-6dc2-41aa-acc5-30516679afda', 'w' => 864, 'h' => 1152], // 03 studio restore（禁黑底抠图）
    'look_green' => ['id' => '71b936e9-5cf9-47e2-885a-3c66bf1cd76f', 'w' => 1024, 'h' => 1024], // 07 HD
    'bamboo' => ['id' => '0cdfcfaf-838c-4afa-9298-58861311e4e0', 'w' => 864, 'h' => 1152], // 08 HD
    'skirt_macro' => ['id' => 'cfdb6a0c-77d8-4e1c-aba7-21131cdc0f64', 'w' => 864, 'h' => 1152], // 09 HD
    'detail_emb' => ['id' => 'ad48656d-258b-464c-8b75-22e45bb7b80b', 'w' => 1200, 'h' => 1200], // detail-06
    'detail_hem' => ['id' => '7783c6cd-3000-4d8d-a804-9aaefe211004', 'w' => 1200, 'h' => 1200], // detail-05
    'look_alt' => ['id' => '61f76196-6d5e-44ac-94d0-486abf1cd720', 'w' => 750, 'h' => 750], // 06
    'close' => ['id' => '1311d574-5381-42b6-9d10-127cbeca5d1a', 'w' => 1024, 'h' => 1024], // 02 HD
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

$dataDir = __DIR__ . '/data';
$copy = [];
foreach ([
    'product-200-detail-i18n-packs-part1.php',
    'product-200-detail-i18n-packs-part2.php',
    'product-200-detail-i18n-packs-part3.php',
    'product-200-detail-i18n-packs-part4.php',
] as $packFile) {
    $part = require $dataDir . '/' . $packFile;
    if (!is_array($part)) {
        fwrite(STDERR, "Bad pack: {$packFile}\n");
        exit(2);
    }
    $copy = array_merge($copy, $part);
}

/** @var \Weline\Websites\Model\Website $website */
$website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->loadById($websiteId)
    ?: ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->load($websiteId);
$enabledLocales = $website ? array_values(array_filter(array_map('strval', (array)$website->getLanguageCodes()))) : [];
if ($enabledLocales === []) {
    $enabledLocales = ['zh_Hans_CN', 'en_US'];
}

// Only default-website enabled locales (+ empty → default language pack).
$defaultLang = $website ? (string)$website->getDefaultLanguage() : 'zh_Hans_CN';
if ($defaultLang === '' || !isset($copy[$defaultLang])) {
    $defaultLang = 'zh_Hans_CN';
}
$localePlan = [
    '' => $defaultLang,
];
foreach ($enabledLocales as $code) {
    if (!isset($copy[$code])) {
        fwrite(STDERR, "Missing true-translate pack for enabled locale: {$code}\n");
        exit(2);
    }
    $localePlan[$code] = $code;
}

$enLeakMarkers = [
    'Design wellspring', 'Original craft', 'Worth noting', 'At a glance',
    'Close looking', 'Size guide',
];
$zhHansLeakInHant = ['设计心源', '衣袂可记', '细处可辨', '形制一览', '护衣小笺'];
$banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship'];

$assemble = static function (array $t) use ($A, $img, $figureStack, $h): string {
    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';
    $hero = $figureStack([$img($A['hero'], (string)$t['alt_hero'])], 'weline-detail-figure-stack--fullbleed');
    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $pair = $figureStack([
        $img($A['look_red'], (string)$t['alt_look'] . ' 1'),
        $img($A['look_side'], (string)$t['alt_look'] . ' 2'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p></div>';

    $macro = $figureStack([
        $img($A['bamboo'], (string)$t['alt_macro'] . ' 1'),
        $img($A['skirt_macro'], (string)$t['alt_macro'] . ' 2'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose weline-detail-prose--macro"><h3>' . $h((string)$t['macro_title']) . '</h3>'
        . '<h4>' . $h((string)$t['macro_label']) . '</h4><p>' . $h((string)$t['macro_body']) . '</p>'
        . '<h4>' . $h((string)$t['macro2_label']) . '</h4><p>' . $h((string)$t['macro2_body']) . '</p></div>';

    $quiet = '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>';
    $check = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ((array)$t['checklist'] as $item) {
        $check .= '<li>' . $h((string)$item) . '</li>';
    }
    $check .= '</ul></div>';

    $color = $figureStack([
        $img($A['look_green'], (string)$t['alt_look'] . ' 3'),
        $img($A['look_alt'], (string)$t['alt_look'] . ' 4'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['color_title']) . '</h3><p>'
        . $h((string)$t['color_body']) . '</p></div>';

    $detailPair = $figureStack([
        $img($A['detail_emb'], (string)$t['alt_macro'] . ' 3'),
        $img($A['detail_hem'], (string)$t['alt_macro'] . ' 4'),
    ], 'weline-detail-figure-stack--caption');

    $lookClose = $figureStack([
        $img($A['look_close'], (string)$t['alt_look'] . ' 5'),
    ], 'weline-detail-figure-stack--caption');

    $quietLine = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';
    $wash = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['wash_title']) . '</h3><ul>';
    foreach ((array)$t['wash_lines'] as $line) {
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
    $size = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';
    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';
    $close = $figureStack([$img($A['close'], (string)$t['alt_close'])], 'weline-detail-figure-stack--fullbleed')
        . '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weds="xq">'
        . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
        . $intro . $hero . $inspire . $pair . $macro . $quiet . $check
        . $color . $detailPair . $lookClose . $quietLine . $wash . $info . $size . $original . $close
        . '</div>';
};

$writes = [];
foreach ($localePlan as $locale => $base) {
    if (!isset($copy[$base])) {
        fwrite(STDERR, "Missing locale pack: {$base}\n");
        exit(2);
    }
    if ($locale !== '' && $locale !== 'en_US' && $base === 'en_US') {
        fwrite(STDERR, "EN pack mapped to non-en locale: {$locale}\n");
        exit(2);
    }
    $html = $assemble($copy[$base]);
    foreach ($banned as $b) {
        if (str_contains($html, $b)) {
            fwrite(STDERR, "Banned phrase in {$locale}: {$b}\n");
            exit(2);
        }
    }
    if ($base !== 'en_US') {
        foreach ($enLeakMarkers as $marker) {
            if (str_contains($html, $marker)) {
                fwrite(STDERR, "EN dump into {$locale}: {$marker}\n");
                exit(2);
            }
        }
    }
    if (str_starts_with((string)$locale, 'zh_Hant') || $base === 'zh_Hant_TW') {
        foreach ($zhHansLeakInHant as $marker) {
            if (str_contains($html, $marker)) {
                fwrite(STDERR, "Hans leak into Hant {$locale}: {$marker}\n");
                exit(2);
            }
        }
    }
    $writes[] = ['locale' => $locale, 'html' => $html, 'len' => strlen($html), 'base' => $base];
}

foreach ($writes as $w) {
    echo ($w['locale'] === '' ? '(empty)' : $w['locale']) . "\t" . $w['base'] . "\t" . $w['len'] . "\n";
}

if (!$apply) {
    echo "Dry-run only. Pass --apply to write.\n";
    exit(0);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    if ($locale !== '') {
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
}

ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
    $websiteId,
    'detail_suite_force_200',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_suite_force_200');
echo "applied product {$productId}\n";
