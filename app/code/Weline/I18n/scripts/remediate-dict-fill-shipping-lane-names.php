<?php

declare(strict_types=1);

/**
 * Upsert seed shipping lane name dictionary fills + publishLocale.
 *
 * Usage:
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-shipping-lane-names.php --dry-run
 *   php -d memory_limit=512M app/code/Weline/I18n/scripts/remediate-dict-fill-shipping-lane-names.php --apply
 */

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\AiTranslationPublisher;
use Weline\I18n\Service\RuntimeCacheBroadcaster;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run']);
$apply = isset($options['apply']) && !isset($options['dry-run']);

/** @var array<string, array<string, string>> $pack */
$pack = require __DIR__ . '/data/dict-fill-shipping-lane-names.v1.php';

$db = (array)(Env::getInstance()->getConfig('db')['master'] ?? []);
$host = (string)($db['hostname'] ?? '127.0.0.1');
$port = (string)($db['hostport'] ?? '');
$name = (string)($db['database'] ?? '');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
$prefix = (string)($db['prefix'] ?? 'w_');
$type = strtolower((string)($db['type'] ?? 'pgsql'));
$isMysql = str_contains($type, 'mysql');
if ($port === '') {
    $port = $isMysql ? '3306' : '5432';
}
if ($name === '' || $user === '') {
    fwrite(STDERR, "error: missing db master config\n");
    exit(1);
}

$dsn = $isMysql
    ? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name)
    : sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$langTable = $prefix . 'weline_websites_website_language';
$dictTable = $prefix . 'i18n_locale_dictionary';
$localeRows = $pdo->query(
    "SELECT language_code FROM {$langTable} WHERE website_id = 0 ORDER BY language_code"
)->fetchAll(PDO::FETCH_COLUMN);
$locales = array_values(array_filter(
    array_map('strval', $localeRows ?: []),
    static fn(string $code): bool => $code !== '' && $code !== 'zh_Hans_CN'
));

$sql = $isMysql
    ? <<<SQL
INSERT INTO {$dictTable} (md5, word, locale_code, translate, is_ai)
VALUES (:md5, :word, :locale, :translate, 0)
ON DUPLICATE KEY UPDATE
  word = VALUES(word),
  locale_code = VALUES(locale_code),
  translate = VALUES(translate)
SQL
    : <<<SQL
INSERT INTO {$dictTable} (md5, word, locale_code, translate, is_ai)
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
            $stmt->execute([
                ':md5' => md5($word . $locale),
                ':word' => $word,
                ':locale' => $locale,
                ':translate' => $tr,
            ]);
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
