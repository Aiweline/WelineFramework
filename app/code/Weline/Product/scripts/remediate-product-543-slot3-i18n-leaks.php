<?php

declare(strict_types=1);

/**
 * 产品优化槽③：#543 长乐公主 — 非英 locale 详情残留英文专名「Princess Changle」真译替换
 *
 * php app/code/Weline/Product/scripts/remediate-product-543-slot3-i18n-leaks.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-product-543-slot3-i18n-leaks.php --apply
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
$productId = 543;

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
/** @var Website $ws */
$ws = $om->get(Website::class);
$ws->load($websiteId);
$enabled = array_values(array_map('strval', (array)$ws->getLanguageCodes()));
$localePlan = ['' => (string)($ws->getDefaultLanguage() ?: 'en_US')];
foreach ($enabled as $code) {
    $localePlan[$code] = $code;
}

echo 'ENABLED_LOCALES=' . json_encode(array_keys($localePlan), JSON_UNESCAPED_UNICODE) . PHP_EOL;

/**
 * 详情可见专名：各启用语本地化「Princess Changle」。
 * （专名 Changle 可保留拉丁转写；称号须目标语）
 *
 * @var array<string, string>
 */
$princessLocal = [
    'de_DE' => 'Prinzessin Changle',
    'nl_NL' => 'Prinses Changle',
    'da_DK' => 'Prinsesse Changle',
    'sv_SE' => 'Prinsessa Changle',
    'nb_NO' => 'Prinsesse Changle',
    'is_IS' => 'Prinsessa Changle',
    'fr_CA' => 'Princesse Changle',
    'fr_FR' => 'Princesse Changle',
    'es_MX' => 'Princesa Changle',
    'es_ES' => 'Princesa Changle',
    'pt_PT' => 'Princesa Changle',
    'pt_BR' => 'Princesa Changle',
    'it_IT' => 'Principessa Changle',
    'ca_ES' => 'Princesa Changle',
    'ro_RO' => 'Prințesa Changle',
    'pl_PL' => 'Księżniczka Changle',
    'cs_CZ' => 'Princezna Changle',
    'sk_SK' => 'Princezná Changle',
    'hu_HU' => 'Changle hercegnő',
    'hr_HR' => 'Princeza Changle',
    'sl_SI' => 'Princesa Changle',
    'ru_RU' => 'принцесса Чанлэ',
    'uk_UA' => 'принцеса Чанле',
    'bg_BG' => 'принцеса Чанлъ',
    'el_GR' => 'πριγκίπισσα Τσανγκλέ',
    'lt_LT' => 'Princesė Changle',
    'lv_LV' => 'Princese Changle',
    'fi_FI' => 'Prinsessa Changle',
    'et_EE' => 'Printsess Changle',
    'tr_TR' => 'Prenses Changle',
    'ga_IE' => 'Banphrionsa Changle',
    'mt_MT' => 'Prinċipessa Changle',
    'id_ID' => 'Putri Changle',
    'ar_SA' => 'الأميرة تشانغله',
    'bn_BD' => 'রাজকুমারী চাংলে',
    'hi_IN' => 'राजकुमारी चांगले',
    'ur_PK' => 'شهزادی چانگ لے',
    'zh_Hans_CN' => '长乐公主',
];

$enLocales = ['', 'en_US', 'en_GB'];
$needle = 'Princess Changle';

$st = $pdo->prepare(
    "SELECT locale, COALESCE(value_text, value_string, '') AS html
     FROM w_product_ws_0_attribute_value
     WHERE entity_id = ? AND attribute_code = 'description'"
);
$st->execute([(string)$productId]);
$existing = [];
foreach ($st as $row) {
    $existing[(string)$row['locale']] = (string)$row['html'];
}

$writes = [];
$skipped = [];
foreach ($localePlan as $locale => $target) {
    if (in_array($locale, $enLocales, true) || in_array($target, ['en_US', 'en_GB'], true)) {
        $skipped[] = ($locale === '' ? 'BASE' : $locale) . ':en_keep';
        continue;
    }
    $html = $existing[$locale] ?? '';
    if ($html === '') {
        $skipped[] = ($locale === '' ? 'BASE' : $locale) . ':empty';
        continue;
    }
    $count = substr_count($html, $needle);
    if ($count === 0) {
        $skipped[] = $locale . ':already_clean';
        continue;
    }
    $replacement = $princessLocal[$target] ?? $princessLocal[$locale] ?? null;
    if ($replacement === null || $replacement === $needle) {
        fwrite(STDERR, "missing local princess form for {$locale}\n");
        exit(2);
    }
    $newHtml = str_replace($needle, $replacement, $html);
    $left = substr_count($newHtml, $needle);
    if ($left !== 0) {
        fwrite(STDERR, "replace incomplete for {$locale} left={$left}\n");
        exit(2);
    }
    $writes[] = [
        'locale' => $locale,
        'html' => $newHtml,
        'replaced' => $count,
        'as' => $replacement,
    ];
}

echo 'plan writes=' . count($writes) . ' apply=' . ($apply ? 'yes' : 'dry-run') . PHP_EOL;
foreach ($writes as $w) {
    echo sprintf(
        "  %s replace=%d → %s\n",
        $w['locale'],
        $w['replaced'],
        $w['as']
    );
}
echo 'skipped=' . json_encode($skipped, JSON_UNESCAPED_UNICODE) . PHP_EOL;

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
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
    echo 'wrote description ' . $locale . ' len=' . strlen($html) . ' replaced=' . $w['replaced'] . PHP_EOL;
}

try {
    $om->get(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'product_543_slot3_i18n_leaks',
        ['product_ids' => [$productId]],
    );
    $om->get(ProductStorefrontCacheInvalidator::class)
        ->clearForCatalogChange('product_543_slot3_i18n_leaks');
    echo "cache_cleared\n";
} catch (Throwable $e) {
    echo 'cache invalidate soft-fail: ' . $e->getMessage() . PHP_EOL;
}

echo "APPLY_DONE\n";
