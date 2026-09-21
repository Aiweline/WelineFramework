<?php

declare(strict_types=1);

/**
 * Delete wrongly translated variant-axis value_json locale overlays.
 *
 * Product-optimize waves baked per-locale translations into style_type/color/size
 * attribute_value rows. Those identities must stay on the empty/source locale;
 * display copy belongs in EAV Option LocalDescription.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-variant-axis-identity-locales.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-variant-axis-identity-locales.php --apply
 */

$apply = in_array('--apply', $argv, true);
$dryRun = !$apply || in_array('--dry-run', $argv, true);

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'];
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'], $db['hostport'], $db['database']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$axisCodes = ['style_type', 'color', 'size', 'character', 'look_ref', 'prop'];
$placeholders = implode(',', array_fill(0, count($axisCodes), '?'));

$countSql = "
SELECT attribute_code, COUNT(*) AS rows
FROM w_product_ws_0_attribute_value
WHERE store_id = 0
  AND entity_type = 'product'
  AND attribute_code IN ($placeholders)
  AND locale IS NOT NULL
  AND locale <> ''
GROUP BY attribute_code
ORDER BY attribute_code
";
$st = $pdo->prepare($countSql);
$st->execute($axisCodes);
echo "BEFORE:\n";
$total = 0;
foreach ($st as $row) {
    $n = (int)$row['rows'];
    $total += $n;
    echo "  {$row['attribute_code']}={$n}\n";
}
echo "TOTAL={$total}\n";

if ($dryRun && !$apply) {
    echo "dry-run only; pass --apply to delete non-empty locale axis overlays.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $del = $pdo->prepare("
        DELETE FROM w_product_ws_0_attribute_value
        WHERE store_id = 0
          AND entity_type = 'product'
          AND attribute_code IN ($placeholders)
          AND locale IS NOT NULL
          AND locale <> ''
    ");
    $del->execute($axisCodes);
    $deleted = $del->rowCount();
    $pdo->commit();
    echo "DELETED={$deleted}\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$st = $pdo->prepare($countSql);
$st->execute($axisCodes);
echo "AFTER:\n";
$remain = 0;
foreach ($st as $row) {
    $n = (int)$row['rows'];
    $remain += $n;
    echo "  {$row['attribute_code']}={$n}\n";
}
echo "REMAIN={$remain}\n";

# Spot-check product 170
$st = $pdo->prepare("
    SELECT locale, value_json
    FROM w_product_ws_0_attribute_value
    WHERE entity_id = 170 AND attribute_code = 'style_type' AND store_id = 0
    ORDER BY locale
");
$st->execute();
echo "PRODUCT_170_STYLE_TYPE:\n";
foreach ($st as $row) {
    echo '  ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
