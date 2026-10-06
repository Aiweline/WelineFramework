<?php

declare(strict_types=1);

/**
 * Upsert payment-guide hub subtitle + publishLocale.
 *
 * Usage:
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-payment-guide-hub-subtitle.php --dry-run
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-payment-guide-hub-subtitle.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\AiTranslationPublisher;
use Weline\I18n\Service\RuntimeCacheBroadcaster;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run']);
$apply = isset($options['apply']) && !isset($options['dry-run']);

/** @var array<string, array<string, string>> $pack */
$pack = require __DIR__ . '/data/dict-fill-payment-guide-hub-subtitle.v1.php';

/** @var WebsiteLanguage $websiteLanguage */
$websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
$localeRows = $websiteLanguage->getWebsiteLanguageCodes(Website::ID_DEFAULT);
$locales = array_values(array_filter(
    array_map('strval', $localeRows ?: []),
    static fn(string $code): bool => $code !== '' && $code !== 'zh_Hans_CN'
));

/** @var LocaleDictionary $dictionary */
$dictionary = ObjectManager::getInstance(LocaleDictionary::class);

$writes = 0;
$words = 0;
$missing = [];
$touchedLocales = [];
foreach ($pack as $word => $byLocale) {
    $word = preg_replace('/^\xEF\xBB\xBF|\x{FEFF}/u', '', (string)$word) ?? (string)$word;
    if ($word === '') {
        continue;
    }
    ++$words;
    foreach ($locales as $locale) {
        $tr = trim((string)($byLocale[$locale] ?? ''));
        if ($tr === '' || preg_match('/[\x{4E00}-\x{9FFF}]/u', $tr)) {
            $missing[] = $locale . '|' . mb_substr($word, 0, 40);
            continue;
        }
        if ($apply) {
            $dictionary->upsert($word, $locale, $tr);
            $touchedLocales[$locale] = true;
        }
        ++$writes;
    }
}

if ($apply && $touchedLocales !== []) {
    try {
        /** @var AiTranslationPublisher $publisher */
        $publisher = ObjectManager::getInstance(AiTranslationPublisher::class);
        foreach (array_keys($touchedLocales) as $locale) {
            $publisher->publishLocale((string)$locale);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'warn: publishLocale: ' . $e->getMessage() . PHP_EOL);
    }
    try {
        ObjectManager::getInstance(RuntimeCacheBroadcaster::class)->broadcast();
    } catch (Throwable $e) {
        fwrite(STDERR, 'warn: broadcast: ' . $e->getMessage() . PHP_EOL);
    }
}

$mode = $apply ? 'apply' : 'dry-run';
fwrite(STDOUT, "mode={$mode} words={$words} writes={$writes} missing=" . count($missing) . " locales=" . count($locales) . PHP_EOL);
if ($missing !== []) {
    fwrite(STDERR, "missing_sample:\n" . implode("\n", array_slice($missing, 0, 20)) . PHP_EOL);
    exit(2);
}
