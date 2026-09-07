<?php

declare(strict_types=1);

/**
 * Privatize special variant-axis options (character / look_ref / style_type).
 *
 * - Shared option referenced by exactly one product → scope_instance_id = product_id
 * - Shared option referenced by multiple products → keep original for the lowest
 *   product_id (privatize), clone private options for the rest, rewrite that
 *   product's attribute_value tokens and offer combination_key option ids
 *
 * Display stays correct because labels are unchanged and product refs point at
 * options usable by that instance.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-special-axis-options-to-private.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-special-axis-options-to-private.php --apply
 *   php ... --apply --attributes=character,look_ref,style_type
 */

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$modes = array_values(array_intersect($argv, ['--dry-run', '--apply']));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --dry-run or --apply.\n");
    exit(2);
}
$apply = $modes[0] === '--apply';

$attributeCodes = ['character', 'look_ref', 'style_type'];
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--attributes=')) {
        $raw = strtolower(trim(substr($argument, strlen('--attributes='))));
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($parts === []) {
            fwrite(STDERR, "Invalid --attributes list.\n");
            exit(2);
        }
        $attributeCodes = $parts;
    }
}
foreach ($attributeCodes as $code) {
    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) {
        fwrite(STDERR, "Invalid attribute code: {$code}\n");
        exit(2);
    }
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

$exists = $pdo->query(
    "SELECT 1 FROM information_schema.columns
     WHERE table_name='w_eav_attribute_option' AND column_name='scope_instance_id'",
)->fetchColumn();
if (!$exists) {
    $pdo->exec(
        'ALTER TABLE w_eav_attribute_option
         ADD COLUMN scope_instance_id INTEGER NOT NULL DEFAULT 0',
    );
    echo "[schema] added w_eav_attribute_option.scope_instance_id\n";
}

$attrValueTables = $pdo->query(
    "SELECT tablename FROM pg_tables
     WHERE schemaname='public' AND tablename ~ '^w_product_ws_[0-9]+_attribute_value$'
     ORDER BY 1",
)->fetchAll(PDO::FETCH_COLUMN);
$offerTables = $pdo->query(
    "SELECT tablename FROM pg_tables
     WHERE schemaname='public' AND tablename ~ '^w_product_ws_[0-9]+_offer$'
     ORDER BY 1",
)->fetchAll(PDO::FETCH_COLUMN);

/**
 * @return array<string, array<int, true>>
 */
$loadRefs = static function (PDO $pdo, array $tables, string $attributeCode): array {
    $byToken = [];
    foreach ($tables as $table) {
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
            $tokens = is_array($decoded) ? $decoded : [trim($raw, "\" \t\n\r")];
            foreach ($tokens as $token) {
                $token = trim((string)$token);
                if ($token === '') {
                    continue;
                }
                $byToken[$token][$productId] = true;
            }
        }
    }

    return $byToken;
};

/**
 * @param list<mixed> $tokens
 * @return list<mixed>
 */
$rewriteTokens = static function (array $tokens, string $oldId, string $newId): array {
    $out = [];
    foreach ($tokens as $token) {
        if (is_int($token) || is_float($token)) {
            $out[] = ((string)(int)$token === $oldId) ? (int)$newId : $token;
            continue;
        }
        $asString = trim((string)$token);
        $out[] = $asString === $oldId ? (ctype_digit($newId) ? (int)$newId : $newId) : $token;
    }

    return $out;
};

$rewriteCombinationKey = static function (string $key, string $axis, string $oldId, string $newId): string {
    if ($key === '' || $oldId === $newId) {
        return $key;
    }
    $parts = explode('|', $key);
    $changed = false;
    foreach ($parts as $i => $part) {
        $eq = strpos($part, '=');
        if ($eq === false) {
            continue;
        }
        $partAxis = rawurldecode(substr($part, 0, $eq));
        $partValue = rawurldecode(substr($part, $eq + 1));
        if ($partAxis === $axis && $partValue === $oldId) {
            $parts[$i] = rawurlencode($axis) . '=' . rawurlencode($newId);
            $changed = true;
        }
    }

    return $changed ? implode('|', $parts) : $key;
};

$cloneOption = static function (PDO $pdo, array $option, int $scopeInstanceId): int {
    $stmt = $pdo->prepare(
        'INSERT INTO w_eav_attribute_option
            (code, value, attribute_id, eav_entity_id, swatch_image, swatch_color, swatch_text, scope_instance_id, create_time, update_time)
         VALUES
            (:code, :value, :attribute_id, :eav_entity_id, :swatch_image, :swatch_color, :swatch_text, :scope_instance_id, NOW(), NOW())
         RETURNING option_id',
    );
    $stmt->execute([
        ':code' => (string)$option['code'],
        ':value' => (string)$option['value'],
        ':attribute_id' => (int)$option['attribute_id'],
        ':eav_entity_id' => (int)$option['eav_entity_id'],
        ':swatch_image' => $option['swatch_image'] !== null && $option['swatch_image'] !== ''
            ? (string)$option['swatch_image'] : null,
        ':swatch_color' => $option['swatch_color'] !== null && $option['swatch_color'] !== ''
            ? (string)$option['swatch_color'] : null,
        ':swatch_text' => $option['swatch_text'] !== null && $option['swatch_text'] !== ''
            ? (string)$option['swatch_text'] : null,
        ':scope_instance_id' => $scopeInstanceId,
    ]);

    return (int)$stmt->fetchColumn();
};

$rewriteProductRefs = static function (
    PDO $pdo,
    array $attrValueTables,
    array $offerTables,
    string $attributeCode,
    int $productId,
    int $oldOptionId,
    int $newOptionId,
) use ($rewriteTokens, $rewriteCombinationKey): array {
    $old = (string)$oldOptionId;
    $new = (string)$newOptionId;
    $attrRows = 0;
    $offerRows = 0;

    foreach ($attrValueTables as $table) {
        $sql = "SELECT value_id, value_json, value_text
                FROM {$table}
                WHERE entity_type='product'
                  AND entity_id=" . (int)$productId . '
                  AND attribute_code=' . $pdo->quote($attributeCode) . '
                  AND COALESCE(cleared, 0)=0';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rawJson = (string)($row['value_json'] ?? '');
            $rawText = (string)($row['value_text'] ?? '');
            $raw = trim($rawJson !== '' ? $rawJson : $rawText);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $next = $rewriteTokens($decoded, $old, $new);
                if ($next === $decoded) {
                    continue;
                }
                $encoded = json_encode(array_values($next), JSON_UNESCAPED_UNICODE);
                $upd = $pdo->prepare(
                    "UPDATE {$table}
                     SET value_json = :value_json, value_text = NULL
                     WHERE value_id = :value_id",
                );
                $upd->execute([
                    ':value_json' => $encoded,
                    ':value_id' => (int)$row['value_id'],
                ]);
                $attrRows += $upd->rowCount();
                continue;
            }
            if (trim($raw, "\" \t\n\r") === $old) {
                $upd = $pdo->prepare(
                    "UPDATE {$table}
                     SET value_json = :value_json, value_text = NULL
                     WHERE value_id = :value_id",
                );
                $upd->execute([
                    ':value_json' => json_encode([(int)$new], JSON_UNESCAPED_UNICODE),
                    ':value_id' => (int)$row['value_id'],
                ]);
                $attrRows += $upd->rowCount();
            }
        }
    }

    foreach ($offerTables as $table) {
        $sql = "SELECT offer_id, combination_key
                FROM {$table}
                WHERE product_id=" . (int)$productId . "
                  AND combination_key ~ ('(^|\\|)' || " . $pdo->quote($attributeCode) . " || '=' || "
            . $pdo->quote($old) . " || '(\\||$)')";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $before = (string)$row['combination_key'];
            $after = $rewriteCombinationKey($before, $attributeCode, $old, $new);
            if ($after === $before) {
                continue;
            }
            $upd = $pdo->prepare(
                "UPDATE {$table}
                 SET combination_key = :combination_key, updated_at = NOW()
                 WHERE offer_id = :offer_id",
            );
            $upd->execute([
                ':combination_key' => $after,
                ':offer_id' => (int)$row['offer_id'],
            ]);
            $offerRows += $upd->rowCount();
        }
    }

    return ['attr_rows' => $attrRows, 'offer_rows' => $offerRows];
};

$totals = [
    'scanned' => 0,
    'already_private' => 0,
    'unreferenced' => 0,
    'singleton' => 0,
    'multi' => 0,
    'clones' => 0,
    'attr_rewrites' => 0,
    'offer_rewrites' => 0,
];

if ($apply) {
    $pdo->beginTransaction();
}

try {
    foreach ($attributeCodes as $attributeCode) {
        $attributeId = (int)$pdo->query(
            'SELECT attribute_id FROM w_eav_attribute WHERE code=' . $pdo->quote($attributeCode) . ' LIMIT 1',
        )->fetchColumn();
        if ($attributeId <= 0) {
            echo "[skip] attribute not found: {$attributeCode}\n";
            continue;
        }

        $options = $pdo->query(
            'SELECT option_id, code, value, attribute_id, eav_entity_id,
                    swatch_image, swatch_color, swatch_text, scope_instance_id
             FROM w_eav_attribute_option
             WHERE attribute_id=' . $attributeId . '
             ORDER BY option_id',
        )->fetchAll(PDO::FETCH_ASSOC);
        $byToken = $loadRefs($pdo, $attrValueTables, $attributeCode);

        echo "\n=== attribute={$attributeCode} id={$attributeId} options=" . count($options) . " ===\n";

        foreach ($options as $option) {
            ++$totals['scanned'];
            $optionId = (int)$option['option_id'];
            $scope = (int)$option['scope_instance_id'];
            if ($optionId <= 0) {
                continue;
            }
            if ($scope > 0) {
                ++$totals['already_private'];
                continue;
            }

            $products = [];
            foreach ([(string)$optionId, (string)$option['code'], (string)$option['value']] as $token) {
                if ($token === '') {
                    continue;
                }
                foreach ($byToken[$token] ?? [] as $productId => $_) {
                    $products[(int)$productId] = true;
                }
            }
            $productIds = array_keys($products);
            sort($productIds, SORT_NUMERIC);
            $count = count($productIds);
            if ($count === 0) {
                ++$totals['unreferenced'];
                continue;
            }

            if ($count === 1) {
                ++$totals['singleton'];
                $productId = $productIds[0];
                $line = sprintf(
                    'singleton #%d %s (%s) -> product %d',
                    $optionId,
                    $option['code'],
                    $option['value'],
                    $productId,
                );
                if (!$apply) {
                    echo '[dry-run] ' . $line . "\n";
                    continue;
                }
                $stmt = $pdo->prepare(
                    'UPDATE w_eav_attribute_option
                     SET scope_instance_id = :scope, update_time = NOW()
                     WHERE option_id = :option_id AND scope_instance_id = 0',
                );
                $stmt->execute([':scope' => $productId, ':option_id' => $optionId]);
                echo '[apply] ' . $line . ' updated=' . $stmt->rowCount() . "\n";
                continue;
            }

            ++$totals['multi'];
            $keeper = $productIds[0];
            $line = sprintf(
                'multi #%d %s (%s) products=%s keeper=%d',
                $optionId,
                $option['code'],
                $option['value'],
                implode(',', $productIds),
                $keeper,
            );
            if (!$apply) {
                echo '[dry-run] ' . $line . ' clones=' . (count($productIds) - 1) . "\n";
                continue;
            }

            $stmt = $pdo->prepare(
                'UPDATE w_eav_attribute_option
                 SET scope_instance_id = :scope, update_time = NOW()
                 WHERE option_id = :option_id AND scope_instance_id = 0',
            );
            $stmt->execute([':scope' => $keeper, ':option_id' => $optionId]);
            echo '[apply] ' . $line . ' privatize_keeper=' . $stmt->rowCount() . "\n";

            foreach ($productIds as $productId) {
                if ($productId === $keeper) {
                    continue;
                }
                $newOptionId = $cloneOption($pdo, $option, $productId);
                ++$totals['clones'];
                $stats = $rewriteProductRefs(
                    $pdo,
                    $attrValueTables,
                    $offerTables,
                    $attributeCode,
                    $productId,
                    $optionId,
                    $newOptionId,
                );
                $totals['attr_rewrites'] += $stats['attr_rows'];
                $totals['offer_rewrites'] += $stats['offer_rows'];
                echo sprintf(
                    "  clone product %d option #%d -> #%d attr=%d offer=%d\n",
                    $productId,
                    $optionId,
                    $newOptionId,
                    $stats['attr_rows'],
                    $stats['offer_rows'],
                );
            }
        }
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nsummary scanned={$totals['scanned']}"
    . " already_private={$totals['already_private']}"
    . " unreferenced_shared={$totals['unreferenced']}"
    . " singleton={$totals['singleton']}"
    . " multi={$totals['multi']}"
    . " clones={$totals['clones']}"
    . " attr_rewrites={$totals['attr_rewrites']}"
    . " offer_rewrites={$totals['offer_rewrites']}"
    . "\n";

if ($apply) {
    foreach ($attributeCodes as $attributeCode) {
        $attributeId = (int)$pdo->query(
            'SELECT attribute_id FROM w_eav_attribute WHERE code=' . $pdo->quote($attributeCode) . ' LIMIT 1',
        )->fetchColumn();
        if ($attributeId <= 0) {
            continue;
        }
        $private = (int)$pdo->query(
            'SELECT COUNT(*) FROM w_eav_attribute_option
             WHERE attribute_id=' . $attributeId . ' AND scope_instance_id > 0',
        )->fetchColumn();
        $shared = (int)$pdo->query(
            'SELECT COUNT(*) FROM w_eav_attribute_option
             WHERE attribute_id=' . $attributeId . ' AND scope_instance_id = 0',
        )->fetchColumn();
        echo "after {$attributeCode}: private={$private} shared={$shared}\n";
    }
}

echo $apply ? "done apply\n" : "done dry-run (no writes)\n";
