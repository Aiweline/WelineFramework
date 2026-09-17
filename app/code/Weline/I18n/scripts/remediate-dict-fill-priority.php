<?php

declare(strict_types=1);

/**
 * Upsert priority dictionary fills for default-website enabled locales.
 *
 * Usage:
 *   php app/code/Weline/I18n/scripts/remediate-dict-fill-priority.php --dry-run
 *   php app/code/Weline/I18n/scripts/remediate-dict-fill-priority.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\RuntimeCacheBroadcaster;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run']);
$apply = isset($options['apply']) && !isset($options['dry-run']);

/** @var array<string, array<string, string>> $pack */
$pack = require __DIR__ . '/data/dict-fill-priority.v1.php';
$locales = ['en_US','ar_SA','bn_BD','es_ES','fr_FR','hi_IN','id_ID','pt_BR','ur_PK'];

/** @var LocaleDictionary $dictionary */
$dictionary = ObjectManager::getInstance(LocaleDictionary::class);

$writes = 0;
$words = 0;
foreach ($pack as $word => $byLocale) {
    $word = preg_replace('/^\xEF\xBB\xBF|\x{FEFF}/u', '', (string)$word) ?? (string)$word;
    if ($word === '') {
        continue;
    }
    ++$words;
    $variants = [$word, "\u{FEFF}" . $word];
    foreach ($locales as $locale) {
        $tr = trim((string)($byLocale[$locale] ?? ''));
        if ($tr === '') {
            continue;
        }
        foreach ($variants as $variant) {
            if ($apply) {
                $dictionary->upsert($variant, $locale, $tr);
            }
            ++$writes;
        }
    }
}

if ($apply) {
    try {
        ObjectManager::getInstance(RuntimeCacheBroadcaster::class)->broadcast();
    } catch (Throwable $e) {
        fwrite(STDERR, 'warn: broadcast: ' . $e->getMessage() . PHP_EOL);
    }
}

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'words' => $words,
    'writes' => $writes,
    'locales' => $locales,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
