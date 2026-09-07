<?php

declare(strict_types=1);

/**
 * Backfill Product catalog EAV Attribute/Option en_US LocalDescription from curated map.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-eav-en-locals.php
 *   php app/code/Weline/Product/scripts/remediate-eav-en-locals.php --force
 *   php app/code/Weline/Product/scripts/remediate-eav-en-locals.php --dry-run
 */

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Service\LocalTranslation\EavLocalTranslationService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$force = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

/** @var array{attributes:array<string,string>,options:array<string,string>} $map */
$map = require dirname(__DIR__) . '/data/hanfu-eav-en-locals.php';
$attributeMap = $map['attributes'] ?? [];
$optionMap = $map['options'] ?? [];

$entityId = 4; // product
$locale = 'en_US';

/** @var EavLocalTranslationService $locals */
$locals = ObjectManager::getInstance(EavLocalTranslationService::class);
/** @var EavAttribute $attributeModel */
$attributeModel = ObjectManager::getInstance(EavAttribute::class);
/** @var Option $optionModel */
$optionModel = ObjectManager::getInstance(Option::class);

$attrUpdated = 0;
$attrSkipped = 0;
$optUpdated = 0;
$optSkipped = 0;
$optUnmapped = 0;

$attributes = (clone $attributeModel)
    ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
    ->select()
    ->fetchArray();

foreach ($attributes as $row) {
    $id = (int)($row[EavAttribute::schema_fields_ID] ?? 0);
    $code = strtolower(trim((string)($row[EavAttribute::schema_fields_code] ?? '')));
    if ($id <= 0 || $code === '' || !isset($attributeMap[$code])) {
        continue;
    }
    $en = trim((string)$attributeMap[$code]);
    if ($en === '') {
        continue;
    }
    if (!$force) {
        $existing = (clone ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\LocalDescription::class))
            ->reset()
            ->where('id', $id)
            ->where('local_code', $locale)
            ->select()
            ->fetchArray();
        $stored = trim((string)($existing[0]['name'] ?? ''));
        if ($stored !== '' && $stored !== trim((string)($row[EavAttribute::schema_fields_name] ?? ''))) {
            $attrSkipped++;
            continue;
        }
    }
    if ($dryRun) {
        echo "[dry-run] attribute #{$id} {$code} => {$en}\n";
        $attrUpdated++;
        continue;
    }
    $locals->saveLocalizedField('attribute', $id, $en, $locale);
    $attrUpdated++;
}

$options = (clone $optionModel)
    ->where(Option::schema_fields_eav_entity_id, $entityId)
    ->select()
    ->fetchArray();

foreach ($options as $row) {
    $id = (int)($row[Option::schema_fields_ID] ?? 0);
    $value = trim((string)($row[Option::schema_fields_value] ?? ''));
    if ($id <= 0 || $value === '') {
        continue;
    }
    if (!isset($optionMap[$value])) {
        if (preg_match('/\p{Han}/u', $value) === 1) {
            $optUnmapped++;
        }
        continue;
    }
    $en = trim((string)$optionMap[$value]);
    if ($en === '') {
        continue;
    }
    if (!$force) {
        $existing = (clone ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Option\LocalDescription::class))
            ->reset()
            ->where('id', $id)
            ->where('local_code', $locale)
            ->select()
            ->fetchArray();
        $stored = trim((string)($existing[0]['value'] ?? ''));
        if ($stored !== '' && $stored !== $value) {
            $optSkipped++;
            continue;
        }
    }
    if ($dryRun) {
        echo "[dry-run] option #{$id} {$value} => {$en}\n";
        $optUpdated++;
        continue;
    }
    $locals->saveLocalizedField('option', $id, $en, $locale);
    $optUpdated++;
}

try {
    $cache = w_cache('eav');
    if (method_exists($cache, 'clear')) {
        $cache->clear();
    } elseif (method_exists($cache, 'flush')) {
        $cache->flush();
    }
} catch (Throwable) {
    // best-effort; storefront may need server:reload
}

echo json_encode([
    'dry_run' => $dryRun,
    'force' => $force,
    'attributes_updated' => $attrUpdated,
    'attributes_skipped' => $attrSkipped,
    'options_updated' => $optUpdated,
    'options_skipped' => $optSkipped,
    'options_unmapped_han' => $optUnmapped,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
