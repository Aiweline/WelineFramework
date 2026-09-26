<?php

declare(strict_types=1);

/**
 * 产品优化槽③复审：#543 — option/swatch label 残留「Changle Princess」/「Princess Changle」
 *
 * php app/code/Weline/Product/scripts/remediate-product-543-slot3-option-label-leaks.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-product-543-slot3-option-label-leaks.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
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
echo 'ENABLED_LOCALES=' . json_encode($enabled, JSON_UNESCAPED_UNICODE) . PHP_EOL;

/**
 * 与 description 槽③已用称谓一致。
 *
 * @var array<string, string>
 */
$princessLocal = [
    'zh_Hans_CN' => '长乐公主',
    'en_US' => 'Princess Changle',
    'en_GB' => 'Princess Changle',
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
];

/** 本品相关 option_id（style_type / 披帛） */
$optionIds = [309, 1679, 1682, 1684];
$idList = implode(',', $optionIds);

$st = $pdo->query(
    "SELECT id, local_code, name, value
     FROM w_eav_attribute_option_local_description
     WHERE id IN ({$idList})
     ORDER BY id, local_code"
);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$writes = [];
$skipped = [];
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $locale = (string)$row['local_code'];
    $name = (string)($row['name'] ?? '');
    $value = (string)($row['value'] ?? '');
    $blob = $name . "\n" . $value;
    $hasCp = str_contains($blob, 'Changle Princess');
    $hasPc = str_contains($blob, 'Princess Changle');
    if (!$hasCp && !$hasPc) {
        $skipped[] = "{$locale}#{$id}:clean";
        continue;
    }
    // EN：统一词序为 description 用的 Princess Changle（Changle Princess → Princess Changle）
    // 非 EN：换成目标语称谓
    $replacement = $princessLocal[$locale] ?? null;
    if ($replacement === null) {
        fwrite(STDERR, "missing princess form for locale={$locale}\n");
        exit(2);
    }
    $newName = $name;
    $newValue = $value;
    if ($hasCp) {
        $newName = str_replace('Changle Princess', $replacement, $newName);
        $newValue = str_replace('Changle Princess', $replacement, $newValue);
    }
    if ($hasPc && !in_array($locale, ['en_US', 'en_GB'], true)) {
        // 非英若仍有 Princess Changle，一并替换
        $newName = str_replace('Princess Changle', $replacement, $newName);
        $newValue = str_replace('Princess Changle', $replacement, $newValue);
    } elseif ($hasCp && in_array($locale, ['en_US', 'en_GB'], true)) {
        // already replaced Changle Princess → Princess Changle above
    }
    // EN 行若同时有 【Princess Changle】 保持不动（replacement 即 Princess Changle）
    if ($newName === $name && $newValue === $value) {
        $skipped[] = "{$locale}#{$id}:noop";
        continue;
    }
    // 落盘后不得再有 Changle Princess；非英不得再有 Princess Changle
    $check = $newName . "\n" . $newValue;
    if (str_contains($check, 'Changle Princess')) {
        fwrite(STDERR, "FAIL still Changle Princess after replace {$locale}#{$id}\n");
        exit(2);
    }
    if (!in_array($locale, ['en_US', 'en_GB'], true) && str_contains($check, 'Princess Changle')) {
        fwrite(STDERR, "FAIL still Princess Changle on non-EN {$locale}#{$id}\n");
        exit(2);
    }
    $writes[] = [
        'id' => $id,
        'local_code' => $locale,
        'name' => $newName,
        'value' => $newValue,
        'from_name' => $name,
        'from_value' => $value,
        'as' => $replacement,
    ];
}

// 全表再扫：启用语内其它 option 若仍含两串英文专名（非 EN）也报出
$extra = $pdo->query(
    "SELECT id, local_code, name, value
     FROM w_eav_attribute_option_local_description
     WHERE (value LIKE '%Changle Princess%' OR name LIKE '%Changle Princess%'
        OR value LIKE '%Princess Changle%' OR name LIKE '%Princess Changle%')
       AND local_code NOT IN ('en_US','en_GB')"
)->fetchAll(PDO::FETCH_ASSOC);
$extraPending = [];
foreach ($extra as $row) {
    $key = (int)$row['id'] . '|' . $row['local_code'];
    $planned = false;
    foreach ($writes as $w) {
        if ($w['id'] === (int)$row['id'] && $w['local_code'] === $row['local_code']) {
            $planned = true;
            break;
        }
    }
    if (!$planned) {
        $extraPending[] = $row;
    }
}
if ($extraPending) {
    echo 'WARN extra non-EN leaks not in product option set: ' . count($extraPending) . PHP_EOL;
    foreach ($extraPending as $r) {
        echo sprintf("  id=%d %s value=%s\n", $r['id'], $r['local_code'], mb_substr((string)$r['value'], 0, 80));
    }
}

echo 'plan writes=' . count($writes) . ' apply=' . ($apply ? 'yes' : 'dry-run') . PHP_EOL;
foreach ($writes as $w) {
    echo sprintf(
        "  %s#%d → %s | was=[%s] now=[%s]\n",
        $w['local_code'],
        $w['id'],
        $w['as'],
        mb_substr($w['from_value'] !== '' ? $w['from_value'] : $w['from_name'], 0, 60),
        mb_substr($w['value'] !== '' ? $w['value'] : $w['name'], 0, 60)
    );
}

if (!$apply) {
    echo "dry-run only\n";
    exit(0);
}

$upd = $pdo->prepare(
    'UPDATE w_eav_attribute_option_local_description
     SET name = :name, value = :value, update_time = NOW()
     WHERE id = :id AND local_code = :local_code'
);
foreach ($writes as $w) {
    $upd->execute([
        ':name' => $w['name'],
        ':value' => $w['value'],
        ':id' => $w['id'],
        ':local_code' => $w['local_code'],
    ]);
    echo "WROTE {$w['local_code']}#{$w['id']}\n";
}

// 校验：非 EN 启用语 option 表对本品 option_id 不得再有两串
$left = $pdo->query(
    "SELECT id, local_code, value FROM w_eav_attribute_option_local_description
     WHERE id IN ({$idList})
       AND (
         value LIKE '%Changle Princess%' OR name LIKE '%Changle Princess%'
         OR (
           local_code NOT IN ('en_US','en_GB')
           AND (value LIKE '%Princess Changle%' OR name LIKE '%Princess Changle%')
         )
       )"
)->fetchAll(PDO::FETCH_ASSOC);
if ($left) {
    fwrite(STDERR, 'POSTCHECK FAIL left=' . json_encode($left, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(2);
}
echo "POSTCHECK_OK\n";

try {
    $om->get(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'product_543_slot3_option_label_leaks',
        ['product_ids' => [$productId]],
    );
    $om->get(ProductStorefrontCacheInvalidator::class)
        ->clearForCatalogChange('product_543_slot3_option_label_leaks');
    echo "cache_cleared\n";
} catch (Throwable $e) {
    echo 'cache soft-fail: ' . $e->getMessage() . PHP_EOL;
}

echo "APPLY_DONE product_id={$productId} writes=" . count($writes) . PHP_EOL;
