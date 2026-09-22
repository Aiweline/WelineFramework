<?php

declare(strict_types=1);

/**
 * Upsert backend menu chrome dictionary fills (website_id=0 locales) + publishLocale.
 *
 * Usage:
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-backend-menu-chrome.php --dry-run
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-backend-menu-chrome.php --apply
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
$pack = require __DIR__ . '/data/dict-fill-backend-menu-chrome.v1.php';

/** @var WebsiteLanguage $websiteLanguage */
$websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
$locales = array_values(array_filter(
    array_map('strval', $websiteLanguage->getWebsiteLanguageCodes(Website::ID_DEFAULT)),
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

$published = [];
if ($apply) {
    /** @var AiTranslationPublisher $publisher */
    $publisher = ObjectManager::getInstance(AiTranslationPublisher::class);
    foreach (array_keys($touchedLocales) as $localeCode) {
        try {
            $published[$localeCode] = $publisher->publishLocale($localeCode);
        } catch (Throwable $e) {
            $published[$localeCode] = 'error:' . $e->getMessage();
        }
    }
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
    'missing_count' => count($missing),
    'missing_sample' => array_slice($missing, 0, 20),
    'published' => $published,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
