<?php

declare(strict_types=1);

/**
 * Apply exported detail magazine HTML + missing file_asset rows on changanhanfu production.
 *
 * php app/code/Weline/Product/scripts/apply-hanfu-detail-i18n-sync.php --pack=/path/to/hanfu-detail-sync-YYYYMMDD --dry-run
 * php app/code/Weline/Product/scripts/apply-hanfu-detail-i18n-sync.php --pack=... --apply --website=0
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'pack:', 'website:', 'limit:', 'offset:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$pack = (string)($options['pack'] ?? '');
$websiteId = max(0, (int)($options['website'] ?? 0));
$limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 0;
$offset = isset($options['offset']) ? max(0, (int)$options['offset']) : 0;

if ($pack === '' || !is_dir($pack)) {
    fwrite(STDERR, "Need --pack=/path/to/export dir\n");
    exit(2);
}

$descFile = $pack . '/descriptions.jsonl';
$assetFile = $pack . '/file_assets.json';
$mediaRoot = $pack . '/media';
if (!is_file($descFile)) {
    fwrite(STDERR, "Missing descriptions.jsonl\n");
    exit(2);
}

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '3306'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- file assets ---
$assetRows = is_file($assetFile) ? (json_decode((string)file_get_contents($assetFile), true) ?: []) : [];
echo 'asset_manifest=', count($assetRows), "\n";
$assetInserted = 0;
$assetSkipped = 0;
$mediaCopied = 0;
foreach ($assetRows as $row) {
    $assetId = (string)($row['asset_id'] ?? '');
    $objectKey = ltrim((string)($row['object_key'] ?? ''), '/');
    if ($assetId === '' || $objectKey === '') {
        continue;
    }
    $chk = $pdo->prepare('SELECT asset_id FROM w_weline_file_asset WHERE asset_id=? LIMIT 1');
    $chk->execute([$assetId]);
    $exists = (bool)$chk->fetchColumn();

    $src = $mediaRoot . '/' . $objectKey;
    $dst = dirname(__DIR__, 5) . '/pub/media/' . $objectKey;
    if (is_file($src)) {
        if ($apply) {
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0775, true);
            }
            if (!is_file($dst) || filesize($dst) !== filesize($src)) {
                if (!copy($src, $dst)) {
                    fwrite(STDERR, "media copy fail $objectKey\n");
                    exit(2);
                }
                @chown($dst, 'www');
                @chgrp($dst, 'www');
                $mediaCopied++;
            }
        } else {
            $mediaCopied++;
        }
    } else {
        echo "WARN missing staged media $objectKey\n";
    }

    if ($exists) {
        $assetSkipped++;
        continue;
    }
    if (!$apply) {
        $assetInserted++;
        continue;
    }
    $ins = $pdo->prepare(
        'INSERT INTO w_weline_file_asset
        (asset_id, disk_code, object_key, object_identity_hash, original_name, mime_type, bytes, sha256, width, height,
         default_locale, visibility, lifecycle_state, asset_revision, metadata, created_at, updated_at, create_time, update_time)
         VALUES
        (:asset_id, :disk_code, :object_key, :object_identity_hash, :original_name, :mime_type, :bytes, :sha256, :width, :height,
         :default_locale, :visibility, :lifecycle_state, :asset_revision, :metadata, NOW(), NOW(), NOW(), NOW())'
    );
    $meta = $row['metadata'] ?? null;
    if (is_array($meta)) {
        $meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
    }
    $ins->execute([
        ':asset_id' => $assetId,
        ':disk_code' => (string)($row['disk_code'] ?? 'local_media'),
        ':object_key' => $objectKey,
        ':object_identity_hash' => (string)($row['object_identity_hash'] ?? ''),
        ':original_name' => (string)($row['original_name'] ?? basename($objectKey)),
        ':mime_type' => (string)($row['mime_type'] ?? 'image/webp'),
        ':bytes' => (int)($row['bytes'] ?? 0),
        ':sha256' => (string)($row['sha256'] ?? ''),
        ':width' => (int)($row['width'] ?? 0),
        ':height' => (int)($row['height'] ?? 0),
        ':default_locale' => (string)($row['default_locale'] ?? 'zh_Hans_CN'),
        ':visibility' => (string)($row['visibility'] ?? 'public'),
        ':lifecycle_state' => (string)($row['lifecycle_state'] ?? 'active'),
        ':asset_revision' => (int)($row['asset_revision'] ?? 1),
        ':metadata' => $meta !== null && $meta !== '' ? (string)$meta : null,
    ]);
    $assetInserted++;
}
echo "assets insert/plan=$assetInserted skip_existing=$assetSkipped media_copy/plan=$mediaCopied\n";

// --- descriptions ---
$lines = file($descFile, FILE_IGNORE_NEW_LINES);
if ($offset > 0) {
    $lines = array_slice($lines, $offset);
}
if ($limit > 0) {
    $lines = array_slice($lines, 0, $limit);
}
echo 'description_rows=', count($lines), ' apply=', ($apply ? 'yes' : 'dry-run'), "\n";

$planOk = 0;
$planFail = 0;
$entities = [];
foreach ($lines as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        $planFail++;
        continue;
    }
    $html = (string)($row['html'] ?? '');
    $eid = (int)($row['entity_id'] ?? 0);
    if ($eid < 1 || $html === '' || !str_contains($html, 'data-weds="xq"')) {
        $planFail++;
        continue;
    }
    $planOk++;
    $entities[$eid] = true;
}
echo "plan_ok=$planOk plan_fail=$planFail unique_entities=", count($entities), "\n";

if (!$apply) {
    echo "dry-run only\n";
    exit(0);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
$written = 0;
$touched = [];
foreach ($lines as $i => $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }
    $eid = (int)$row['entity_id'];
    $locale = (string)$row['locale'];
    $html = (string)$row['html'];
    if ($eid < 1 || $html === '') {
        continue;
    }
    if ($locale !== '') {
        LocalDescription::upsertQuiet($eid, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $eid, 'description', $locale, $html, true);
    $written++;
    $touched[$eid] = true;
    if (($written % 200) === 0) {
        echo "progress written=$written\n";
    }
}
echo "wrote_description_rows=$written\n";

// Delete short stubs without data-weds for touched entities
$ids = array_keys($touched);
foreach (array_chunk($ids, 50) as $chunk) {
    $in = implode(',', array_map('intval', $chunk));
    $q = $pdo->query(
        "SELECT value_id, entity_id, locale, CHAR_LENGTH(value_text) AS len, value_text
         FROM w_product_ws_0_attribute_value
         WHERE attribute_code='description' AND store_id=0 AND entity_id IN ($in)"
    );
    $del = [];
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $v = (string)$r['value_text'];
        $len = (int)$r['len'];
        if (!str_contains($v, 'data-weds="xq"') || $len < 500) {
            $del[] = (int)$r['value_id'];
        }
    }
    if ($del !== []) {
        $pdo->exec('DELETE FROM w_product_ws_0_attribute_value WHERE value_id IN (' . implode(',', $del) . ')');
        echo 'deleted_short_stubs=', count($del), "\n";
    }
}

try {
    /** @var ProductStorefrontCacheInvalidator $inv */
    $inv = ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class);
    $inv->clearForCatalogChange('hanfu_detail_i18n_sync:' . count($touched));
    echo "cache clearForCatalogChange OK\n";
} catch (Throwable $e) {
    echo 'cache soft-fail: ' . $e->getMessage() . "\n";
}
try {
    /** @var StorefrontCatalogCacheCoordinator $coord */
    $coord = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
    $coord->notifyCatalogChanged($websiteId, 'hanfu_detail_i18n_sync', ['count' => count($touched)]);
} catch (Throwable $e) {
    echo 'notify soft-fail: ' . $e->getMessage() . "\n";
}

echo "DONE entities=", count($touched), "\n";
