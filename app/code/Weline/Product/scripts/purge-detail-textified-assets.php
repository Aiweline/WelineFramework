<?php

declare(strict_types=1);

/**
 * Physically delete detail images already replaced by HTML textify, plus FileAsset metadata.
 *
 * php app/code/Weline/Product/scripts/purge-detail-textified-assets.php --dry-run --from-report=var/hanfu-1688/detail-textify/product-175-apply-20260914-135145.json
 * php app/code/Weline/Product/scripts/purge-detail-textified-assets.php --apply --asset=c4639f19-e73f-45b5-bcc0-7a101998a560 --asset=95bf871a-2bbc-45c8-ba07-3c5bdc9ac796
 * php app/code/Weline/Product/scripts/purge-detail-textified-assets.php --apply --product=175 --website=0
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\DetailTextifiedAssetPurger;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:', 'product:', 'asset:', 'from-report:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = isset($options['product']) ? max(0, (int)$options['product']) : 0;
$root = dirname(__DIR__, 5);
$mediaRoot = $root . '/pub/media';

$assetIds = [];
if (isset($options['asset'])) {
    $raw = $options['asset'];
    foreach (is_array($raw) ? $raw : [$raw] as $id) {
        $assetIds[] = strtolower(trim((string)$id));
    }
}
if (!empty($options['from-report'])) {
    $reportPath = (string)$options['from-report'];
    if ($reportPath[0] !== '/') {
        $reportPath = $root . '/' . ltrim($reportPath, '/');
    }
    $report = json_decode((string)file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_keys((array)($report['replaced_assets'] ?? [])) as $id) {
        $assetIds[] = strtolower(trim((string)$id));
    }
    foreach ((array)($report['plans'] ?? []) as $plan) {
        foreach ((array)($plan['replaced_assets'] ?? []) as $id) {
            $assetIds[] = strtolower(trim((string)$id));
        }
    }
}

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

if ($productId > 0 && $assetIds === []) {
    $reportGlob = glob($root . '/var/hanfu-1688/detail-textify/product-' . $productId . '-apply-*.json') ?: [];
    rsort($reportGlob);
    if ($reportGlob !== []) {
        $report = json_decode((string)file_get_contents($reportGlob[0]), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys((array)($report['replaced_assets'] ?? [])) as $id) {
            $assetIds[] = strtolower(trim((string)$id));
        }
    }
}

$assetIds = array_values(array_unique(array_filter($assetIds)));
if ($assetIds === []) {
    fwrite(STDERR, "No asset ids. Use --asset / --from-report / --product.\n");
    exit(2);
}

$stillUsed = static function (string $assetId) use ($pdo, $websiteId): bool {
    $like = '%asset://' . $assetId . '%';
    $tables = $pdo->query(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public'
         AND tablename LIKE 'w_product_ws_%_attribute_value'",
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if ($websiteId >= 0 && !str_contains((string)$table, '_ws_' . $websiteId . '_')) {
            // Check all website shards — assets must be unused everywhere.
        }
        $stmt = $pdo->prepare(
            "SELECT 1 FROM {$table}
             WHERE entity_type = 'product' AND attribute_code = 'description'
               AND value_text LIKE :like LIMIT 1",
        );
        $stmt->execute(['like' => $like]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    $mediaTables = $pdo->query(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public'
         AND tablename LIKE 'w_product_ws_%_media'",
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($mediaTables as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE asset_id = :id LIMIT 1");
        $stmt->execute(['id' => $assetId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
};

$preview = [];
foreach ($assetIds as $assetId) {
    $st = $pdo->prepare(
        'SELECT disk_code, object_key, lifecycle_state, deleted_at
         FROM w_weline_file_asset WHERE asset_id = ?',
    );
    $st->execute([$assetId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $objectKey = (string)($row['object_key'] ?? '');
    $path = $objectKey !== '' ? $mediaRoot . '/' . ltrim($objectKey, '/') : '';
    $preview[] = [
        'asset_id' => $assetId,
        'still_used' => $stillUsed($assetId),
        'object_key' => $objectKey,
        'file_exists' => $path !== '' && is_file($path),
        'bytes' => ($path !== '' && is_file($path)) ? filesize($path) : null,
        'lifecycle_state' => $row['lifecycle_state'] ?? null,
        'deleted_at' => $row['deleted_at'] ?? null,
        'db_row' => $row !== null,
    ];
}

$deleted = [];
if ($apply) {
    /** @var DetailTextifiedAssetPurger $purger */
    $purger = ObjectManager::getInstance(DetailTextifiedAssetPurger::class);
    $deleted = $purger->purgeReplaced($assetIds, $stillUsed, $mediaRoot);
}

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'asset_count' => count($assetIds),
    'preview' => $preview,
    'deleted' => $deleted,
];
$outDir = $root . '/var/hanfu-1688/detail-textify';
@mkdir($outDir, 0777, true);
$out = $outDir . '/purge-' . ($apply ? 'apply' : 'dry-run') . '-' . date('Ymd-His') . '.json';
file_put_contents($out, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$report['report_path'] = $out;
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
