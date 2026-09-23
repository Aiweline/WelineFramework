<?php

declare(strict_types=1);

/**
 * Upsert P3 sitewide CJK dictionary fills + publishLocale.
 *
 * Pack JSON shape:
 *   { "<zh word>": { "<locale>": "<translation>", ... }, ... }
 *
 * Usage:
 *   php -d memory_limit=1G app/code/Weline/I18n/scripts/remediate-dict-fill-sitewide-p3.php --pack=generated/i18n-p3/pack-a.json --dry-run
 *   php -d memory_limit=1G app/code/Weline/I18n/scripts/remediate-dict-fill-sitewide-p3.php --pack=generated/i18n-p3/pack-a.json --apply
 */

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\AiTranslationPublisher;
use Weline\I18n\Service\RuntimeCacheBroadcaster;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'pack:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$packPath = (string)($options['pack'] ?? '');
if ($packPath === '' || !is_file($packPath)) {
    fwrite(STDERR, "error: --pack=path/to.json required\n");
    exit(1);
}

$raw = file_get_contents($packPath);
$pack = json_decode((string)$raw, true);
if (!is_array($pack)) {
    fwrite(STDERR, "error: invalid JSON pack\n");
    exit(1);
}

$db = (array)(Env::getInstance()->getConfig('db')['master'] ?? []);
$host = (string)($db['hostname'] ?? '127.0.0.1');
$port = (string)($db['hostport'] ?? '5432');
$name = (string)($db['database'] ?? '');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
if ($name === '' || $user === '') {
    fwrite(STDERR, "error: missing db master config\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$localeRows = $pdo->query(
    "SELECT language_code FROM w_weline_websites_website_language WHERE website_id = 0 AND language_code <> 'zh_Hans_CN' ORDER BY language_code"
)->fetchAll(PDO::FETCH_COLUMN);
$allowed = array_fill_keys(array_map('strval', $localeRows ?: []), true);

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
$skipped = 0;
$missing = [];
$touchedLocales = [];
$han = '/\p{Han}/u';

foreach ($pack as $word => $byLocale) {
    $word = preg_replace('/^\xEF\xBB\xBF|\x{FEFF}/u', '', (string)$word) ?? (string)$word;
    if ($word === '' || !is_array($byLocale)) {
        continue;
    }
    ++$words;
    // Clean key + single leading U+FEFF (collect occasionally stores a BOM-prefixed word).
    $variants = [$word, "\u{FEFF}" . $word];
    foreach ($byLocale as $locale => $tr) {
        $locale = (string)$locale;
        $tr = trim((string)$tr);
        if ($locale === '' || $locale === 'zh_Hans_CN' || !isset($allowed[$locale])) {
            ++$skipped;
            continue;
        }
        if ($tr === '' || preg_match($han, $tr) || $tr === $word) {
            $missing[] = $locale . '|' . mb_substr($word, 0, 40);
            continue;
        }
        foreach ($variants as $variant) {
            if ($apply) {
                $stmt->execute([
                    ':md5' => md5($variant . $locale),
                    ':word' => $variant,
                    ':locale' => $locale,
                    ':translate' => $tr,
                ]);
                $touchedLocales[$locale] = true;
            }
            ++$writes;
        }
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
    'pack' => $packPath,
    'words' => $words,
    'writes' => $writes,
    'skipped' => $skipped,
    'missing_count' => count($missing),
    'missing_sample' => array_slice($missing, 0, 20),
    'touched_locales' => count($touchedLocales),
    'published_ok' => count(array_filter($published, static fn($v) => $v === true)),
    'published_fail' => array_filter($published, static fn($v) => $v !== true),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
