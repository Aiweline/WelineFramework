<?php

declare(strict_types=1);

/**
 * Move misfiled color options (props / look_ref / style_type / character) off the
 * shared color palette onto their target axes.
 *
 * - Unreferenced: UPDATE option.attribute_id to the target attribute (option_id kept).
 * - Referenced: create target-axis option (private per product), remove from product
 *   color value, append to target attribute value, rewrite offer combination_key
 *   from color={old} to {axis}={new}.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-misfiled-color-options-to-axes.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-misfiled-color-options-to-axes.php --apply
 */

require dirname(__DIR__, 5) . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Sample\Hanfu1688\ColorAxisClassifier;
use Weline\Product\Service\ProductCatalogEavBootstrap;

$modes = array_values(array_intersect($argv, ['--dry-run', '--apply']));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --dry-run or --apply.\n");
    exit(2);
}
$apply = $modes[0] === '--apply';

ObjectManager::getInstance(ProductCatalogEavBootstrap::class)->ensureHanfuSchema();

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

$attrIds = [];
foreach (array_merge(['color'], ColorAxisClassifier::targetAxes()) as $code) {
    $id = (int)$pdo->query(
        'SELECT attribute_id FROM w_eav_attribute WHERE code=' . $pdo->quote($code) . ' LIMIT 1',
    )->fetchColumn();
    if ($id <= 0) {
        fwrite(STDERR, "Missing attribute: {$code}\n");
        exit(1);
    }
    $attrIds[$code] = $id;
}
$colorAttrId = $attrIds['color'];

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
$loadColorRefs = static function (PDO $pdo, array $tables): array {
    $byToken = [];
    foreach ($tables as $table) {
        $sql = "SELECT entity_id, value_json, value_text
                FROM {$table}
                WHERE entity_type='product'
                  AND attribute_code='color'
                  AND COALESCE(cleared, 0)=0";
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

$rewriteCombinationKeyAxis = static function (
    string $key,
    string $fromAxis,
    string $oldId,
    string $toAxis,
    string $newId,
): string {
    if ($key === '') {
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
        if ($partAxis === $fromAxis && $partValue === $oldId) {
            $parts[$i] = rawurlencode($toAxis) . '=' . rawurlencode($newId);
            $changed = true;
        }
    }

    return $changed ? implode('|', $parts) : $key;
};

/**
 * @param list<mixed> $tokens
 * @return list<mixed>
 */
$removeToken = static function (array $tokens, string $oldId): array {
    $out = [];
    foreach ($tokens as $token) {
        $asString = is_int($token) || is_float($token)
            ? (string)(int)$token
            : trim((string)$token);
        if ($asString === $oldId) {
            continue;
        }
        $out[] = $token;
    }

    return array_values($out);
};

$ensureProductAxisValue = static function (
    PDO $pdo,
    array $tables,
    int $productId,
    string $attributeCode,
    int $optionId,
) use ($apply): int {
    foreach ($tables as $table) {
        $sql = "SELECT value_id, value_json, value_text, store_id, locale, value_type, scope_state
                FROM {$table}
                WHERE entity_type='product'
                  AND entity_id=" . (int)$productId . '
                  AND attribute_code=' . $pdo->quote($attributeCode) . '
                  AND COALESCE(cleared, 0)=0
                ORDER BY value_id
                LIMIT 1';
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $raw = trim((string)($row['value_json'] ?? $row['value_text'] ?? ''));
            $decoded = json_decode($raw, true);
            $tokens = is_array($decoded) ? $decoded : [];
            foreach ($tokens as $token) {
                $asString = is_int($token) || is_float($token)
                    ? (string)(int)$token
                    : trim((string)$token);
                if ($asString === (string)$optionId) {
                    return 0;
                }
            }
            $tokens[] = $optionId;
            if (!$apply) {
                return 1;
            }
            $upd = $pdo->prepare(
                "UPDATE {$table}
                 SET value_json = :value_json, value_text = NULL
                 WHERE value_id = :value_id",
            );
            $upd->execute([
                ':value_json' => json_encode(array_values($tokens), JSON_UNESCAPED_UNICODE),
                ':value_id' => (int)$row['value_id'],
            ]);

            return $upd->rowCount();
        }
    }

    // Insert into first shard table (website 0) when product has no row yet.
    $table = $tables[0] ?? '';
    if ($table === '' || !$apply) {
        return $table === '' ? 0 : 1;
    }
    $ins = $pdo->prepare(
        "INSERT INTO {$table}
            (store_id, entity_type, entity_id, attribute_code, locale, value_json, cleared, is_required, value_type, scope_state)
         VALUES
            (0, 'product', :entity_id, :attribute_code, '', :value_json, 0, 0, 'json', 'explicit')",
    );
    $ins->execute([
        ':entity_id' => $productId,
        ':attribute_code' => $attributeCode,
        ':value_json' => json_encode([$optionId], JSON_UNESCAPED_UNICODE),
    ]);

    return $ins->rowCount();
};

$cloneOrFindOption = static function (
    PDO $pdo,
    array $source,
    int $targetAttributeId,
    int $scopeInstanceId,
): int {
    $code = (string)$source['code'];
    $value = (string)$source['value'];
    $existing = $pdo->prepare(
        'SELECT option_id FROM w_eav_attribute_option
         WHERE attribute_id = :attribute_id
           AND scope_instance_id = :scope
           AND (code = :code OR value = :value)
         ORDER BY option_id
         LIMIT 1',
    );
    $existing->execute([
        ':attribute_id' => $targetAttributeId,
        ':scope' => $scopeInstanceId,
        ':code' => $code,
        ':value' => $value,
    ]);
    $found = (int)$existing->fetchColumn();
    if ($found > 0) {
        return $found;
    }

    // Avoid unique (attribute_id, code, scope) collisions with a different label.
    $codeCheck = $pdo->prepare(
        'SELECT option_id FROM w_eav_attribute_option
         WHERE attribute_id = :attribute_id AND scope_instance_id = :scope AND code = :code
         LIMIT 1',
    );
    $codeCheck->execute([
        ':attribute_id' => $targetAttributeId,
        ':scope' => $scopeInstanceId,
        ':code' => $code,
    ]);
    if ((int)$codeCheck->fetchColumn() > 0) {
        $code = rtrim(substr($code, 0, 8), '-') . '-' . substr(hash('sha256', $value), 0, 4);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO w_eav_attribute_option
            (code, value, attribute_id, eav_entity_id, swatch_image, swatch_color, swatch_text, scope_instance_id, create_time, update_time)
         VALUES
            (:code, :value, :attribute_id, :eav_entity_id, :swatch_image, :swatch_color, :swatch_text, :scope_instance_id, NOW(), NOW())
         RETURNING option_id',
    );
    $stmt->execute([
        ':code' => $code,
        ':value' => $value,
        ':attribute_id' => $targetAttributeId,
        ':eav_entity_id' => (int)$source['eav_entity_id'],
        ':swatch_image' => $source['swatch_image'] !== null && $source['swatch_image'] !== ''
            ? (string)$source['swatch_image'] : null,
        ':swatch_color' => $source['swatch_color'] !== null && $source['swatch_color'] !== ''
            ? (string)$source['swatch_color'] : null,
        ':swatch_text' => $source['swatch_text'] !== null && $source['swatch_text'] !== ''
            ? (string)$source['swatch_text'] : null,
        ':scope_instance_id' => $scopeInstanceId,
    ]);

    return (int)$stmt->fetchColumn();
};

$options = $pdo->query(
    'SELECT option_id, code, value, attribute_id, eav_entity_id,
            swatch_image, swatch_color, swatch_text, scope_instance_id
     FROM w_eav_attribute_option
     WHERE attribute_id=' . $colorAttrId . '
     ORDER BY option_id',
)->fetchAll(PDO::FETCH_ASSOC);
$byToken = $loadColorRefs($pdo, $attrValueTables);

$stats = [
    'scanned' => 0,
    'kept_color' => 0,
    'moved_unreferenced' => 0,
    'moved_referenced' => 0,
    'products_touched' => 0,
    'offer_rewrites' => 0,
];

if ($apply) {
    $pdo->beginTransaction();
}

try {
    foreach ($options as $option) {
        ++$stats['scanned'];
        $optionId = (int)$option['option_id'];
        $label = (string)$option['value'];
        $target = ColorAxisClassifier::classify($label);
        if ($target === ColorAxisClassifier::AXIS_COLOR) {
            ++$stats['kept_color'];
            continue;
        }
        $targetAttrId = $attrIds[$target];
        $products = [];
        foreach ([(string)$optionId, (string)$option['code'], $label] as $token) {
            if ($token === '') {
                continue;
            }
            foreach ($byToken[$token] ?? [] as $productId => $_) {
                $products[(int)$productId] = true;
            }
        }
        $productIds = array_keys($products);
        sort($productIds, SORT_NUMERIC);

        if ($productIds === []) {
            ++$stats['moved_unreferenced'];
            $line = sprintf(
                'unref #%d (%s) color -> %s',
                $optionId,
                $label,
                $target,
            );
            if (!$apply) {
                echo '[dry-run] ' . $line . "\n";
                continue;
            }
            // Resolve code clash on target shared scope.
            $clash = $pdo->prepare(
                'SELECT option_id FROM w_eav_attribute_option
                 WHERE attribute_id = :aid AND scope_instance_id = 0 AND code = :code
                   AND option_id <> :oid LIMIT 1',
            );
            $clash->execute([
                ':aid' => $targetAttrId,
                ':code' => (string)$option['code'],
                ':oid' => $optionId,
            ]);
            if ((int)$clash->fetchColumn() > 0) {
                $newCode = rtrim(substr((string)$option['code'], 0, 8), '-')
                    . '-' . substr(hash('sha256', $label), 0, 4);
                $pdo->prepare(
                    'UPDATE w_eav_attribute_option SET code = :code, update_time = NOW()
                     WHERE option_id = :oid',
                )->execute([':code' => $newCode, ':oid' => $optionId]);
            }
            $upd = $pdo->prepare(
                'UPDATE w_eav_attribute_option
                 SET attribute_id = :aid, update_time = NOW()
                 WHERE option_id = :oid AND attribute_id = :color_id',
            );
            $upd->execute([
                ':aid' => $targetAttrId,
                ':oid' => $optionId,
                ':color_id' => $colorAttrId,
            ]);
            echo '[apply] ' . $line . ' rows=' . $upd->rowCount() . "\n";
            continue;
        }

        ++$stats['moved_referenced'];
        $line = sprintf(
            'ref #%d (%s) color -> %s products=%s',
            $optionId,
            $label,
            $target,
            implode(',', $productIds),
        );
        if (!$apply) {
            echo '[dry-run] ' . $line . "\n";
            continue;
        }
        echo '[apply] ' . $line . "\n";

        foreach ($productIds as $productId) {
            $newOptionId = $cloneOrFindOption($pdo, $option, $targetAttrId, $productId);
            // Remove from color values.
            foreach ($attrValueTables as $table) {
                $sql = "SELECT value_id, value_json, value_text
                        FROM {$table}
                        WHERE entity_type='product'
                          AND entity_id=" . (int)$productId . "
                          AND attribute_code='color'
                          AND COALESCE(cleared, 0)=0";
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $raw = trim((string)($row['value_json'] ?? $row['value_text'] ?? ''));
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $next = $removeToken($decoded, (string)$optionId);
                    $next = $removeToken($next, (string)$option['code']);
                    $next = $removeToken($next, $label);
                    if ($next === $decoded) {
                        continue;
                    }
                    $pdo->prepare(
                        "UPDATE {$table}
                         SET value_json = :value_json, value_text = NULL
                         WHERE value_id = :value_id",
                    )->execute([
                        ':value_json' => json_encode(array_values($next), JSON_UNESCAPED_UNICODE),
                        ':value_id' => (int)$row['value_id'],
                    ]);
                }
            }
            $ensureProductAxisValue($pdo, $attrValueTables, $productId, $target, $newOptionId);

            foreach ($offerTables as $table) {
                $sql = "SELECT offer_id, combination_key
                        FROM {$table}
                        WHERE product_id=" . (int)$productId . "
                          AND combination_key LIKE '%color=%'";
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $before = (string)$row['combination_key'];
                    $parts = explode('|', $before);
                    $changed = false;
                    foreach ($parts as $i => $part) {
                        $eq = strpos($part, '=');
                        if ($eq === false) {
                            continue;
                        }
                        $partAxis = rawurldecode(substr($part, 0, $eq));
                        $partValue = rawurldecode(substr($part, $eq + 1));
                        if ($partAxis !== 'color') {
                            continue;
                        }
                        $matchesId = $partValue === (string)$optionId;
                        $matchesLabel = $partValue === $label || $partValue === (string)$option['code'];
                        if (!$matchesId && !$matchesLabel) {
                            continue;
                        }
                        $nextValue = $matchesId ? (string)$newOptionId : $partValue;
                        $parts[$i] = rawurlencode($target) . '=' . rawurlencode($nextValue);
                        $changed = true;
                    }
                    if (!$changed) {
                        continue;
                    }
                    $after = implode('|', $parts);
                    if ($after === $before) {
                        continue;
                    }
                    $pdo->prepare(
                        "UPDATE {$table}
                         SET combination_key = :ck, updated_at = NOW()
                         WHERE offer_id = :oid",
                    )->execute([
                        ':ck' => $after,
                        ':oid' => (int)$row['offer_id'],
                    ]);
                    ++$stats['offer_rewrites'];
                }
            }
            ++$stats['products_touched'];
            echo sprintf(
                "  product %d -> %s option #%d\n",
                $productId,
                $target,
                $newOptionId,
            );
        }

        // Drop original color option if nothing left references it.
        $still = false;
        foreach ([(string)$optionId, (string)$option['code'], $label] as $token) {
            // re-scan quickly in DB
        }
        $left = 0;
        foreach ($attrValueTables as $table) {
            $left += (int)$pdo->query(
                "SELECT COUNT(*) FROM {$table}
                 WHERE entity_type='product' AND attribute_code='color' AND COALESCE(cleared,0)=0
                   AND (
                     value_json LIKE " . $pdo->quote('%' . $optionId . '%') . '
                     OR value_json LIKE ' . $pdo->quote('%' . (string)$option['code'] . '%') . '
                   )',
            )->fetchColumn();
        }
        if ($left === 0) {
            $pdo->prepare('DELETE FROM w_eav_attribute_option WHERE option_id = :oid AND attribute_id = :aid')
                ->execute([':oid' => $optionId, ':aid' => $colorAttrId]);
            echo "  deleted leftover color option #{$optionId}\n";
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

echo "\nsummary scanned={$stats['scanned']} kept_color={$stats['kept_color']}"
    . " moved_unref={$stats['moved_unreferenced']} moved_ref={$stats['moved_referenced']}"
    . " products_touched={$stats['products_touched']} offer_rewrites={$stats['offer_rewrites']}\n";

// Residual misfiled on color
$leftMis = 0;
foreach ($pdo->query(
    'SELECT option_id, value FROM w_eav_attribute_option WHERE attribute_id=' . $colorAttrId,
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (ColorAxisClassifier::isMisfiledColor((string)$row['value'])) {
        echo "residual color #{$row['option_id']} {$row['value']}\n";
        ++$leftMis;
    }
}
echo "residual_misfiled_color={$leftMis}\n";
$propCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM w_eav_attribute_option WHERE attribute_id=' . $attrIds['prop'],
)->fetchColumn();
echo "prop_options={$propCount}\n";
echo $apply ? "done apply\n" : "done dry-run (no writes)\n";
