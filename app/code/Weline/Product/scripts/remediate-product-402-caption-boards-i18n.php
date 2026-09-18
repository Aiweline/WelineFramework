<?php

declare(strict_types=1);

/**
 * #402：detail-02/03 文图拼版烤字（原创声明/颜色展示/设计亮点）仍入架 → 裁切感 + 不可真译。
 * 删拼版 → 语义 HTML；古诗可留中文。
 *
 * php app/code/Weline/Product/scripts/remediate-product-402-caption-boards-i18n.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-product-402-caption-boards-i18n.php --apply
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
$productId = 402;

/** detail-03 设计亮点/颜色对照拼版 */
$assetDesignBoard = '143f0663-e3e5-4630-bce8-3bdcd303a566';
/** detail-02 原创声明/颜色展示拼版 */
$assetOriginalBoard = 'ce8eff51-7f48-4364-bd1a-4a2bb948e548';

$packs = [
    'zh_Hans_CN' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => '原创声明',
        'original_body' => '此款已申请原创保护。抄袭盗版，违者必究。',
        'original_badge' => '审核为原创',
        'color_title' => '颜色展示',
        'highlight_title' => '设计亮点',
        'hl_print_title' => '精美印花',
        'hl_print_body' => '原创图案，清晰精致，典雅华贵；裙头坠仿珍珠装饰，层次丰富。',
        'hl_fabric_title' => '舒适面料',
        'hl_fabric_body' => '柔软亲肤，垂顺质感；华丽飘逸大裙摆，灵动仙气。',
        'color_green' => '绿色',
        'color_red' => '红色',
    ],
    'en_US' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'Originality statement',
        'original_body' => 'This design is originality-protected. Copying or piracy will be pursued.',
        'original_badge' => 'Verified original',
        'color_title' => 'Color display',
        'highlight_title' => 'Design highlights',
        'hl_print_title' => 'Fine print',
        'hl_print_body' => 'Original motifs, clear and delicate; pearl-like accents at the skirt head add layered detail.',
        'hl_fabric_title' => 'Comfortable fabric',
        'hl_fabric_body' => 'Soft on skin with a smooth drape; a flowing skirt hem for an ethereal step.',
        'color_green' => 'Green',
        'color_red' => 'Red',
    ],
    'es_ES' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'Declaración de originalidad',
        'original_body' => 'Este diseño está protegido como original. La copia o piratería será perseguida.',
        'original_badge' => 'Verificado como original',
        'color_title' => 'Exhibición de colores',
        'highlight_title' => 'Puntos de diseño',
        'hl_print_title' => 'Estampado fino',
        'hl_print_body' => 'Motivos originales, claros y delicados; acentos tipo perla en la cintura de la falda aportan capas.',
        'hl_fabric_title' => 'Tejido cómodo',
        'hl_fabric_body' => 'Suave al tacto y con caída fluida; falda amplia para un paso etéreo.',
        'color_green' => 'Verde',
        'color_red' => 'Rojo',
    ],
    'fr_FR' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'Déclaration d’originalité',
        'original_body' => 'Ce modèle est protégé au titre de l’originalité. Contrefaçon et piratage seront poursuivis.',
        'original_badge' => 'Vérifié comme original',
        'color_title' => 'Présentation des couleurs',
        'highlight_title' => 'Points forts du design',
        'hl_print_title' => 'Impression fine',
        'hl_print_body' => 'Motifs originaux, nets et délicats ; accents façon perle à la tête de jupe pour plus de relief.',
        'hl_fabric_title' => 'Tissu confortable',
        'hl_fabric_body' => 'Doux sur la peau, tombé fluide ; grande jupe pour une allure aérienne.',
        'color_green' => 'Vert',
        'color_red' => 'Rouge',
    ],
    'pt_BR' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'Declaração de originalidade',
        'original_body' => 'Este design tem proteção de originalidade. Cópia ou pirataria serão responsabilizadas.',
        'original_badge' => 'Verificado como original',
        'color_title' => 'Exibição de cores',
        'highlight_title' => 'Destaques do design',
        'hl_print_title' => 'Estampa fina',
        'hl_print_body' => 'Motivos originais, nítidos e delicados; detalhes tipo pérola na cintura da saia aumentam as camadas.',
        'hl_fabric_title' => 'Tecido confortável',
        'hl_fabric_body' => 'Macio na pele, com caimento fluido; saia ampla para um passo etéreo.',
        'color_green' => 'Verde',
        'color_red' => 'Vermelho',
    ],
    'id_ID' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'Pernyataan keaslian',
        'original_body' => 'Desain ini dilindungi sebagai karya asli. Penyalinan atau pembajakan akan ditindak.',
        'original_badge' => 'Terverifikasi asli',
        'color_title' => 'Tampilan warna',
        'highlight_title' => 'Sorotan desain',
        'hl_print_title' => 'Cetakan halus',
        'hl_print_body' => 'Motif asli, jelas dan lembut; aksen mirip mutiara di pinggang rok menambah lapisan.',
        'hl_fabric_title' => 'Kain nyaman',
        'hl_fabric_body' => 'Lembut di kulit dengan jatuh halus; rok lebar untuk langkah yang ringan.',
        'color_green' => 'Hijau',
        'color_red' => 'Merah',
    ],
    'ar_SA' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'بيان الأصالة',
        'original_body' => 'هذا التصميم محمي بوصفه أصلياً. سيتم ملاحقة النسخ أو القرصنة.',
        'original_badge' => 'موثّق كأصلي',
        'color_title' => 'عرض الألوان',
        'highlight_title' => 'أبرز ملامح التصميم',
        'hl_print_title' => 'طباعة دقيقة',
        'hl_print_body' => 'زخارف أصلية واضحة ورقيقة؛ لمسات شبيهة باللؤلؤ عند رأس التنورة تضيف طبقات.',
        'hl_fabric_title' => 'قماش مريح',
        'hl_fabric_body' => 'ناعم على البشرة بانسياب سلس؛ تنورة واسعة لخطوة خفيفة.',
        'color_green' => 'أخضر',
        'color_red' => 'أحمر',
    ],
    'hi_IN' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'मौलिकता कथन',
        'original_body' => 'यह डिज़ाइन मौलिक सुरक्षा में है। नकल या चोरी पर कार्रवाई होगी।',
        'original_badge' => 'मूल के रूप में सत्यापित',
        'color_title' => 'रंग प्रदर्शन',
        'highlight_title' => 'डिज़ाइन मुख्य बिंदु',
        'hl_print_title' => 'सूक्ष्म प्रिंट',
        'hl_print_body' => 'मूल रूपांक, स्पष्ट और नाज़ुक; स्कर्ट शीर्ष पर मोती-समान विवरण परतें जोड़ते हैं।',
        'hl_fabric_title' => 'आरामदायक कपड़ा',
        'hl_fabric_body' => 'त्वचा पर नरम, सहज ड्रेप; हवादार कदम के लिए विशाल स्कर्ट।',
        'color_green' => 'हरा',
        'color_red' => 'लाल',
    ],
    'bn_BD' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'মৌলিকত্বের বিবৃতি',
        'original_body' => 'এই নকশা মৌলিক সুরক্ষায় আছে। নকল বা পাইরেসি শাস্তিযোগ্য।',
        'original_badge' => 'মূল হিসেবে যাচাইকৃত',
        'color_title' => 'রঙ প্রদর্শন',
        'highlight_title' => 'নকশার হাইলাইট',
        'hl_print_title' => 'সূক্ষ্ম প্রিন্ট',
        'hl_print_body' => 'মূল মোটিফ, স্পষ্ট ও কোমল; স্কার্ট মাথায় মুক্তোর মতো বিবরণ স্তর বাড়ায়।',
        'hl_fabric_title' => 'আরামদায়ক কাপড়',
        'hl_fabric_body' => 'ত্বকে নরম, মসৃণ ড্রেপ; হালকা পদক্ষেপের জন্য প্রশস্ত স্কার্ট।',
        'color_green' => 'সবুজ',
        'color_red' => 'লাল',
    ],
    'ur_PK' => [
        'verse' => '飘风屯其相离兮，帅云霓而来御',
        'original_title' => 'اصالت کا بیان',
        'original_body' => 'یہ ڈیزائن اصالت کے تحفظ میں ہے۔ نقل یا چوری پر کارروائی ہوگی۔',
        'original_badge' => 'اصل کے طور پر تصدیق شدہ',
        'color_title' => 'رنگوں کی نمائش',
        'highlight_title' => 'ڈیزائن کے نمایاں نکات',
        'hl_print_title' => 'باریک چھپائی',
        'hl_print_body' => 'اصل نقوش، واضح اور نازک؛ اسکرٹ کے سرے پر موتی نما تفصیل تہیں بڑھاتی ہے۔',
        'hl_fabric_title' => 'آرام دہ کپڑا',
        'hl_fabric_body' => 'جلد پر نرم، ہموار ڈریپ؛ ہلکے قدم کے لیے وسیع اسکرٹ۔',
        'color_green' => 'سبز',
        'color_red' => 'سرخ',
    ],
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$buildBlocks = static function (array $t) use ($h): string {
    $verse = DetailDescriptionTextifier::sanitizeFragment(
        '<div class="weline-detail-prose weline-detail-prose--verse" data-weline-detail-text="verse-li-sao" lang="zh-Hans">'
        . '<p>' . $h((string)$t['verse']) . '</p>'
        . '</div>'
    );
    $original = DetailDescriptionTextifier::sanitizeFragment(
        '<div class="weline-detail-prose" data-weline-detail-text="originality">'
        . '<h3>' . $h((string)$t['original_title']) . '</h3>'
        . '<p>' . $h((string)$t['original_body']) . '</p>'
        . '<p class="weline-detail-feature__note">' . $h((string)$t['original_badge']) . '</p>'
        . '</div>'
    );
    $color = DetailDescriptionTextifier::buildSectionHeading((string)$t['color_title'], 'color-display');
    $highlights = DetailDescriptionTextifier::sanitizeFragment(
        '<div class="weline-detail-prose weline-detail-prose--checklist" data-weline-detail-text="design-highlights">'
        . '<h3>' . $h((string)$t['highlight_title']) . '</h3>'
        . '<ul>'
        . '<li><strong>' . $h((string)$t['hl_print_title']) . '</strong> — ' . $h((string)$t['hl_print_body']) . '</li>'
        . '<li><strong>' . $h((string)$t['hl_fabric_title']) . '</strong> — ' . $h((string)$t['hl_fabric_body']) . '</li>'
        . '<li><strong>' . $h((string)$t['color_green']) . '</strong> / <strong>' . $h((string)$t['color_red']) . '</strong></li>'
        . '</ul>'
        . '</div>'
    );

    return $verse . $original . $color . $highlights;
};

$stripEmpty = static function (string $html): string {
    $html = preg_replace('#<div class="weline-detail-figure"\s*>\s*</div>#i', '', $html) ?? $html;
    $html = preg_replace('#<div class="weline-detail-figure-row[^"]*"\s*>\s*</div>#i', '', $html) ?? $html;
    $html = preg_replace(
        '#<div class="weline-detail-figure-stack[^"]*"[^>]*>\s*</div>#i',
        '',
        $html
    ) ?? $html;
    $html = preg_replace('#<(p|div|span)\b[^>]*>\s*</\1>#i', '', $html) ?? $html;

    return $html;
};

$removeAssetAndWrappers = static function (string $html, string $assetId) use ($stripEmpty): string {
    // Only strip the <img> (and its empty figure); never match across prior figure-stacks
    // with a broad "stack...img...two closes" regex — that left orphan </div> and cropped the PDP shell.
    $next = DetailDescriptionTextifier::replaceAssetImageWithHtml($html, $assetId, '');
    if ($next === $html) {
        $img = '#<img\b[^>]*\bsrc=(["\'])asset://' . preg_quote($assetId, '#') . '\1[^>]*/?>#i';
        $next = preg_replace($img, '', $html) ?? $html;
    }
    $next = preg_replace(
        '#<div class="weline-detail-figure"\s*>\s*</div>#i',
        '',
        $next
    ) ?? $next;
    // Collapse pair → solo when one cell remains.
    $next = preg_replace(
        '#<div class="weline-detail-figure-row weline-detail-figure-row--pair">\s*(<div class="weline-detail-figure">.*?</div>)\s*</div>#is',
        '<div class="weline-detail-figure-row weline-detail-figure-row--solo">$1</div>',
        $next
    ) ?? $next;

    return $stripEmpty($next);
};

$insertBlocks = static function (string $html, string $blocks): string {
    foreach (['originality', 'color-display', 'design-highlights'] as $marker) {
        if (str_contains($html, 'data-weline-detail-text="' . $marker . '"')) {
            // refresh: strip old blocks then reinsert
            $html = preg_replace(
                '#<div class="weline-detail-prose[^"]*"[^>]*data-weline-detail-text="' . preg_quote($marker, '#') . '"[^>]*>.*?</div>#is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '#<div class="weline-detail-text weline-detail-text--section"[^>]*data-weline-detail-text="' . preg_quote($marker, '#') . '"[^>]*>.*?</div>#is',
                '',
                $html
            ) ?? $html;
        }
    }
    $html = preg_replace(
        '#<div class="weline-detail-prose weline-detail-prose--verse"[^>]*data-weline-detail-text="verse-li-sao"[^>]*>.*?</div>#is',
        '',
        $html
    ) ?? $html;

    // Place after first figure stack / feature, before remaining photos.
    if (preg_match('#(<div class="weline-detail-figure-stack[^"]*"[^>]*>.*?</div>\s*</div>\s*</div>)#is', $html, $m, PREG_OFFSET_CAPTURE)) {
        $end = $m[0][1] + strlen($m[0][0]);

        return substr($html, 0, $end) . $blocks . substr($html, $end);
    }

    return $blocks . $html;
};

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

/** @var \Weline\Websites\Model\Website $websiteModel */
$websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->loadById($websiteId)
    ?: ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->load($websiteId);
$enabledLocales = $websiteModel ? array_values(array_filter(array_map('strval', (array)$websiteModel->getLanguageCodes()))) : [];
if ($enabledLocales === []) {
    $enabledLocales = array_keys($packs);
}
$defaultLang = $websiteModel ? (string)$websiteModel->getDefaultLanguage() : 'en_US';
if ($defaultLang === '' || !isset($packs[$defaultLang])) {
    $defaultLang = 'en_US';
}

$localePlan = ['' => $defaultLang];
foreach ($enabledLocales as $code) {
    $localePlan[$code] = $code;
}

echo "ENABLED LOCALES (+empty):\n";
foreach ($localePlan as $loc => $base) {
    echo '  [' . ($loc === '' ? '(empty)' : $loc) . "] => {$base}\n";
}

$zhLeak = ['原创声明', '颜色展示', '设计亮点', '精美印花', '舒适面料', '审核为原创', '抄袭盗版'];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($packs[$baseKey])) {
        fwrite(STDERR, "missing pack {$baseKey}\n");
        exit(2);
    }
    $t = $packs[$baseKey];
    $st = $pdo->prepare(
        "SELECT value_text FROM w_product_ws_0_attribute_value
         WHERE entity_id=? AND attribute_code='description' AND locale=?
         ORDER BY length(COALESCE(value_text,'')) DESC LIMIT 1"
    );
    $st->execute([(string)$productId, $locale]);
    $html = (string)$st->fetchColumn();
    if ($html === '') {
        fwrite(STDERR, "no description {$locale}\n");
        exit(2);
    }

    $had2 = str_contains($html, 'asset://' . $assetOriginalBoard);
    $had3 = str_contains($html, 'asset://' . $assetDesignBoard);
    $html = $removeAssetAndWrappers($html, $assetOriginalBoard);
    $html = $removeAssetAndWrappers($html, $assetDesignBoard);
    // If pair left a single empty stack remnant after both removals, strip again.
    $html = $stripEmpty($html);
    $html = $insertBlocks($html, $buildBlocks($t));

    if (str_contains($html, 'asset://' . $assetOriginalBoard) || str_contains($html, 'asset://' . $assetDesignBoard)) {
        fwrite(STDERR, "caption board still present {$locale}\n");
        exit(3);
    }
    if ($baseKey !== 'zh_Hans_CN') {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Classical verse may remain; forbid other baked ZH panel markers outside verse.
        foreach ($zhLeak as $marker) {
            if (str_contains($plain, $marker)) {
                fwrite(STDERR, "ZH leak {$locale}: {$marker}\n");
                exit(3);
            }
        }
    }
    foreach (['originality', 'color-display', 'design-highlights'] as $need) {
        if (!str_contains($html, 'data-weline-detail-text="' . $need . '"')) {
            fwrite(STDERR, "missing {$need} for {$locale}\n");
            exit(3);
        }
    }

    $writes[] = [
        'locale' => $locale,
        'base' => $baseKey,
        'len' => strlen($html),
        'had2' => $had2,
        'had3' => $had3,
        'html' => $html,
    ];
    echo sprintf(
        "plan %s base=%s len=%d boards=%s/%s\n",
        $locale === '' ? '(empty)' : $locale,
        $baseKey,
        strlen($html),
        $had2 ? 'Y' : 'N',
        $had3 ? 'Y' : 'N'
    );
}

if (!$apply) {
    echo "Dry-run only. Pass --apply to write.\n";
    exit(0);
}

foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
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

ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
    $websiteId,
    'product_402_caption_boards_i18n',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('product_402_caption_boards_i18n');

echo "Applied " . count($writes) . " locale descriptions for product {$productId}.\n";
