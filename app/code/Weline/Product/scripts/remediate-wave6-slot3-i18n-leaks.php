<?php

declare(strict_types=1);

/**
 * 产品优化槽③：wave6 12 品 — 启用语 description 渗漏修复
 * - 非 EN：英文 eyebrow「Verse aside」→ 目标语
 * - 非中文：清「本店」与商业短标题中文渗漏（专名可留）
 * - 修正 poem-aside verse-vertical 的 lang=
 *
 * php app/code/Weline/Product/scripts/remediate-wave6-slot3-i18n-leaks.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-wave6-slot3-i18n-leaks.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));

$productIds = [228, 251, 303, 345, 348, 354, 359, 361, 372, 386, 397, 416];

$root = dirname(__DIR__, 5);
$env = include $root . '/app/etc/env.php';
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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$om = ObjectManager::getInstance();
$ws = $om->get(Website::class);
$ws->load($websiteId);
$enabled = $ws->getLanguageCodes();
$localePlan = ['' => (string)($ws->getDefaultLanguage() ?: 'zh_Hans_CN')];
foreach ($enabled as $code) {
    $localePlan[$code] = $code;
}

echo 'ENABLED_LOCALES=' . json_encode(array_keys($localePlan), JSON_UNESCAPED_UNICODE) . PHP_EOL;

/** @var array<string, string> */
$poemTitle = [
    'zh_Hans_CN' => '诗意旁笺',
    'en_US' => 'Verse aside',
    'es_ES' => 'Nota en verso',
    'fr_FR' => 'Aparté en vers',
    'pt_BR' => 'Nota em verso',
    'id_ID' => 'Catatan puisi',
    'ar_SA' => 'هامش شعري',
    'bn_BD' => 'কাব্যিক পাদটীকা',
    'hi_IN' => 'काव्य पार्श्व',
    'ur_PK' => 'شعری حاشیہ',
];

/** @var array<string, string> */
$brandOurShop = [
    'zh_Hans_CN' => '本店',
    'en_US' => 'Our shop',
    'es_ES' => 'Nuestra tienda',
    'fr_FR' => 'Notre boutique',
    'pt_BR' => 'Nossa loja',
    'id_ID' => 'Toko kami',
    'ar_SA' => 'متجرنا',
    'bn_BD' => 'আমাদের দোকান',
    'hi_IN' => 'हमारी दुकान',
    'ur_PK' => 'ہماری دکان',
];

/** @var array<string, string> */
$htmlLang = [
    'zh_Hans_CN' => 'zh-Hans',
    'en_US' => 'en',
    'es_ES' => 'es',
    'fr_FR' => 'fr',
    'pt_BR' => 'pt-BR',
    'id_ID' => 'id',
    'ar_SA' => 'ar',
    'bn_BD' => 'bn',
    'hi_IN' => 'hi',
    'ur_PK' => 'ur',
];

/**
 * 商业短标题渗漏串（非专名）——精确子串。
 *
 * @var array<int, list<string>>
 */
$commercialLeakPatterns = [
    345 => [
        '汉服成人女日常小清新魏晋',
    ],
    348 => [
        '立领新兔绒汉服女冬明制刺',
        '立领新兔绒汉服女冬Ming-style刺',
    ],
    354 => [
        '春季汉服宋制绣花长袖日常',
        '春季汉服Song-style绣花长袖日常',
    ],
    359 => [
        '魏晋风广袖成人女装款春夏',
    ],
    361 => [
        '成人立领明制汉服马面裙女',
        '成人立领Ming-style汉服马面裙女',
    ],
    372 => [
        '成人女装 汉服女唐制齐胸',
        '成人女装 汉服女Tang-style齐胸',
    ],
    386 => [
        '女装新款汉服女拜年服加厚',
    ],
    397 => [
        '曹州成人汉紫嫣汉服女唐制',
        '曹州成人汉紫嫣汉服女Tang-style',
    ],
    416 => [
        '新款花嫁成人汉服女中国风',
    ],
];

/**
 * 混写标题（中文夹本地化制式词）——整段替换为 locale name。
 *
 * @var array<int, list<string>> regex without delimiters
 */
$commercialLeakRegex = [
    348 => [
        '立领新兔绒汉服女冬[^\n<>]{0,32}刺',
        '立领新兔绒汉服女冬',
    ],
    354 => [
        '春季汉服[^\n<>]{0,32}绣花长袖日常',
        '春季汉服宋制绣花长袖日常',
        '绣花长袖日常',
        '春季汉服',
    ],
    361 => [
        '成人立领[^\n<>]{0,32}汉服马面裙女',
        '成人立领明制汉服马面裙女',
        '汉服马面裙女',
        '成人立领',
    ],
    372 => [
        '成人女装\s*汉服女[^\n<>]{0,32}齐胸',
        '成人女装\s*汉服女唐制齐胸',
    ],
    397 => [
        '曹州成人汉紫嫣汉服女[^\n<>「»«"“”·]{0,40}',
    ],
];

/** 专名（允许各语保留中文） */
$properShorts = [
    228 => '芳华国色',
    251 => '凌云',
    303 => '炫彩战国袍',
];

function wave6FetchDesc(PDO $pdo, int $productId, string $locale): string
{
    $st = $pdo->prepare(
        "SELECT value_text FROM w_product_ws_0_attribute_value
         WHERE entity_id=? AND attribute_code='description' AND store_id=0 AND locale=?
         ORDER BY length(COALESCE(value_text,'')) DESC LIMIT 1"
    );
    $st->execute([(string)$productId, $locale]);

    return (string)$st->fetchColumn();
}

function wave6FetchName(PDO $pdo, int $productId, string $locale): string
{
    $st = $pdo->prepare(
        "SELECT value_text FROM w_product_ws_0_attribute_value
         WHERE entity_id=? AND attribute_code='name' AND store_id=0 AND locale=?
         LIMIT 1"
    );
    $st->execute([(string)$productId, $locale]);

    return trim((string)$st->fetchColumn());
}

/**
 * @param list<string> $patterns
 * @param array<int, list<string>> $commercialLeakPatterns
 * @param array<int, list<string>> $commercialLeakRegex
 * @param array<int, string> $properShorts
 * @return array{html:string, changes:list<string>}
 */
function wave6RemediateHtml(
    string $html,
    string $baseKey,
    int $productId,
    array $poemTitle,
    array $brandOurShop,
    array $htmlLang,
    array $commercialLeakPatterns,
    array $commercialLeakRegex,
    array $properShorts,
    string $localeName,
): array {
    if ($html === '' || strlen($html) < 80) {
        return ['html' => $html, 'changes' => ['EMPTY']];
    }

    $changes = [];
    $out = $html;

    // 1) Verse aside eyebrow → target locale (en keeps English)
    if ($baseKey !== 'en_US' && $baseKey !== 'zh_Hans_CN') {
        $targetPoem = $poemTitle[$baseKey] ?? $poemTitle['en_US'];
        if (str_contains($out, 'Verse aside')) {
            $out = str_replace('Verse aside', $targetPoem, $out);
            $changes[] = 'poem_title';
        }
        if (str_contains($out, '诗意旁笺')) {
            $out = str_replace('诗意旁笺', $targetPoem, $out);
            $changes[] = 'poem_title_zh';
        }
    }

    // 2) lang= on verse-vertical
    $lang = $htmlLang[$baseKey] ?? 'en';
    if (preg_match('/weline-detail-prose--verse-vertical"\s+lang="zh-Hans"/', $out)
        && $baseKey !== 'zh_Hans_CN'
    ) {
        $out = preg_replace(
            '/(weline-detail-prose--verse-vertical")\s+lang="zh-Hans"/',
            '$1 lang="' . $lang . '"',
            $out,
            1
        ) ?? $out;
        $changes[] = 'verse_lang';
    }

    // 3) Brand 本店
    if ($baseKey !== 'zh_Hans_CN' && str_contains($out, '本店')) {
        $brand = $brandOurShop[$baseKey] ?? 'Our shop';
        $out = str_replace('本店', $brand, $out);
        $changes[] = 'brand_本店';
    }

    // 4) Commercial Chinese short titles in non-zh
    if ($baseKey !== 'zh_Hans_CN' && isset($commercialLeakPatterns[$productId])) {
        $display = $localeName !== '' ? $localeName : 'Hanfu';
        $patterns = $commercialLeakPatterns[$productId];
        usort($patterns, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($patterns as $pat) {
            if ($pat !== '' && str_contains($out, $pat)) {
                $out = str_replace($pat, $display, $out);
                $changes[] = 'title_leak';
            }
        }
    }

    // 4b) Hybrid title with localized dynasty/style tokens
    if ($baseKey !== 'zh_Hans_CN' && isset($commercialLeakRegex[$productId])) {
        $display = $localeName !== '' ? $localeName : 'Hanfu';
        foreach ($commercialLeakRegex[$productId] as $rx) {
            $replaced = preg_replace('/' . $rx . '/u', $display, $out);
            if (is_string($replaced) && $replaced !== $out) {
                $out = $replaced;
                $changes[] = 'title_leak_rx';
            }
        }
    }

    // 5) Proper-name products: only clean non-proper marketplace tokens if any
    if ($baseKey !== 'zh_Hans_CN' && isset($properShorts[$productId])) {
        foreach (['本店', '大码', '斤女', '女原创古风', '重工汉服', '原创古风'] as $tok) {
            if (str_contains($out, $tok)) {
                $rep = $tok === '本店' ? ($brandOurShop[$baseKey] ?? 'Our shop') : '';
                $out = str_replace($tok, $rep, $out);
                $changes[] = 'proper_clean:' . $tok;
            }
        }
    }

    $out = preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out;
    $out = preg_replace('/\s+·\s+·/u', ' · ', $out) ?? $out;

    $changes = array_values(array_unique($changes));

    return ['html' => $out, 'changes' => $changes];
}

$attributes = $om->get(AttributeValueRepository::class);
$writes = [];
$summary = [];

foreach ($productIds as $productId) {
    echo "===== PRODUCT {$productId} =====" . PHP_EOL;
    foreach ($localePlan as $locale => $baseKey) {
        $html = wave6FetchDesc($pdo, $productId, $locale);
        $nameLocale = $locale !== '' ? $locale : 'zh_Hans_CN';
        $name = wave6FetchName($pdo, $productId, $nameLocale);
        if ($name === '' && $locale !== 'en_US') {
            $name = wave6FetchName($pdo, $productId, 'en_US');
        }
        // Avoid using wrong category-like zh name for 228 short title replacement
        // (proper-name products do not replace Chinese titles anyway)
        $result = wave6RemediateHtml(
            $html,
            $baseKey,
            $productId,
            $poemTitle,
            $brandOurShop,
            $htmlLang,
            $commercialLeakPatterns,
            $commercialLeakRegex,
            $properShorts,
            $name,
        );
        $label = $locale === '' ? '(empty)' : $locale;
        if ($result['changes'] === []) {
            echo "  {$label}: OK (no change)" . PHP_EOL;
            continue;
        }
        if ($result['changes'] === ['EMPTY']) {
            echo "  {$label}: EMPTY skip" . PHP_EOL;
            $summary[] = "{$productId}/{$label}:EMPTY";
            continue;
        }
        echo '  ' . $label . ': FIX ' . implode(',', $result['changes'])
            . ' len ' . strlen($html) . '→' . strlen($result['html']) . PHP_EOL;
        $writes[] = [
            'product_id' => $productId,
            'locale' => $locale,
            'html' => $result['html'],
            'changes' => $result['changes'],
        ];
        $summary[] = "{$productId}/{$label}:" . implode('+', $result['changes']);
    }
}

echo PHP_EOL . 'Pending writes: ' . count($writes) . PHP_EOL;

if (!$apply) {
    echo "Dry-run only. Pass --apply to write." . PHP_EOL;
    exit(0);
}

$touchedProducts = [];
foreach ($writes as $w) {
    $productId = (int)$w['product_id'];
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    $touchedProducts[$productId] = true;

    if ($locale !== '') {
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);

    if (str_contains($html, 'data-weds') && strlen($html) > 500) {
        $pdo->prepare(
            "DELETE FROM w_product_ws_0_attribute_value
             WHERE entity_id=? AND attribute_code='description' AND store_id=0 AND locale=?
               AND length(COALESCE(value_text,'')) < 200
               AND COALESCE(value_text,'') NOT LIKE '%data-weds%'"
        )->execute([(string)$productId, $locale]);
    }
}

$ids = array_map('intval', array_keys($touchedProducts));
$om->get(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
    $websiteId,
    'wave6_slot3_i18n_leaks',
    ['product_ids' => $ids],
);
$om->get(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('wave6_slot3_i18n_leaks');

echo 'Applied ' . count($writes) . ' locale descriptions for products '
    . implode(',', $ids) . '.' . PHP_EOL;
