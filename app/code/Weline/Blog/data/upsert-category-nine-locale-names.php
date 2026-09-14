<?php

declare(strict_types=1);

/**
 * Backfill website=0 blog category EAV `name` for nine storefront locales.
 * Does not touch zh_Hans_CN. Skips rows that already differ from English/Chinese leftovers.
 *
 * Usage: php app/code/Weline/Blog/data/upsert-category-nine-locale-names.php
 */

use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogCategoryAttributeService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;

/** @var list<string> */
const LOCALES = [
    'ar_SA',
    'bn_BD',
    'en_US',
    'es_ES',
    'fr_FR',
    'hi_IN',
    'id_ID',
    'pt_BR',
    'ur_PK',
];

/**
 * Explicit packs for hub / special labels (slug => locale => name).
 *
 * @var array<string, array<string, string>>
 */
const EXPLICIT = [
    'news' => [
        'en_US' => 'News Center',
        'ar_SA' => 'مركز الأخبار',
        'bn_BD' => 'সংবাদ কেন্দ্র',
        'es_ES' => 'Centro de noticias',
        'fr_FR' => "Centre d'actualités",
        'hi_IN' => 'समाचार केंद्र',
        'id_ID' => 'Pusat Berita',
        'pt_BR' => 'Central de notícias',
        'ur_PK' => 'خبریں مرکز',
    ],
    'hanfu-mens' => [
        'en_US' => 'Hanfu Menswear',
        'ar_SA' => 'أزياء الهانفو الرجالية',
        'bn_BD' => 'পুরুষদের হানফু',
        'es_ES' => 'Hanfu para hombres',
        'fr_FR' => 'Hanfu homme',
        'hi_IN' => 'पुरुष हानफू',
        'id_ID' => 'Hanfu Pria',
        'pt_BR' => 'Hanfu masculino',
        'ur_PK' => 'مردانہ ہانفو',
    ],
    'world-ethnic' => [
        'en_US' => 'World Ethnic Dress',
        'ar_SA' => 'الأزياء العرقية العالمية',
        'bn_BD' => 'বিশ্ব জাতিগতোশাক',
        'es_ES' => 'Trajes étnicos del mundo',
        'fr_FR' => 'Costumes ethniques du monde',
        'hi_IN' => 'विश्व जातीय परिधान',
        'id_ID' => 'Busana Etnik Dunia',
        'pt_BR' => 'Trajes étnicos do mundo',
        'ur_PK' => 'عالمی نسلی لباس',
    ],
    'china-ethnic-dress' => [
        'en_US' => 'Chinese Ethnic Dress',
        'ar_SA' => 'أزياء الأقليات الصينية',
        'bn_BD' => 'চীনের জাতিগোষ্ঠীর পোশাক',
        'es_ES' => 'Trajes étnicos chinos',
        'fr_FR' => 'Costumes ethniques chinois',
        'hi_IN' => 'चीनी जातीय परिधान',
        'id_ID' => 'Busana Etnik Tiongkok',
        'pt_BR' => 'Trajes étnicos chineses',
        'ur_PK' => 'چینی نسلی لباس',
    ],
];

/**
 * Ethnonym stem (from English "X Dress") => optional per-locale stem override.
 * Empty locale uses Latin stem.
 *
 * @var array<string, array<string, string>>
 */
const ETHNIC_STEMS = [
    'Han' => [],
    'Mongol' => ['ar_SA' => 'المنغول', 'hi_IN' => 'मंगोल', 'bn_BD' => 'মঙ্গোল', 'ur_PK' => 'منگول'],
    'Hui' => [],
    'Tibetan' => ['ar_SA' => 'التبت', 'hi_IN' => 'तिब्बती', 'bn_BD' => 'তিব্বতি', 'ur_PK' => 'تبتی', 'fr_FR' => 'tibétain', 'es_ES' => 'tibetano', 'pt_BR' => 'tibetano', 'id_ID' => 'Tibet'],
    'Uyghur' => ['ar_SA' => 'الأويغور', 'ur_PK' => 'ایغور'],
    'Miao' => [],
    'Yi' => [],
    'Zhuang' => [],
    'Buyei' => [],
    'Korean (Chaoxian)' => [
        'ar_SA' => 'الكوري (تشاوشيان)',
        'hi_IN' => 'कोरियाई (चाओशियान)',
        'bn_BD' => 'কোরিয়ান (চাওশিয়ান)',
        'ur_PK' => 'کوریائی (چاؤشیان)',
        'es_ES' => 'coreano (Chaoxian)',
        'fr_FR' => 'coréen (Chaoxian)',
        'pt_BR' => 'coreano (Chaoxian)',
        'id_ID' => 'Korea (Chaoxian)',
    ],
    'Manchu' => ['ar_SA' => 'المانشو', 'hi_IN' => 'मंचू', 'ur_PK' => 'مانچو'],
    'Dong' => [],
    'Yao' => [],
    'Bai' => [],
    'Tujia' => [],
    'Hani' => [],
    'Kazakh' => ['ar_SA' => 'الكازاخ', 'hi_IN' => 'कज़ाख', 'ur_PK' => 'قازق', 'es_ES' => 'kazajo', 'fr_FR' => 'kazakh', 'pt_BR' => 'cazaque'],
    'Dai' => [],
    'Li' => [],
    'Lisu' => [],
    'Wa' => [],
    'She' => [],
    'Gaoshan' => [],
    'Lahu' => [],
    'Shui' => [],
    'Dongxiang' => [],
    'Naxi' => [],
    'Jingpo' => [],
    'Kirgiz' => ['ar_SA' => 'القيرغيز', 'ur_PK' => 'کرغیز', 'es_ES' => 'kirguís', 'fr_FR' => 'kirghiz', 'pt_BR' => 'quirguiz'],
    'Tu' => [],
    'Daur' => [],
    'Mulao' => [],
    'Qiang' => [],
    'Blang' => [],
    'Salar' => [],
    'Maonan' => [],
    'Gelao' => [],
    'Xibe' => [],
    'Achang' => [],
    'Pumi' => [],
    'Tajik' => ['ar_SA' => 'الطاجيك', 'ur_PK' => 'تاجک', 'es_ES' => 'tayiko', 'fr_FR' => 'tadjik', 'pt_BR' => 'tadjique'],
    'Nu' => [],
    'Uzbek' => ['ar_SA' => 'الأوزبك', 'ur_PK' => 'ازبک', 'es_ES' => 'uzbeko', 'fr_FR' => 'ouzbek', 'pt_BR' => 'uzbeque'],
    'Russian' => ['ar_SA' => 'الروسي', 'hi_IN' => 'रूसी', 'bn_BD' => 'রাশিয়ান', 'ur_PK' => 'روسی', 'es_ES' => 'ruso', 'fr_FR' => 'russe', 'pt_BR' => 'russo', 'id_ID' => 'Rusia'],
    'Ewenki' => [],
    "De’ang" => ['en_US' => "De’ang"],
    'Bonan' => [],
    'Yugur' => [],
    'Gin (Kinh)' => [
        'ar_SA' => 'الجين (كينه)',
        'hi_IN' => 'जिन (किन्ह)',
        'bn_BD' => 'জিন (কিনহ)',
        'ur_PK' => 'جن (کنہ)',
        'es_ES' => 'Gin (Kinh)',
        'fr_FR' => 'Gin (Kinh)',
        'pt_BR' => 'Gin (Kinh)',
        'id_ID' => 'Gin (Kinh)',
    ],
    'Tatar' => ['ar_SA' => 'التتار', 'ur_PK' => 'تاتار'],
    'Derung' => [],
    'Oroqen' => [],
    'Hezhen' => [],
    'Monba' => [],
    'Lhoba' => [],
    'Jino' => [],
];

/**
 * @return array<string, string> locale => template with {stem}
 */
function dressTemplates(): array
{
    return [
        'en_US' => '{stem} Dress',
        'ar_SA' => 'أزياء {stem}',
        'bn_BD' => '{stem} পোশাক',
        'es_ES' => 'Traje {stem}',
        'fr_FR' => 'Costume {stem}',
        'hi_IN' => '{stem} परिधान',
        'id_ID' => 'Busana {stem}',
        'pt_BR' => 'Traje {stem}',
        'ur_PK' => '{stem} لباس',
    ];
}

function isHan(string $value): bool
{
    return preg_match('/\p{Han}/u', $value) === 1;
}

/**
 * @param array<string, array{slug:string,zh:string,en:string}> $catalog
 * @return array<int, array<string, string>>
 */
function buildWrites(array $catalog): array
{
    $templates = dressTemplates();
    $bySlug = [];
    foreach ($catalog as $id => $row) {
        $bySlug[(string)$row['slug']] = [(int)$id, $row];
    }

    $writes = [];

    foreach (EXPLICIT as $slug => $pack) {
        if (!isset($bySlug[$slug])) {
            continue;
        }
        [$id] = $bySlug[$slug];
        foreach (LOCALES as $locale) {
            $label = trim((string)($pack[$locale] ?? ''));
            if ($label !== '') {
                $writes[$id][$locale] = $label;
            }
        }
    }

    foreach ($catalog as $id => $row) {
        $en = trim((string)$row['en']);
        if (!preg_match('/^(.+?) Dress$/u', $en, $m)) {
            continue;
        }
        $stem = trim($m[1]);
        if ($stem === 'World Ethnic' || $stem === 'Chinese Ethnic') {
            continue; // handled in EXPLICIT
        }
        $overrides = ETHNIC_STEMS[$stem] ?? [];
        foreach (LOCALES as $locale) {
            if ($locale === 'en_US') {
                $writes[(int)$id][$locale] = $en;
                continue;
            }
            $localStem = trim((string)($overrides[$locale] ?? $stem));
            if ($localStem === '') {
                $localStem = $stem;
            }
            $tpl = $templates[$locale] ?? '{stem} Dress';
            $writes[(int)$id][$locale] = str_replace('{stem}', $localStem, $tpl);
        }
    }

    return $writes;
}

$admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
$attrs = ObjectManager::getInstance(BlogCategoryAttributeService::class);

$flat = [];
$walk = static function (array $nodes) use (&$walk, &$flat): void {
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $flat[] = $node;
        $kids = is_array($node['nodes'] ?? null) ? $node['nodes'] : [];
        if ($kids !== []) {
            $walk($kids);
        }
    }
};
$walk($admin->tree(WEBSITE_ID, 'zh_Hans_CN'));

$ids = [];
$catalog = [];
foreach ($flat as $node) {
    $id = (int)($node['category_id'] ?? 0);
    $slug = trim((string)($node['slug'] ?? $node['code'] ?? ''));
    if ($id <= 0 || $slug === '') {
        continue;
    }
    $ids[] = $id;
    $catalog[$id] = [
        'slug' => $slug,
        'zh' => trim((string)($node['name'] ?? '')),
        'en' => '',
    ];
}
$enMap = $attrs->readNameMap(WEBSITE_ID, $ids, 'en_US');
foreach ($catalog as $id => &$row) {
    $row['en'] = trim((string)($enMap[$id] ?? $row['zh']));
}
unset($row);

$planned = buildWrites($catalog);
$written = 0;
$skipped = 0;

foreach ($planned as $categoryId => $localeMap) {
    foreach ($localeMap as $locale => $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $current = trim($attrs->readName(WEBSITE_ID, $categoryId, $locale));
        $en = trim((string)($catalog[$categoryId]['en'] ?? ''));
        $needs = $current === ''
            || isHan($current)
            || ($locale !== 'en_US' && $en !== '' && $current === $en);
        if (!$needs && $current === $name) {
            ++$skipped;
            continue;
        }
        if (!$needs && $current !== $name && !isHan($current) && !($locale !== 'en_US' && $current === $en)) {
            // Already has a distinct localized value — keep unless Chinese/English leftover.
            ++$skipped;
            continue;
        }
        $attrs->writeName(WEBSITE_ID, $categoryId, $name, $locale);
        ++$written;
        echo "+ #{$categoryId} {$locale} => {$name}\n";
    }
}

echo "done written={$written} skipped={$skipped} planned_categories=" . count($planned) . "\n";
