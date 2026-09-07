<?php

declare(strict_types=1);

/**
 * Privatize shared color options that are referenced by exactly one product.
 *
 * Scans w_product_ws_*_attribute_value product multiselect rows for attribute_code=color,
 * matches tokens against option_id / code / value, and for singleton hits sets
 * scope_instance_id to that product_id (option_id unchanged).
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-singleton-color-options-to-private.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-singleton-color-options-to-private.php --apply
 *   php app/code/Weline/Product/scripts/remediate-singleton-color-options-to-private.php --apply --attribute=color
 */

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$modes = array_values(array_intersect($argv, ['--dry-run', '--apply']));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --dry-run or --apply.\n");
    exit(2);
}
$apply = $modes[0] === '--apply';
$attributeCode = 'color';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--attribute=')) {
        $attributeCode = strtolower(trim(substr($argument, strlen('--attribute='))));
    }
}
if ($attributeCode === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $attributeCode)) {
    fwrite(STDERR, "Invalid --attribute code.\n");
    exit(2);
}

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

$ensureScopeColumn = static function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name='w_eav_attribute_option' AND column_name='scope_instance_id'",
    )->fetchColumn();
    if ($exists) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE w_eav_attribute_option
         ADD COLUMN scope_instance_id INTEGER NOT NULL DEFAULT 0',
    );
    echo "[schema] added w_eav_attribute_option.scope_instance_id\n";
};
$ensureScopeColumn($pdo);

$attributeId = (int)$pdo->query(
    "SELECT attribute_id FROM w_eav_attribute WHERE code=" . $pdo->quote($attributeCode) . ' LIMIT 1',
)->fetchColumn();
if ($attributeId <= 0) {
    fwrite(STDERR, "Attribute not found: {$attributeCode}\n");
    exit(1);
}

$options = $pdo->query(
    'SELECT option_id, code, value, scope_instance_id
     FROM w_eav_attribute_option
     WHERE attribute_id=' . $attributeId . '
     ORDER BY option_id',
)->fetchAll(PDO::FETCH_ASSOC);

$shards = $pdo->query(
    "SELECT tablename FROM pg_tables
     WHERE schemaname='public' AND tablename LIKE 'w_product_ws_%_attribute_value'
     ORDER BY 1",
)->fetchAll(PDO::FETCH_COLUMN);

/** @var array<string, array<int, true>> $byToken */
$byToken = [];
foreach ($shards as $table) {
    $sql = "SELECT entity_id, value_json, value_text
            FROM {$table}
            WHERE entity_type='product'
              AND attribute_code=" . $pdo->quote($attributeCode) . '
              AND COALESCE(cleared, 0)=0';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int)($row['entity_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        $raw = trim((string)($row['value_json'] ?? $row['value_text'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        $tokens = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $tokens[] = trim((string)$item);
            }
        } else {
            $tokens[] = trim($raw, "\" \t\n\r");
        }
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $byToken[$token][$productId] = true;
        }
    }
}

$shared = 0;
$alreadyPrivate = 0;
$unreferenced = 0;
$multi = 0;
$singletons = [];

foreach ($options as $option) {
    $optionId = (int)($option['option_id'] ?? 0);
    $code = trim((string)($option['code'] ?? ''));
    $value = trim((string)($option['value'] ?? ''));
    $scope = (int)($option['scope_instance_id'] ?? 0);
    if ($optionId <= 0) {
        continue;
    }
    if ($scope > 0) {
        ++$alreadyPrivate;
        continue;
    }
    ++$shared;

    $products = [];
    foreach ([(string)$optionId, $code, $value] as $token) {
        if ($token === '') {
            continue;
        }
        foreach ($byToken[$token] ?? [] as $productId => $_) {
            $products[(int)$productId] = true;
        }
    }
    $count = count($products);
    if ($count === 0) {
        ++$unreferenced;
        continue;
    }
    if ($count !== 1) {
        ++$multi;
        continue;
    }
    $productId = (int)array_key_first($products);
    $singletons[] = [
        'option_id' => $optionId,
        'code' => $code,
        'value' => $value,
        'product_id' => $productId,
    ];
}

echo "attribute={$attributeCode} id={$attributeId}\n";
echo "options total=" . count($options)
    . " shared_scanned={$shared}"
    . " already_private={$alreadyPrivate}"
    . " unreferenced_shared={$unreferenced}"
    . " multi_product={$multi}"
    . " singleton=" . count($singletons)
    . "\n";

foreach ($singletons as $row) {
    $line = sprintf(
        'option #%d %s (%s) -> product %d',
        $row['option_id'],
        $row['code'],
        $row['value'],
        $row['product_id'],
    );
    if (!$apply) {
        echo '[dry-run] ' . $line . "\n";
        continue;
    }
    $stmt = $pdo->prepare(
        'UPDATE w_eav_attribute_option
         SET scope_instance_id = :scope, update_time = NOW()
         WHERE option_id = :option_id
           AND attribute_id = :attribute_id
           AND scope_instance_id = 0',
    );
    $stmt->execute([
        ':scope' => $row['product_id'],
        ':option_id' => $row['option_id'],
        ':attribute_id' => $attributeId,
    ]);
    echo '[apply] ' . $line . ' updated=' . $stmt->rowCount() . "\n";
}

if ($apply) {
    $privateNow = (int)$pdo->query(
        'SELECT COUNT(*) FROM w_eav_attribute_option
         WHERE attribute_id=' . $attributeId . ' AND scope_instance_id > 0',
    )->fetchColumn();
    $sharedNow = (int)$pdo->query(
        'SELECT COUNT(*) FROM w_eav_attribute_option
         WHERE attribute_id=' . $attributeId . ' AND scope_instance_id = 0',
    )->fetchColumn();
    echo "after: private={$privateNow} shared={$sharedNow}\n";
}

echo $apply ? "done apply\n" : "done dry-run (no writes)\n";
