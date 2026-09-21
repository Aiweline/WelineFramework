<?php

declare(strict_types=1);

/**
 * Audit Product EAV Attribute/Option LocalDescription gaps for default-website locales.
 *
 * php app/code/Weline/Product/scripts/audit-eav-local-gaps.php
 * php app/code/Weline/Product/scripts/audit-eav-local-gaps.php --sku=HUAZHAOJI-6DDC5DCC-TCB95-S7CA5
 * php app/code/Weline/Product/scripts/audit-eav-local-gaps.php --limit=40 --json-out=/tmp/eav-gaps.json
 */

$skuFilter = '';
$limit = 40;
$jsonOut = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sku=')) {
        $skuFilter = trim(substr($arg, 6));
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
    if (str_starts_with($arg, '--json-out=')) {
        $jsonOut = trim(substr($arg, 11));
    }
}

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'];
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'], $db['hostport'], $db['database']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$locales = $pdo->query(
    'SELECT language_code FROM w_weline_websites_website_language WHERE website_id=0 ORDER BY language_code'
)->fetchAll(PDO::FETCH_COLUMN);
$locales = array_values(array_unique(array_map('strval', $locales)));
if (!in_array('zh_Hans_CN', $locales, true)) {
    $locales[] = 'zh_Hans_CN';
}
if (!in_array('en_US', $locales, true)) {
    $locales[] = 'en_US';
}
sort($locales);

echo 'DEFAULT_LOCALES=' . json_encode($locales, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'LOCALE_COUNT=' . count($locales) . PHP_EOL;

$entityId = 4; // product

$attrs = $pdo->prepare(
    'SELECT attribute_id, code, name FROM w_eav_attribute WHERE eav_entity_id = ? ORDER BY attribute_id'
);
$attrs->execute([$entityId]);
$attrById = [];
foreach ($attrs->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $id = (int)$row['attribute_id'];
    $attrById[$id] = [
        'id' => $id,
        'code' => (string)$row['code'],
        'name' => trim((string)$row['name']),
    ];
}

$attrLocalMap = [];
$st = $pdo->query('SELECT id, local_code, name FROM w_eav_attribute_local_description');
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$row['id'];
    $code = trim((string)$row['local_code']);
    if ($id <= 0 || $code === '') {
        continue;
    }
    $attrLocalMap[$id][$code] = trim((string)$row['name']);
}

$attrGaps = [];
foreach ($attrById as $id => $meta) {
    $source = $meta['name'];
    foreach ($locales as $locale) {
        $stored = trim((string)($attrLocalMap[$id][$locale] ?? ''));
        $reason = '';
        if ($stored === '') {
            $reason = 'missing';
        } elseif ($locale !== 'zh_Hans_CN' && $source !== '' && $stored === $source && preg_match('/\p{Han}/u', $source) === 1) {
            $reason = 'source_copy';
        } elseif ($locale !== 'zh_Hans_CN' && preg_match('/\p{Han}/u', $stored) === 1) {
            $reason = 'han_leak';
        }
        if ($reason !== '') {
            $attrGaps[] = [
                'id' => $id,
                'code' => $meta['code'],
                'source' => $source,
                'locale' => $locale,
                'stored' => $stored,
                'reason' => $reason,
            ];
        }
    }
}

$opts = $pdo->prepare(
    'SELECT option_id, attribute_id, code, value, scope_instance_id
     FROM w_eav_attribute_option
     WHERE eav_entity_id = ?
     ORDER BY option_id'
);
$opts->execute([$entityId]);
$optById = [];
while ($row = $opts->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$row['option_id'];
    $optById[$id] = [
        'id' => $id,
        'attribute_id' => (int)$row['attribute_id'],
        'code' => trim((string)$row['code']),
        'value' => trim((string)$row['value']),
        'scope_instance_id' => (int)$row['scope_instance_id'],
    ];
}

$optLocalMap = [];
$st = $pdo->query('SELECT id, local_code, value FROM w_eav_attribute_option_local_description');
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$row['id'];
    $code = trim((string)$row['local_code']);
    if ($id <= 0 || $code === '') {
        continue;
    }
    $optLocalMap[$id][$code] = trim((string)$row['value']);
}

$optGaps = [];
foreach ($optById as $id => $meta) {
    $source = $meta['value'];
    if ($source === '') {
        continue;
    }
    foreach ($locales as $locale) {
        $stored = trim((string)($optLocalMap[$id][$locale] ?? ''));
        $reason = '';
        if ($stored === '') {
            $reason = 'missing';
        } elseif ($locale !== 'zh_Hans_CN' && $source !== '' && $stored === $source && preg_match('/\p{Han}/u', $source) === 1) {
            $reason = 'source_copy';
        } elseif ($locale !== 'zh_Hans_CN' && preg_match('/\p{Han}/u', $stored) === 1) {
            $reason = 'han_leak';
        }
        if ($reason !== '') {
            $attrCode = $attrById[$meta['attribute_id']]['code'] ?? '';
            $optGaps[] = [
                'id' => $id,
                'attribute_id' => $meta['attribute_id'],
                'attribute_code' => $attrCode,
                'source' => $source,
                'option_code' => $meta['code'],
                'scope_instance_id' => $meta['scope_instance_id'],
                'locale' => $locale,
                'stored' => $stored,
                'reason' => $reason,
            ];
        }
    }
}

$summary = [
    'locales' => $locales,
    'attr_total' => count($attrById),
    'attr_gaps' => count($attrGaps),
    'opt_total' => count($optById),
    'opt_gaps' => count($optGaps),
    'attr_gaps_by_locale' => [],
    'opt_gaps_by_locale' => [],
    'opt_gaps_by_attr' => [],
    'opt_gaps_by_reason' => [],
    'en_us_han_leak_options' => [],
];

foreach ($attrGaps as $g) {
    $summary['attr_gaps_by_locale'][$g['locale']] = ($summary['attr_gaps_by_locale'][$g['locale']] ?? 0) + 1;
}
foreach ($optGaps as $g) {
    $summary['opt_gaps_by_locale'][$g['locale']] = ($summary['opt_gaps_by_locale'][$g['locale']] ?? 0) + 1;
    $k = $g['attribute_code'] !== '' ? $g['attribute_code'] : ('aid:' . $g['attribute_id']);
    $summary['opt_gaps_by_attr'][$k] = ($summary['opt_gaps_by_attr'][$k] ?? 0) + 1;
    $summary['opt_gaps_by_reason'][$g['reason']] = ($summary['opt_gaps_by_reason'][$g['reason']] ?? 0) + 1;
    if ($g['locale'] === 'en_US' && in_array($g['reason'], ['han_leak', 'source_copy', 'missing'], true)) {
        $summary['en_us_han_leak_options'][] = $g;
    }
}
arsort($summary['opt_gaps_by_attr']);

echo 'ATTR_TOTAL=' . $summary['attr_total'] . ' ATTR_GAPS=' . $summary['attr_gaps'] . PHP_EOL;
echo 'OPT_TOTAL=' . $summary['opt_total'] . ' OPT_GAPS=' . $summary['opt_gaps'] . PHP_EOL;
echo 'ATTR_GAPS_BY_LOCALE=' . json_encode($summary['attr_gaps_by_locale'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'OPT_GAPS_BY_LOCALE=' . json_encode($summary['opt_gaps_by_locale'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'OPT_GAPS_BY_REASON=' . json_encode($summary['opt_gaps_by_reason'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'OPT_GAPS_BY_ATTR=' . json_encode($summary['opt_gaps_by_attr'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'EN_US_OPTION_ISSUES=' . count($summary['en_us_han_leak_options']) . PHP_EOL;

echo "--- ATTR_GAP_SAMPLES ---\n";
foreach (array_slice($attrGaps, 0, $limit) as $g) {
    echo json_encode($g, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
echo "--- EN_US_OPTION_ISSUE_SAMPLES ---\n";
foreach (array_slice($summary['en_us_han_leak_options'], 0, $limit) as $g) {
    echo json_encode($g, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

// 九尾 / screenshot product
$jiuwei = [];
foreach ($optById as $id => $meta) {
    if (str_contains($meta['value'], '九尾') || preg_match('/jiuwei/i', $meta['value']) === 1 || str_contains($meta['value'], '红色九尾')) {
        $jiuwei[] = [
            'id' => $id,
            'attribute_code' => $attrById[$meta['attribute_id']]['code'] ?? '',
            'source' => $meta['value'],
            'scope_instance_id' => $meta['scope_instance_id'],
            'locals_sample' => array_intersect_key(
                $optLocalMap[$id] ?? [],
                array_flip(['zh_Hans_CN', 'en_US', 'es_ES', 'fr_FR', 'hi_IN', 'ar_SA', 'de_DE'])
            ),
            'missing_locales' => array_values(array_filter($locales, static function (string $loc) use ($optLocalMap, $id, $meta): bool {
                $stored = trim((string)($optLocalMap[$id][$loc] ?? ''));
                if ($stored === '') {
                    return true;
                }
                if ($loc !== 'zh_Hans_CN' && preg_match('/\p{Han}/u', $stored) === 1) {
                    return true;
                }
                if ($loc !== 'zh_Hans_CN' && $stored === $meta['value'] && preg_match('/\p{Han}/u', $meta['value']) === 1) {
                    return true;
                }
                return false;
            })),
        ];
    }
}
echo "--- JIUWEI_OPTIONS ---\n";
echo json_encode($jiuwei, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if ($skuFilter !== '') {
    $st = $pdo->prepare('SELECT product_id, sku FROM w_product WHERE sku = ? OR sku LIKE ? LIMIT 20');
    $st->execute([$skuFilter, '%' . $skuFilter . '%']);
    $products = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "--- PRODUCT_MATCH ---\n";
    echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    foreach ($products as $p) {
        $pid = (int)$p['product_id'];
        $productOptGaps = array_values(array_filter($optGaps, static fn(array $g): bool => (int)$g['scope_instance_id'] === $pid));
        echo "product_id={$pid} private_opt_gaps=" . count($productOptGaps) . PHP_EOL;
        foreach (array_slice($productOptGaps, 0, 30) as $g) {
            echo json_encode($g, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }
}

if ($jsonOut !== '') {
    file_put_contents($jsonOut, json_encode([
        'summary' => $summary,
        'attr_gaps' => $attrGaps,
        'opt_gaps' => $optGaps,
        'jiuwei' => $jiuwei,
    ], JSON_UNESCAPED_UNICODE));
    echo "WROTE {$jsonOut}\n";
}
