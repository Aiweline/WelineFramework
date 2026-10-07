<?php

declare(strict_types=1);

/**
 * 长安汉服（website_id=0）全部商品价格 ×10（amount_minor）。
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/scale-hanfu-prices-x10.php [--dry-run]
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

$_SERVER['WELINE_WEBSITE_ID'] = '0';

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__, 5);
$stamp = date('Ymd_His');
$bakDir = $root . '/var/backup/hanfu-prices-x10-' . $stamp;
$factor = 10;
$websiteId = 0;
$table = 'w_product_ws_0_price';

$env = include $root . '/app/etc/env.php';
$db = $env['db']['master'];
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'], $db['hostport'], $db['database']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$site = $pdo->query('SELECT website_id, name, code FROM w_weline_websites_website WHERE website_id = 0')->fetch(PDO::FETCH_ASSOC);
if (!$site || (($site['name'] ?? '') !== '长安汉服' && ($site['code'] ?? '') !== 'default')) {
    fwrite(STDERR, 'refuse: website 0 is not 长安汉服 default: ' . json_encode($site, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$statsBefore = $pdo->query("SELECT COUNT(*) AS c, MIN(amount_minor) AS mn, MAX(amount_minor) AS mx, AVG(amount_minor)::bigint AS avg FROM {$table} WHERE COALESCE(cleared,0)=0")->fetch(PDO::FETCH_ASSOC);
$overflow = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE COALESCE(cleared,0)=0 AND amount_minor > (9223372036854775807 / {$factor})")->fetchColumn();
if ($overflow > 0) {
    fwrite(STDERR, "refuse: {$overflow} rows would overflow bigint after ×{$factor}\n");
    exit(1);
}

$sampleBefore = $pdo->query("SELECT price_id, offer_id, currency, amount_minor FROM {$table} WHERE COALESCE(cleared,0)=0 ORDER BY price_id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'website' => $site,
    'table' => $table,
    'factor' => $factor,
    'dry_run' => $dryRun,
    'before' => $statsBefore,
    'sample_before' => $sampleBefore,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if ($dryRun) {
    echo "dry-run only; no writes\n";
    exit(0);
}

if (is_dir($bakDir)) {
    fwrite(STDERR, "bak exists {$bakDir}\n");
    exit(1);
}
mkdir($bakDir, 0775, true);
$bakCsv = $bakDir . '/w_product_ws_0_price.csv';
$fh = fopen($bakCsv, 'wb');
if ($fh === false) {
    fwrite(STDERR, "cannot write {$bakCsv}\n");
    exit(1);
}
fputcsv($fh, ['price_id', 'store_id', 'offer_id', 'currency', 'amount_minor', 'cleared', 'scope_state', 'version']);
$stmt = $pdo->query("SELECT price_id, store_id, offer_id, currency, amount_minor, cleared, scope_state, version FROM {$table} ORDER BY price_id");
$rowsBacked = 0;
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    fputcsv($fh, $row);
    $rowsBacked++;
}
fclose($fh);
file_put_contents($bakDir . '/MANIFEST.json', json_encode([
    'created_at' => date('c'),
    'website' => $site,
    'table' => $table,
    'factor' => $factor,
    'rows_backed' => $rowsBacked,
    'before' => $statsBefore,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

$pdo->beginTransaction();
try {
    $updated = $pdo->exec("UPDATE {$table} SET amount_minor = amount_minor * {$factor}, version = COALESCE(version, 0) + 1 WHERE COALESCE(cleared, 0) = 0");
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

$statsAfter = $pdo->query("SELECT COUNT(*) AS c, MIN(amount_minor) AS mn, MAX(amount_minor) AS mx, AVG(amount_minor)::bigint AS avg FROM {$table} WHERE COALESCE(cleared,0)=0")->fetch(PDO::FETCH_ASSOC);
$sampleAfter = $pdo->query("SELECT price_id, offer_id, currency, amount_minor FROM {$table} WHERE COALESCE(cleared,0)=0 ORDER BY price_id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

/** @var StorefrontCatalogCacheCoordinator $cache */
$cache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
$cache->notifyCatalogChanged($websiteId, 'hanfu_prices_scale_x10', [
    'factor' => $factor,
    'updated' => $updated,
    'backup' => $bakDir,
]);

echo json_encode([
    'status' => 'ok',
    'updated' => $updated,
    'backup_dir' => $bakDir,
    'backup_csv' => $bakCsv,
    'rows_backed' => $rowsBacked,
    'after' => $statsAfter,
    'sample_after' => $sampleAfter,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
