<?php

declare(strict_types=1);

/**
 * 产品优化槽③：wave5 12 品 — 启用语 description 渗漏修复
 * - 非 EN：将英文 eyebrow「Verse aside」真译为目标语
 * - 非中文：清「本店」与商业短标题中文渗漏（专名可留）
 * - 修正 poem-aside verse-vertical 的 lang=
 *
 * php app/code/Weline/Product/scripts/remediate-wave5-slot3-i18n-leaks.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-wave5-slot3-i18n-leaks.php --apply
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

$productIds = [443, 444, 448, 456, 458, 480, 481, 520, 177, 210, 211, 217];

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
 * 商业短标题（非专名）→ 各语展示名；专名保留中文。
 *
 * @var array<int, array{keep?:string, titles: array<string, string>}>
 */
$commercialTitles = [
    443 => [
        'titles' => [
            'en_US' => 'Que-lan heavy embroidery',
            'es_ES' => 'Bordado Que-lan',
            'fr_FR' => 'Broderie Que-lan',
            'pt_BR' => 'Bordado Que-lan',
            'id_ID' => 'Sulaman Que-lan',
            'ar_SA' => 'تطريز كوي لان',
            'bn_BD' => 'কুই-লান ভারী সূচিকর্ম',
            'hi_IN' => 'क्वे-लान भारी कढ़ाई',
            'ur_PK' => 'کوے-لان بھاری کڑھائی',
        ],
    ],
    448 => [
        'titles' => [
            'en_US' => 'Plus-size hezi skirt set',
            'es_ES' => 'Conjunto hezi talla grande',
            'fr_FR' => 'Ensemble hezi grande taille',
            'pt_BR' => 'Conjunto hezi plus size',
            'id_ID' => 'Set rok hezi ukuran besar',
            'ar_SA' => 'طقم تنورة هي زي بمقاسات كبيرة',
            'bn_BD' => 'প্লাস-সাইজ হেজি স্কার্ট সেট',
            'hi_IN' => 'प्लस-साइज़ हेज़ी स्कर्ट सेट',
            'ur_PK' => 'پلس سائز ہیزی اسکرٹ سیٹ',
        ],
    ],
    481 => [
        'keep' => '粉樱',
        'titles' => [
            'en_US' => 'Pink Cherry',
            'es_ES' => 'Cerezo rosa',
            'fr_FR' => 'Cerisier rose',
            'pt_BR' => 'Cerejeira rosa',
            'id_ID' => 'Sakura merah muda',
            'ar_SA' => 'كرز وردي',
            'bn_BD' => 'গোলাপি চেরি',
            'hi_IN' => 'गुलाबी चेरी',
            'ur_PK' => 'گلابی چیری',
        ],
    ],
    520 => [
        'keep' => '锦衣卫飞鱼服',
        'titles' => [
            'en_US' => 'Jinyiwei flying-fish robe',
            'es_ES' => 'Túnica pez volador Jinyiwei',
            'fr_FR' => 'Robe poisson volant Jinyiwei',
            'pt_BR' => 'Túnica peixe-voador Jinyiwei',
            'id_ID' => 'Jubah ikan terbang Jinyiwei',
            'ar_SA' => 'رداء السمكة الطائرة جين يي وي',
            'bn_BD' => 'জিনিইওয়েই উড়ন্ত-মাছ পোশাক',
            'hi_IN' => 'जिनयीवेई उड़न-मछली वस्त्र',
            'ur_PK' => 'جن یی وی اڑتی مچھلی کا لباس',
        ],
    ],
];

/** 专名（允许各语保留中文） */
$properShorts = [
    444 => '异域之风',
    456 => '糯米糕',
    458 => '凤求凰',
    480 => '簪红',
    177 => '鎏金夜宴',
    210 => '一鹭生花',
    211 => '玉蝴蝶',
    217 => '春染',
];

function wave5FetchDesc(PDO $pdo, int $productId, string $locale): string
{
    $st = $pdo->prepare(
        "SELECT value_text FROM w_product_ws_0_attribute_value
         WHERE entity_id=? AND attribute_code='description' AND store_id=0 AND locale=?
         ORDER BY length(COALESCE(value_text,'')) DESC LIMIT 1"
    );
    $st->execute([(string)$productId, $locale]);

    return (string)$st->fetchColumn();
}

function wave5FetchName(PDO $pdo, int $productId, string $locale): string
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
 * @return array{html:string, changes:list<string>}
 */
function wave5RemediateHtml(
    string $html,
    string $baseKey,
    int $productId,
    array $poemTitle,
    array $brandOurShop,
    array $htmlLang,
    array $commercialTitles,
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
        // also fix if somehow still Chinese eyebrow in non-zh
        if (str_contains($out, '诗意旁笺') && $baseKey !== 'zh_Hans_CN') {
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
    if ($baseKey !== 'zh_Hans_CN' && isset($commercialTitles[$productId])) {
        $pack = $commercialTitles[$productId];
        $zhKeep = (string)($pack['keep'] ?? '');
        $localized = (string)($pack['titles'][$baseKey] ?? ($pack['titles']['en_US'] ?? $localeName));
        if ($localized === '') {
            $localized = $localeName !== '' ? $localeName : 'Hanfu';
        }

        // Known leak patterns from suite short extractor
        $patterns = match ($productId) {
            443 => ['雀兰调重工汉服女原创古风', '雀兰调重工Hanfu女原创古风', '雀兰调重工هانفو女原创古风', '雀兰调重工হানফু女原创古风', '雀兰调重工हानफ़ू女原创古风', '雀兰调重工ہانفو女原创古风'],
            448 => ['柯子裙汉服大码300斤女', '柯子裙Hanfu大码300斤女', '柯子裙هانفو大码300斤女', '柯子裙হানফু大码300斤女', '柯子裙हानफ़ू大码300斤女', '柯子裙ہانفو大码300斤女'],
            481 => ['粉樱中国风原创汉服女唐制', '粉樱中国风原创Hanfu女唐制', '粉樱中国风原创هانفو女唐制', '粉樱中国风原创হানফু女唐制', '粉樱中国风原创हानफ़ू女唐制', '粉樱中国风原创ہانفو女唐制'],
            520 => ['锦衣卫飞鱼服明制汉服男织', '锦衣卫飞鱼服明制Hanfu男织', '锦衣卫飞鱼服明制هانفو男织', '锦衣卫飞鱼服明制হানফু男织', '锦衣卫飞鱼服明制हानफ़ू男织', '锦衣卫飞鱼服明制ہانفو男织'],
            default => [],
        };

        $display = ($zhKeep !== '' && $baseKey === 'zh_Hans_CN') ? $zhKeep : $localized;
        // For non-zh always use localized display (keep Chinese proper name only when short poetic name)
        if ($zhKeep !== '' && in_array($baseKey, ['en_US', 'es_ES', 'fr_FR', 'pt_BR', 'id_ID'], true)) {
            // bilingual: localized + Chinese proper in parentheses optional — prefer localized only to clear leak
            $display = $localized;
        }

        foreach ($patterns as $pat) {
            if (str_contains($out, $pat)) {
                $out = str_replace($pat, $display, $out);
                $changes[] = 'title_leak';
            }
        }

        // residual commercial tokens
        $tokenMap = [
            '大码' => match ($baseKey) {
                'es_ES' => 'talla grande',
                'fr_FR' => 'grande taille',
                'pt_BR' => 'plus size',
                'id_ID' => 'ukuran besar',
                'ar_SA' => 'مقاسات كبيرة',
                'bn_BD' => 'প্লাস সাইজ',
                'hi_IN' => 'प्लस साइज़',
                'ur_PK' => 'پلس سائز',
                default => 'plus-size',
            },
            '斤女' => '',
            '女原创古风' => '',
            '重工汉服' => match ($baseKey) {
                'ar_SA' => 'تطريز ثقيل',
                'bn_BD' => 'ভারী সূচিকর্ম',
                'hi_IN' => 'भारी कढ़ाई',
                'ur_PK' => 'بھاری کڑھائی',
                'es_ES' => 'bordado pesado',
                'fr_FR' => 'broderie dense',
                'pt_BR' => 'bordado denso',
                'id_ID' => 'sulaman tebal',
                default => 'heavy embroidery',
            },
            '汉服女' => match ($baseKey) {
                'ar_SA' => 'هانفو',
                'bn_BD' => 'হানফু',
                'hi_IN' => 'हानफ़ू',
                'ur_PK' => 'ہانفو',
                default => 'Hanfu',
            },
            '柯子裙' => match ($baseKey) {
                'ar_SA' => 'تنورة هي زي',
                'bn_BD' => 'হেজি স্কার্ট',
                'hi_IN' => 'हेज़ी स्कर्ट',
                'ur_PK' => 'ہیزی اسکرٹ',
                'es_ES', 'fr_FR', 'pt_BR' => 'hezi',
                'id_ID' => 'rok hezi',
                default => 'hezi skirt',
            },
            '原创古风' => '',
            '中国风原创' => '',
            '明制汉服男织' => match ($baseKey) {
                'ar_SA' => 'طراز مينغ منسوج',
                'bn_BD' => 'মিং বোনা',
                'hi_IN' => 'मिंग बुना',
                'ur_PK' => 'منگ بنا ہوا',
                default => 'Ming weave',
            },
        ];
        foreach ($tokenMap as $zhTok => $rep) {
            if ($zhTok !== '' && str_contains($out, $zhTok)) {
                $out = str_replace($zhTok, $rep, $out);
                $changes[] = 'tok:' . $zhTok;
            }
        }
    }

    // 5) Proper-name products: strip leftover marketplace Chinese around name if any
    if ($baseKey !== 'zh_Hans_CN' && isset($properShorts[$productId])) {
        foreach (['本店', '大码', '斤女', '女原创古风', '重工汉服', '原创古风'] as $tok) {
            if (str_contains($out, $tok)) {
                $rep = $tok === '本店' ? ($brandOurShop[$baseKey] ?? 'Our shop') : '';
                $out = str_replace($tok, $rep, $out);
                $changes[] = 'proper_clean:' . $tok;
            }
        }
    }

    // collapse doubled spaces from empty replacements
    $out = preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out;
    $out = preg_replace('/\s+·\s+·/u', ' · ', $out) ?? $out;
    $out = preg_replace('/>\s+·\s+</u', '><', $out) ?? $out;

    $changes = array_values(array_unique($changes));

    return ['html' => $out, 'changes' => $changes];
}

$attributes = $om->get(AttributeValueRepository::class);
$writes = [];
$summary = [];

foreach ($productIds as $productId) {
    echo "===== PRODUCT {$productId} =====" . PHP_EOL;
    foreach ($localePlan as $locale => $baseKey) {
        $html = wave5FetchDesc($pdo, $productId, $locale);
        $name = wave5FetchName($pdo, $productId, $locale !== '' ? $locale : 'zh_Hans_CN');
        if ($name === '' && $locale !== 'en_US') {
            $name = wave5FetchName($pdo, $productId, 'en_US');
        }
        $result = wave5RemediateHtml(
            $html,
            $baseKey,
            $productId,
            $poemTitle,
            $brandOurShop,
            $htmlLang,
            $commercialTitles,
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
    'wave5_slot3_i18n_leaks',
    ['product_ids' => $ids],
);
$om->get(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('wave5_slot3_i18n_leaks');

echo 'Applied ' . count($writes) . ' locale descriptions for products '
    . implode(',', $ids) . '.' . PHP_EOL;
