<?php

declare(strict_types=1);

/**
 * Fill missing EAV Option LocalDescription rows by copying identity-safe labels.
 *
 * For ASCII/code-like options, every locale stores the same token.
 * For options that already have en_US / zh_Hans_CN, fill blanks from the best
 * available pivot (zh for zh_Hans_CN, else en_US, else source value).
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-eav-option-local-gaps.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-eav-option-local-gaps.php --apply
 */

require dirname(__DIR__, 5) . '/app/bootstrap.php';

use Weline\Eav\Service\LocalTranslation\EavLocalTranslationService;
use Weline\Framework\Manager\ObjectManager;

$apply = in_array('--apply', $argv, true);

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

$opts = $pdo->query("
    SELECT o.option_id, o.value AS source
    FROM w_eav_attribute_option o
    WHERE o.eav_entity_id = 4 AND COALESCE(o.value, '') <> ''
")->fetchAll(PDO::FETCH_ASSOC);

$localMap = [];
$st = $pdo->query('SELECT id, local_code, value FROM w_eav_attribute_option_local_description');
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $localMap[(int)$row['id']][$row['local_code']] = trim((string)$row['value']);
}

/** @var EavLocalTranslationService $locals */
$locals = ObjectManager::getInstance(EavLocalTranslationService::class);

$planned = 0;
$written = 0;
foreach ($opts as $opt) {
    $id = (int)$opt['option_id'];
    $source = trim((string)$opt['source']);
    $existing = $localMap[$id] ?? [];
    $zh = trim((string)($existing['zh_Hans_CN'] ?? ''));
    $en = trim((string)($existing['en_US'] ?? ''));
    foreach ($locales as $locale) {
        $stored = trim((string)($existing[$locale] ?? ''));
        if ($stored !== '') {
            continue;
        }
        if ($locale === 'zh_Hans_CN') {
            $fill = $zh !== '' ? $zh : $source;
        } elseif ($en !== '') {
            $fill = $en;
        } else {
            $fill = $source;
        }
        if ($fill === '') {
            continue;
        }
        $planned++;
        if (!$apply) {
            continue;
        }
        $locals->saveLocalizedField('option', $id, $fill, $locale);
        $written++;
    }
}

# Attribute name gaps: copy en_US or source name
$attrs = $pdo->query("
    SELECT attribute_id, name FROM w_eav_attribute WHERE eav_entity_id = 4
")->fetchAll(PDO::FETCH_ASSOC);
$attrLocals = [];
$st = $pdo->query('SELECT id, local_code, name FROM w_eav_attribute_local_description');
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $attrLocals[(int)$row['id']][$row['local_code']] = trim((string)$row['name']);
}
$attrPlanned = 0;
$attrWritten = 0;
foreach ($attrs as $attr) {
    $id = (int)$attr['attribute_id'];
    $source = trim((string)$attr['name']);
    $existing = $attrLocals[$id] ?? [];
    $en = trim((string)($existing['en_US'] ?? ''));
    $zh = trim((string)($existing['zh_Hans_CN'] ?? ''));
    foreach ($locales as $locale) {
        $stored = trim((string)($existing[$locale] ?? ''));
        if ($stored !== '') {
            continue;
        }
        if ($locale === 'zh_Hans_CN') {
            $fill = $zh !== '' ? $zh : $source;
        } elseif ($en !== '') {
            $fill = $en;
        } else {
            $fill = $source;
        }
        if ($fill === '') {
            continue;
        }
        $attrPlanned++;
        if (!$apply) {
            continue;
        }
        $locals->saveLocalizedField('attribute', $id, $fill, $locale);
        $attrWritten++;
    }
}

echo 'OPTION_GAPS_PLANNED=' . $planned . ' WRITTEN=' . $written . PHP_EOL;
echo 'ATTR_GAPS_PLANNED=' . $attrPlanned . ' WRITTEN=' . $attrWritten . PHP_EOL;
echo ($apply ? "applied\n" : "dry-run; pass --apply\n");
