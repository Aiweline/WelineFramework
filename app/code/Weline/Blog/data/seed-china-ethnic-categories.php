<?php

declare(strict_types=1);

/**
 * Incremental seed: China 56 ethnic dress categories + Hanfu menswear.
 * Does NOT purge existing blog data. Idempotent (skip existing codes).
 *
 * Blog space supports parent_id depth ≤ 2. china-ethnic-dress is the hub;
 * ethnic-cn-* children are remounted under it after upsert.
 *
 * Usage: php app/code/Weline/Blog/data/seed-china-ethnic-categories.php
 */

use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogCategoryAttributeService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const MEDIA_BASE = '/media/blog/hanfu/categories';
const ICON_DIR = __DIR__ . '/../../../../../pub/media/blog/hanfu/categories/icons';
const BANNER_DIR = __DIR__ . '/../../../../../pub/media/blog/hanfu/categories/banners';

/** @var list<string> */
const LOCALES = [
    'zh_Hans_CN',
    'en_US',
    'hi_IN',
    'es_ES',
    'ar_SA',
    'fr_FR',
    'bn_BD',
    'pt_BR',
    'id_ID',
    'ur_PK',
];

/**
 * @return array<string,int> code => category_id
 */
function existingCategoryMap(): array
{
    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $map = [];
    foreach ($admin->tree(WEBSITE_ID, 'zh_Hans_CN') as $node) {
        $code = trim((string)($node['code'] ?? ''));
        $id = (int)($node['category_id'] ?? 0);
        if ($code !== '' && $id > 0) {
            $map[$code] = $id;
        }
    }

    return $map;
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create ' . $dir);
    }
}

function colorPair(string $code): array
{
    $hash = crc32($code);
    $hues = [
        ['#FFE8F0', '#A83A6A'],
        ['#FFECEC', '#A84040'],
        ['#FFF4E5', '#B86B1A'],
        ['#EAF6FF', '#2F6FAE'],
        ['#EEF8EE', '#2F7A45'],
        ['#F3EEFF', '#6B4CA0'],
        ['#FFF0E8', '#C45C26'],
        ['#E8F7F5', '#1F7A6C'],
    ];

    return $hues[$hash % count($hues)];
}

function writeIconSvg(string $code, string $label): void
{
    ensureDir(ICON_DIR);
    $path = ICON_DIR . '/' . $code . '.svg';
    if (is_file($path)) {
        return;
    }
    [$bg, $fg] = colorPair($code);
    $labelEsc = htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80" role="img" aria-label="{$labelEsc}">
  <rect width="80" height="80" rx="16" fill="{$bg}"/>
  <rect x="4" y="4" width="72" height="72" rx="14" fill="{$fg}"/>
  <circle cx="40" cy="28" r="10" fill="#fff" opacity=".95"/>
  <rect x="22" y="42" width="36" height="22" rx="8" fill="#fff" opacity=".9"/>
  <path d="M28 52h24M28 58h16" stroke="{$fg}" stroke-width="2" stroke-linecap="round"/>
</svg>
SVG;
    if (file_put_contents($path, $svg) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

function writeBannerSvg(string $code, string $label): void
{
    ensureDir(BANNER_DIR);
    $path = BANNER_DIR . '/' . $code . '.svg';
    if (is_file($path)) {
        return;
    }
    [$bg, $fg] = colorPair($code);
    $labelEsc = htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="960" height="240" viewBox="0 0 960 240" role="img" aria-label="{$labelEsc}">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$fg}"/>
      <stop offset="100%" stop-color="{$bg}"/>
    </linearGradient>
  </defs>
  <rect width="960" height="240" fill="url(#g)"/>
  <circle cx="120" cy="120" r="54" fill="#fff" opacity=".18"/>
  <rect x="220" y="78" width="520" height="84" rx="18" fill="#fff" opacity=".2"/>
</svg>
SVG;
    if (file_put_contents($path, $svg) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

/**
 * @param array<string,array{name:string,summary:string,description:string}> $i18n
 */
function upsertCategory(string $code, int $sort, array $i18n, array &$existing): void
{
    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $attributes = ObjectManager::getInstance(BlogCategoryAttributeService::class);
    $zh = $i18n['zh_Hans_CN'];
    writeIconSvg($code, (string)$zh['name']);
    writeBannerSvg($code, (string)$zh['name']);

    if (isset($existing[$code])) {
        echo "= skip category {$code} #{$existing[$code]}\n";

        return;
    }

    $created = $admin->save(
        WEBSITE_ID,
        0,
        (string)$zh['name'],
        $code,
        'zh_Hans_CN',
        $sort,
        MEDIA_BASE . '/icons/' . $code . '.svg',
        MEDIA_BASE . '/banners/' . $code . '.svg',
        (string)$zh['summary'],
        (string)$zh['description'],
    );
    $categoryId = (int)($created['category_id'] ?? 0);
    if ($categoryId <= 0) {
        throw new RuntimeException('failed create ' . $code);
    }
    foreach (LOCALES as $locale) {
        if ($locale === 'zh_Hans_CN') {
            continue;
        }
        $pack = $i18n[$locale] ?? null;
        if (!is_array($pack)) {
            continue;
        }
        $name = trim((string)($pack['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $attributes->writeName(WEBSITE_ID, $categoryId, $name, $locale);
        $attributes->writeSummary(WEBSITE_ID, $categoryId, (string)($pack['summary'] ?? ''), $locale);
        $attributes->writeDescription(WEBSITE_ID, $categoryId, (string)($pack['description'] ?? ''), $locale);
    }
    $existing[$code] = $categoryId;
    echo "+ #{$categoryId} {$code} {$zh['name']}\n";
}

/**
 * @return array<string,array{name:string,summary:string,description:string}>
 */
function packI18n(string $zhName, string $zhSummary, string $zhDesc, string $enName, string $enSummary, string $enDesc): array
{
    $enPack = ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc];

    return [
        'zh_Hans_CN' => ['name' => $zhName, 'summary' => $zhSummary, 'description' => $zhDesc],
        'en_US' => $enPack,
        'hi_IN' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        'es_ES' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        'ar_SA' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        'fr_FR' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        'bn_BD' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        'pt_BR' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        'id_ID' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        'ur_PK' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
    ];
}

$groups = require __DIR__ . '/china-ethnic-groups.php';
if (!is_array($groups) || count($groups) !== 56) {
    throw new RuntimeException('china-ethnic-groups.php must return exactly 56 groups, got ' . (is_array($groups) ? count($groups) : 0));
}

$existing = existingCategoryMap();
echo "=== incremental china ethnic + mens categories ===\n";

upsertCategory(
    'hanfu-mens',
    115,
    packI18n(
        '汉服男装',
        '圆领袍、直裾、道袍等男装形制入门。',
        '面向男装买家梳理圆领袍、直裾、道袍等主流形制与场合建议，并连接工厂直销成衣实拍。',
        'Hanfu Menswear',
        'Yuanling, zhiju and men’s robe silhouettes.',
        'A men’s Hanfu hub covering yuanling, zhiju and related robes with occasion tips linked to factory-direct photos.',
    ),
    $existing,
);

upsertCategory(
    'china-ethnic-dress',
    650,
    packI18n(
        '中国民族服饰',
        '56 个民族传统服装科普枢纽。',
        '按民族建立服饰子分类，介绍形制、面料、场合与纹样，并与汉服文化对照，扩展主题权威。',
        'Chinese Ethnic Dress',
        'Hub for traditional dress of China’s 56 ethnic groups.',
        'Per-ethnicity dress guides covering silhouette, fabric, occasion and motifs, with thoughtful Hanfu comparisons.',
    ),
    $existing,
);

$sort = 651;
foreach ($groups as $g) {
    $code = 'ethnic-cn-' . $g['code'];
    $zhName = $g['zh'] . '服饰';
    $enName = $g['en'] . ' Dress';
    upsertCategory(
        $code,
        $sort,
        packI18n(
            $zhName,
            $g['region'] . ' · ' . $g['silhouette'],
            '介绍' . $g['zh'] . '传统服装的形制、面料工艺、穿着场合与纹样特点，并说明与汉服形制对照时的文化边界。',
            $enName,
            $g['region_en'] . ' · ' . $g['silhouette_en'],
            'Guide to ' . $g['en'] . ' traditional dress: silhouette, craft, occasions and motifs, with clear cultural boundaries versus Hanfu.',
        ),
        $existing,
    );
    ++$sort;
}

remountEthnicChildren($existing);

echo "done. categories_total=" . count(existingCategoryMap()) . "\n";

/**
 * @param array<string,int> $existing
 */
function remountEthnicChildren(array $existing): void
{
    $hubId = (int)($existing['china-ethnic-dress'] ?? 0);
    if ($hubId <= 0) {
        $existing = existingCategoryMap();
        $hubId = (int)($existing['china-ethnic-dress'] ?? 0);
    }
    if ($hubId <= 0) {
        echo "! skip remount: china-ethnic-dress missing\n";

        return;
    }

    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $position = 1;
    foreach ($existing as $code => $categoryId) {
        if (!is_string($code) || !str_starts_with($code, 'ethnic-cn-')) {
            continue;
        }
        $categoryId = (int)$categoryId;
        if ($categoryId <= 0) {
            continue;
        }
        $admin->reorder(WEBSITE_ID, $categoryId, $hubId, 2, $position);
        echo "~ remount #{$categoryId} {$code} -> china-ethnic-dress #{$hubId}\n";
        ++$position;
    }
}
