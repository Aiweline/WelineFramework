<?php

declare(strict_types=1);

/**
 * §3.2‑C：清掉详情里「原创声明」拼版 / 「设计灵感」烤字板，换净图 + HTML 真译。
 *
 * php app/code/Weline/Product/scripts/remediate-caption-boards-originality-inspire.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-caption-boards-originality-inspire.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));

$jobs = [
    236 => [
        // Descriptions store asset://UUID; storefront resolves to object_key.
        'drop_needles' => [
            '78de7088-317c-4fb5-bb3c-10ed49e11596', // detail-01 原创声明+设计灵感烤字拼版
            'af3c295b-2878-483c-b135-0ac7b5e35b27', // detail-02 细节展示/刺绣设计烤字板
            'detail-01-bfbebea02f9c.webp',
            'detail-02-bfe984c33515.webp',
        ],
        'still_needles' => [
            '78de7088-317c-4fb5-bb3c-10ed49e11596',
            'af3c295b-2878-483c-b135-0ac7b5e35b27',
            'detail-01-bfbebea02f9c.webp',
            'detail-02-bfe984c33515.webp',
        ],
        'cleans' => [
            [
                'rel' => 'catalog/hanfu/1688/factory-huazhaoji-cx/980969279321/detail-01-originality-photo-clean.webp',
                'src' => '/tmp/hf-detail-ocr/236_gen_clean.webp',
                'w' => 864,
                'h' => 1152,
                'alt_zh' => '昭昭公主 · 着装',
                'role' => 'portrait',
            ],
            [
                'rel' => 'catalog/hanfu/1688/factory-huazhaoji-cx/980969279321/detail-02-embroidery-clean.webp',
                'src' => '/tmp/hf-detail-ocr/236_embroidery_clean.webp',
                'w' => 864,
                'h' => 1152,
                'alt_zh' => '昭昭公主 · 刺绣特写',
                'role' => 'embroidery',
            ],
        ],
        'clean_rel' => 'catalog/hanfu/1688/factory-huazhaoji-cx/980969279321/detail-01-originality-photo-clean.webp',
        'clean_src' => '/tmp/hf-detail-ocr/236_gen_clean.webp',
        'clean_w' => 864,
        'clean_h' => 1152,
        'alt_zh' => '昭昭公主 · 着装',
        'packs' => [
            'zh_Hans_CN' => [
                'original_title' => '原创声明',
                'original_body' => '此款已申请原创保护。抄袭盗版，违者必究。',
                'inspire_title' => '设计心源',
                'inspire_lines' => ['古典，轻盈之美。'],
                'detail_title' => '刺绣设计',
                'detail_body' => '丝线在布上交织，花朵似要跃然而出。',
            ],
            'en_US' => [
                'original_title' => 'Originality statement',
                'original_body' => 'This design is originality-protected. Copying or piracy will be pursued.',
                'inspire_title' => 'Design wellspring',
                'inspire_lines' => ['Classical ease — light beauty.'],
                'detail_title' => 'Embroidery design',
                'detail_body' => 'Silk threads interlaced on cloth — flowers seem ready to leap out.',
            ],
            'fr_FR' => [
                'original_title' => 'Déclaration d’originalité',
                'original_body' => 'Ce modèle est protégé au titre de l’originalité. Contrefaçon et piratage seront poursuivis.',
                'inspire_title' => 'Source du dessin',
                'inspire_lines' => ['Beauté classique et légère.'],
                'detail_title' => 'Broderie',
                'detail_body' => 'Fils de soie entrelacés — les fleurs semblent prêtes à s’envoler.',
            ],
            'es_ES' => [
                'original_title' => 'Declaración de originalidad',
                'original_body' => 'Este diseño está protegido como original. La copia o piratería será perseguida.',
                'inspire_title' => 'Manantial del diseño',
                'inspire_lines' => ['Belleza clásica y ligera.'],
                'detail_title' => 'Bordado',
                'detail_body' => 'Hilos de seda entrelazados — las flores parecen saltar del tejido.',
            ],
            'pt_BR' => [
                'original_title' => 'Declaração de originalidade',
                'original_body' => 'Este design tem proteção de originalidade. Cópia ou pirataria serão responsabilizadas.',
                'inspire_title' => 'Nascente do desenho',
                'inspire_lines' => ['Beleza clássica e leve.'],
                'detail_title' => 'Bordado',
                'detail_body' => 'Fios de seda entrelaçados — as flores parecem saltar do tecido.',
            ],
            'id_ID' => [
                'original_title' => 'Pernyataan keaslian',
                'original_body' => 'Desain ini dilindungi sebagai karya asli. Penyalinan atau pembajakan akan ditindak.',
                'inspire_title' => 'Sumur desain',
                'inspire_lines' => ['Kecantikan klasik yang ringan.'],
                'detail_title' => 'Sulaman',
                'detail_body' => 'Benang sutra saling menjalin — bunga seolah hendak melompat keluar.',
            ],
            'hi_IN' => [
                'original_title' => 'मौलिकता वक्तव्य',
                'original_body' => 'यह डिज़ाइन मौलिकता-संरक्षित है। नकल या चोरी पर कार्रवाई होगी।',
                'inspire_title' => 'डिज़ाइन स्रोत',
                'inspire_lines' => ['शास्त्रीय, हल्की सुंदरता।'],
                'detail_title' => 'कढ़ाई डिज़ाइन',
                'detail_body' => 'रेशमी धागे बुने — फूल जैसे निकल पड़ने को हैं।',
            ],
            'ar_SA' => [
                'original_title' => 'بيان الأصالة',
                'original_body' => 'هذا التصميم محمي بوصفه أصلياً. سيتم ملاحقة النسخ أو القرصنة.',
                'inspire_title' => 'منبع التصميم',
                'inspire_lines' => ['جمال كلاسيكي خفيف.'],
                'detail_title' => 'تطريز',
                'detail_body' => 'خيوط حرير متداخلة — الزهور تكاد تقفز من القماش.',
            ],
            'bn_BD' => [
                'original_title' => 'মৌলিকত্ব বিবৃতি',
                'original_body' => 'এই নকশা মৌলিকত্ব-সুরক্ষিত। নকল বা চুরির বিরুদ্ধে ব্যবস্থা নেওয়া হবে।',
                'inspire_title' => 'ডিজাইনের উৎস',
                'inspire_lines' => ['ধ্রুপদী, হালকা সৌন্দর্য।'],
                'detail_title' => 'সূচিকর্ম',
                'detail_body' => 'রেশমি সুতো জড়ানো — ফুল যেন বেরিয়ে আসতে চায়।',
            ],
            'ur_PK' => [
                'original_title' => 'اصالت کا بیان',
                'original_body' => 'یہ ڈیزائن اصالت سے محفوظ ہے۔ نقل یا چوری پر کارروائی ہوگی۔',
                'inspire_title' => 'ڈیزائن کا چشمہ',
                'inspire_lines' => ['کلاسیکی، ہلکی خوبصورتی۔'],
                'detail_title' => 'کڑھائی',
                'detail_body' => 'ریشمی دھاگے گوندھے — پھول جیسے نکل پڑنے کو ہیں۔',
            ],
        ],
    ],
    235 => [
        'drop_needles' => [
            '4b2d7441-629d-4ecc-b595-4ffa319071e5', // detail-01 设计灵感烤字/配色示意板
            'detail-01-88fbb138b2e4.webp',
        ],
        'still_needles' => [
            '4b2d7441-629d-4ecc-b595-4ffa319071e5',
            'detail-01-88fbb138b2e4.webp',
        ],
        'cleans' => [
            [
                'rel' => 'catalog/hanfu/1688/factory-huazhaoji-cx/977531927363/detail-01-inspire-art-clean.webp',
                'src' => '/tmp/hf-detail-ocr/235_gen_clean.webp',
                'w' => 1280,
                'h' => 720,
                'alt_zh' => '小花神 · 配色示意',
                'role' => 'inspire_art',
            ],
        ],
        'clean_rel' => 'catalog/hanfu/1688/factory-huazhaoji-cx/977531927363/detail-01-inspire-art-clean.webp',
        'clean_src' => '/tmp/hf-detail-ocr/235_gen_clean.webp',
        'clean_w' => 1280,
        'clean_h' => 720,
        'alt_zh' => '小花神 · 配色示意',
        'packs' => [
            'zh_Hans_CN' => [
                'original_title' => '原创声明',
                'original_body' => '此款已申请原创保护。抄袭盗版，违者必究。',
                'inspire_title' => '设计心源',
                'inspire_lines' => [
                    '以「小花神」为题，构思粉、绿、紫三色。',
                    '绣花以落花铺满大袖与披帛；裙头渐变、裙身晕染印花。',
                    '通身写空灵与浪漫，非货盘腔调。',
                ],
            ],
            'en_US' => [
                'original_title' => 'Originality statement',
                'original_body' => 'This design is originality-protected. Copying or piracy will be pursued.',
                'inspire_title' => 'Design wellspring',
                'inspire_lines' => [
                    'Named “Little Flower Spirit” — pink, green, and violet color stories.',
                    'Falling-petal embroidery across wide sleeves and pibo; gradient waist and wash-print skirt.',
                    'Ethereal romance, not marketplace copy.',
                ],
            ],
            'fr_FR' => [
                'original_title' => 'Déclaration d’originalité',
                'original_body' => 'Ce modèle est protégé au titre de l’originalité. Contrefaçon et piratage seront poursuivis.',
                'inspire_title' => 'Source du dessin',
                'inspire_lines' => [
                    'Nommé « Petite esprit des fleurs » — rose, vert et violet.',
                    'Broderie de pétales sur manches larges et pibo ; ceinture dégradée, jupe en dégradé imprimé.',
                    'Romance éthérée, pas un discours de marché.',
                ],
            ],
            'es_ES' => [
                'original_title' => 'Declaración de originalidad',
                'original_body' => 'Este diseño está protegido como original. La copia o piratería será perseguida.',
                'inspire_title' => 'Manantial del diseño',
                'inspire_lines' => [
                    'Nombrado «Pequeña espíritu de las flores» — rosa, verde y violeta.',
                    'Bordado de pétalos en mangas amplias y pibo; cintura degradada y falda estampada.',
                    'Romanticismo etéreo, no discurso de mercado.',
                ],
            ],
            'pt_BR' => [
                'original_title' => 'Declaração de originalidade',
                'original_body' => 'Este design tem proteção de originalidade. Cópia ou pirataria serão responsabilizadas.',
                'inspire_title' => 'Nascente do desenho',
                'inspire_lines' => [
                    'Nomeado «Pequeno espírito das flores» — rosa, verde e violeta.',
                    'Bordado de pétalas nas mangas largas e pibo; cintura degradê e saia estampada.',
                    'Romance etéreo, não discurso de mercado.',
                ],
            ],
            'id_ID' => [
                'original_title' => 'Pernyataan keaslian',
                'original_body' => 'Desain ini dilindungi sebagai karya asli. Penyalinan atau pembajakan akan ditindak.',
                'inspire_title' => 'Sumur desain',
                'inspire_lines' => [
                    'Bernama «Roh kecil bunga» — merah muda, hijau, dan ungu.',
                    'Sulaman kelopak di lengan lebar dan pibo; pinggang gradasi, rok cetak gradasi.',
                    'Romansa ethereal, bukan gaya pasar.',
                ],
            ],
            'hi_IN' => [
                'original_title' => 'मौलिकता वक्तव्य',
                'original_body' => 'यह डिज़ाइन मौलिकता-संरक्षित है। नकल या चोरी पर कार्रवाई होगी।',
                'inspire_title' => 'डिज़ाइन स्रोत',
                'inspire_lines' => [
                    '«छोटी फूल-देवी» — गुलाबी, हरा और बैंगनी।',
                    'चौड़ी आस्तीनों व पिबो पर गिरते फूलों की कढ़ाई; ग्रेडिएंट कमर व स्कर्ट।',
                    'हल्की रोमांस, बाज़ार का स्वर नहीं।',
                ],
            ],
            'ar_SA' => [
                'original_title' => 'بيان الأصالة',
                'original_body' => 'هذا التصميم محمي بوصفه أصلياً. سيتم ملاحقة النسخ أو القرصنة.',
                'inspire_title' => 'منبع التصميم',
                'inspire_lines' => [
                    'باسم «روح الزهر الصغيرة» — وردي وأخضر وبنفسجي.',
                    'تطريز بتلات على الأكمام الواسعة والبيبو؛ خصر متدرج وتنورة مطبوعة.',
                    'رومانسية شفافة، لا خطاب سوق.',
                ],
            ],
            'bn_BD' => [
                'original_title' => 'মৌলিকত্ব বিবৃতি',
                'original_body' => 'এই নকশা মৌলিকত্ব-সুরক্ষিত। নকল বা চুরির বিরুদ্ধে ব্যবস্থা নেওয়া হবে।',
                'inspire_title' => 'ডিজাইনের উৎস',
                'inspire_lines' => [
                    '«ছোট ফুল-দেবী» — গোলাপি, সবুজ ও বেগুনি।',
                    'প্রশস্ত হাতা ও পিবোতে পাপড়ি সূচিকর্ম; গ্রেডিয়েন্ট কোমর ও স্কার্ট।',
                    'হালকা রোমান্স, বাজারের স্বর নয়।',
                ],
            ],
            'ur_PK' => [
                'original_title' => 'اصالت کا بیان',
                'original_body' => 'یہ ڈیزائن اصالت سے محفوظ ہے۔ نقل یا چوری پر کارروائی ہوگی۔',
                'inspire_title' => 'ڈیزائن کا چشمہ',
                'inspire_lines' => [
                    '«چھوٹی پھول-دیوی» — گلابی، سبز اور جامنی۔',
                    'چوڑی آستینوں اور پیبو پر گرتے پھولوں کی کڑھائی؛ گریڈینٹ کمر اور اسکرٹ۔',
                    'ہلکی رومانس، بازاری لہجہ نہیں۔',
                ],
            ],
        ],
    ],
];

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/**
 * Prefer in-place object_key overwrite for board assets (same asset:// UUID).
 * Only inject HTML prose — NEVER inject raw /pub/media img (storefront strips those
 * and aggressive figure-strip / poem-aside wipe / pair→solo previously gutted magazines).
 */
$buildBlocks = static function (array $t, array $cleans) use ($h): string {
    $lines = '';
    foreach ((array)($t['inspire_lines'] ?? []) as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $lines .= '<p>' . $h($line) . '</p>';
    }
    $original = '<div class="weline-detail-prose" data-weline-detail-text="originality-statement"><h3>'
        . $h((string)$t['original_title']) . '</h3><p>' . $h((string)$t['original_body']) . '</p></div>';
    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse" data-weline-detail-text="design-inspire"><h3>'
        . $h((string)$t['inspire_title']) . '</h3>' . $lines . '</div>';
    $extra = '';
    foreach ($cleans as $c) {
        if (($c['role'] ?? '') === 'embroidery' && isset($t['detail_title'], $t['detail_body'])) {
            $extra .= '<div class="weline-detail-prose" data-weline-detail-text="embroidery-caption"><h3>'
                . $h((string)$t['detail_title']) . '</h3><p>' . $h((string)$t['detail_body']) . '</p></div>';
        }
    }

    return $original . $inspire . $extra;
};

/** Idempotent prose cleanup only — do not strip figure shells or convert pairs. */
$stripBoards = static function (string $html, array $needles): string {
    unset($needles); // boards kept; clean bytes overwrite object_key files instead
    $html = preg_replace(
        '#<div class="weline-detail-prose"[^>]*data-weline-detail-text="originality-statement"[^>]*>.*?</div>#is',
        '',
        $html
    ) ?? $html;
    $html = preg_replace(
        '#<div class="weline-detail-prose[^"]*"[^>]*data-weline-detail-text="design-inspire"[^>]*>.*?</div>#is',
        '',
        $html
    ) ?? $html;
    $html = preg_replace(
        '#<div class="weline-detail-prose[^"]*"[^>]*data-weline-detail-text="embroidery-caption"[^>]*>.*?</div>#is',
        '',
        $html
    ) ?? $html;
    // Remove legacy broken /pub/media injects from earlier remediate runs.
    $html = preg_replace(
        '#<div class="weline-detail-figure-stack[^"]*"[^>]*data-weline-detail-text="caption-board-clean"[^>]*>.*?</div>\s*</div>\s*</div>#is',
        '',
        $html
    ) ?? $html;

    return $html;
};

$insertAfterLead = static function (string $html, string $blocks): string {
    if (preg_match('#(<div class="weline-detail-prose weline-detail-prose--lead"[^>]*>.*?</div>)#is', $html, $m, PREG_OFFSET_CAPTURE)) {
        $end = $m[0][1] + strlen($m[0][0]);

        return substr($html, 0, $end) . $blocks . substr($html, $end);
    }
    if (preg_match('#(data-weds="xq"[^>]*>)#i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $end = $m[0][1] + strlen($m[0][0]);

        return substr($html, 0, $end) . $blocks . substr($html, $end);
    }

    return $blocks . $html;
};

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '3306'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$root = dirname(__DIR__, 5);
foreach ($jobs as $productId => $job) {
    $cleans = (array)($job['cleans'] ?? []);
    if ($cleans === []) {
        $cleans = [[
            'rel' => (string)$job['clean_rel'],
            'src' => (string)$job['clean_src'],
            'w' => (int)$job['clean_w'],
            'h' => (int)$job['clean_h'],
            'alt_zh' => (string)$job['alt_zh'],
            'role' => 'main',
        ]];
    }
    echo "product={$productId}\n";
    foreach ($cleans as $c) {
        $src = (string)($c['src'] ?? '');
        $rel = ltrim((string)($c['rel'] ?? ''), '/');
        $dst = $root . '/pub/media/' . $rel;
        echo "  clean={$rel}\n";
        if (!is_file($src)) {
            fwrite(STDERR, "missing clean src {$src}\n");
            exit(2);
        }
        if ($apply) {
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0775, true);
            }
            if (!copy($src, $dst)) {
                fwrite(STDERR, "copy fail {$dst}\n");
                exit(2);
            }
            @chown($dst, 'www');
            @chgrp($dst, 'www');
            echo "    media copied bytes=" . filesize($dst) . "\n";
        } else {
            echo "    dry-run would copy " . filesize($src) . " bytes\n";
        }
    }

    $packs = (array)$job['packs'];
    $localePlan = ['' => 'en_US'];
    foreach (array_keys($packs) as $code) {
        $localePlan[$code] = $code;
    }
    // Prefer website enabled codes when available.
    try {
        $codes = \Weline\Website\Model\WebsiteLanguage::getWebsiteLanguageCodes($websiteId);
        if (is_array($codes) && $codes !== []) {
            $localePlan = ['' => 'en_US'];
            foreach ($codes as $code) {
                $code = (string)$code;
                if ($code === '') {
                    continue;
                }
                $localePlan[$code] = isset($packs[$code]) ? $code : (isset($packs['en_US']) ? 'en_US' : array_key_first($packs));
            }
        }
    } catch (Throwable $e) {
        // keep pack keys
    }

    foreach ($localePlan as $locale => $packKey) {
        if (!isset($packs[$packKey])) {
            $packKey = 'en_US';
        }
        $st = $pdo->prepare(
            "SELECT value_text FROM w_product_ws_0_attribute_value
             WHERE entity_id=? AND attribute_code='description' AND locale=?
             ORDER BY CHAR_LENGTH(COALESCE(value_text,'')) DESC LIMIT 1"
        );
        $st->execute([(string)$productId, $locale]);
        $html = (string)$st->fetchColumn();
        if ($html === '' || !str_contains($html, 'data-weds="xq"')) {
            echo "  skip locale=" . ($locale === '' ? '(empty)' : $locale) . " (empty/no-weds)\n";
            continue;
        }
        $before = $html;
        $beforeAssets = substr_count($html, 'asset://');
        $html = $stripBoards($html, (array)$job['drop_needles']);
        $blocks = $buildBlocks((array)$packs[$packKey], $cleans);
        $html = $insertAfterLead($html, $blocks);
        $changed = $html !== $before;
        $afterAssets = substr_count($html, 'asset://');
        $boardKept = false;
        foreach ((array)($job['still_needles'] ?? $job['drop_needles']) as $n) {
            if (str_contains($html, $n)) {
                $boardKept = true;
            }
        }
        echo '  locale=' . ($locale === '' ? '(empty)' : $locale)
            . ' pack=' . $packKey
            . ' changed=' . ($changed ? 'yes' : 'no')
            . ' board_uuid_kept=' . ($boardKept ? 'yes(overwrite-file)' : 'no')
            . ' assets=' . $beforeAssets . '→' . $afterAssets
            . ' len=' . strlen($html) . "\n";
        if ($afterAssets < $beforeAssets) {
            fwrite(STDERR, "refusing write: asset:// count dropped ({$beforeAssets}→{$afterAssets})\n");
            exit(2);
        }
        if (!$apply || !$changed) {
            continue;
        }
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
        try {
            $locCode = $locale === '' ? 'en_US' : $locale;
            $pdo->prepare('UPDATE w_weline_product_local SET description=? WHERE product_id=? AND local_code=?')
                ->execute([$html, $productId, $locCode]);
            if ($locale === '') {
                $pdo->prepare('UPDATE w_weline_product_local SET description=? WHERE product_id=? AND local_code=?')
                    ->execute([$html, $productId, 'zh_Hans_CN']);
            }
        } catch (Throwable $e) {
            echo '  local sync soft-fail: ' . $e->getMessage() . "\n";
        }
    }
}

if ($apply) {
    try {
        ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
            ->invalidateForProducts(array_map('intval', array_keys($jobs)));
    } catch (Throwable $e) {
        echo 'cache invalidate soft-fail: ' . $e->getMessage() . "\n";
    }
    try {
        ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)
            ->clearForCatalogChange('caption_boards_originality_inspire');
    } catch (Throwable $e) {
        echo 'catalog cache soft-fail: ' . $e->getMessage() . "\n";
    }
}

echo $apply ? "DONE apply\n" : "DONE dry-run\n";
