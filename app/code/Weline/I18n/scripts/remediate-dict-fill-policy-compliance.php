<?php

declare(strict_types=1);

/**
 * Upsert storefront policy-compliance dictionary fills (direct SQL, single broadcast).
 *
 * Usage:
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-policy-compliance.php --dry-run
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-policy-compliance.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\RuntimeCacheBroadcaster;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run']);
$apply = isset($options['apply']) && !isset($options['dry-run']);

/** @var array<string, array<string, string>> $pack */
$pack = require __DIR__ . '/data/dict-fill-policy-compliance.v1.php';

$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=mig_clone_productcurrent20260810_20260810022347_e07e',
    'weline',
    'weline',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$localeRows = $pdo->query(
    'SELECT language_code FROM w_weline_websites_website_language WHERE website_id = 0 ORDER BY language_code'
)->fetchAll(PDO::FETCH_COLUMN);
$locales = array_values(array_filter(
    array_map('strval', $localeRows ?: []),
    static fn(string $code): bool => $code !== '' && $code !== 'zh_Hans_CN'
));

$sql = <<<'SQL'
INSERT INTO w_i18n_locale_dictionary (md5, word, locale_code, translate, is_ai)
VALUES (:md5, :word, :locale, :translate, 0)
ON CONFLICT (md5) DO UPDATE SET
  word = EXCLUDED.word,
  locale_code = EXCLUDED.locale_code,
  translate = EXCLUDED.translate
SQL;
$stmt = $pdo->prepare($sql);

$writes = 0;
$words = 0;
$missing = [];
foreach ($pack as $word => $byLocale) {
    $word = preg_replace('/^\xEF\xBB\xBF|\x{FEFF}/u', '', (string)$word) ?? (string)$word;
    if ($word === '') {
        continue;
    }
    ++$words;
    foreach ($locales as $locale) {
        $tr = trim((string)($byLocale[$locale] ?? ''));
        // Only reject CJK Unified Ideographs left as translate (not \p{Han}: it false-positives on ·).
        if ($tr === '' || preg_match('/[\x{4E00}-\x{9FFF}]/u', $tr)) {
            $missing[] = $locale . '|' . mb_substr($word, 0, 40);
            continue;
        }
        if ($apply) {
            $stmt->execute([
                ':md5' => md5($word . $locale),
                ':word' => $word,
                ':locale' => $locale,
                ':translate' => $tr,
            ]);
        }
        ++$writes;
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
    'apply' => $apply,
    'words' => $words,
    'writes' => $writes,
    'locales' => count($locales),
    'missing' => count($missing),
    'missing_sample' => array_slice($missing, 0, 20),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
