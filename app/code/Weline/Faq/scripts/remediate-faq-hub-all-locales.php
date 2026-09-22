<?php

declare(strict_types=1);

/**
 * Remediate site hub FAQ (hub_0..hub_7) for every default-website language_code.
 *
 * Usage:
 *   php -d memory_limit=512M app/code/Weline/Faq/scripts/remediate-faq-hub-all-locales.php --dry-run
 *   php -d memory_limit=512M app/code/Weline/Faq/scripts/remediate-faq-hub-all-locales.php --apply
 */

use Weline\Faq\Service\FaqResourceChangePublisher;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run']);
$apply = isset($options['apply']) && !isset($options['dry-run']);

/** @var array<string, list<array{q:string, a:string}>> $pack */
$pack = require __DIR__ . '/data/faq-hub-all-locales.v1.php';

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
    'SELECT language_code FROM w_weline_websites_website_language WHERE website_id = 0 ORDER BY language_code'
)->fetchAll(PDO::FETCH_COLUMN);
$locales = array_values(array_filter(
    array_map('strval', $localeRows ?: []),
    static fn(string $code): bool => $code !== ''
));

$selectTpl = $pdo->prepare(
    'SELECT * FROM w_weline_faq_item
     WHERE website_id = 0 AND type_code = :type AND entity_uuid = :entity AND faq_key = :key
     ORDER BY CASE WHEN locale_code = :prefer THEN 0 ELSE 1 END, faq_id ASC
     LIMIT 1'
);
$selectOne = $pdo->prepare(
    'SELECT * FROM w_weline_faq_item
     WHERE website_id = 0 AND type_code = :type AND entity_uuid = :entity
       AND faq_key = :key AND locale_code = :locale
     LIMIT 1'
);
$updateStmt = $pdo->prepare(
    'UPDATE w_weline_faq_item
     SET question = :question, answer = :answer, status = :status, sort_order = :sort_order, updated_at = :updated_at
     WHERE faq_id = :faq_id'
);
$insertStmt = $pdo->prepare(
    'INSERT INTO w_weline_faq_item (
        website_id, store_code, channel_code, locale_code, type_code, entity_uuid,
        faq_key, question, answer, sort_order, status, created_at, updated_at
     ) VALUES (
        0, :store_code, :channel_code, :locale, :type, :entity,
        :key, :question, :answer, :sort_order, :status, :created_at, :updated_at
     ) RETURNING faq_id'
);

$now = date('Y-m-d H:i:s');
$summary = [
    'apply' => $apply,
    'locales_expected' => count($locales),
    'hubs' => [],
    'missing_pack_locales' => [],
    'publisher' => 'pending',
    'sample_ru_RU' => [],
];

for ($i = 0; $i < 8; $i++) {
    $summary['hubs']['hub_' . $i] = ['updated' => 0, 'inserted' => 0, 'unchanged' => 0, 'missing_pack' => []];
}

$publishRows = [];
$type = 'site';
$entity = 'site';

// Prefer hub_0 zh template for store/channel defaults; fall back to any site hub row.
$selectTpl->execute([
    ':type' => $type,
    ':entity' => $entity,
    ':key' => 'hub_0',
    ':prefer' => 'zh_Hans_CN',
]);
/** @var array<string, mixed>|false $template */
$template = $selectTpl->fetch(PDO::FETCH_ASSOC);
if (!$template) {
    $selectTpl->execute([
        ':type' => $type,
        ':entity' => $entity,
        ':key' => 'hub_1',
        ':prefer' => 'zh_Hans_CN',
    ]);
    $template = $selectTpl->fetch(PDO::FETCH_ASSOC);
}
if (!$template) {
    fwrite(STDERR, "error: missing site hub template row\n");
    exit(1);
}

foreach ($locales as $locale) {
    if (!isset($pack[$locale]) || !is_array($pack[$locale]) || count($pack[$locale]) < 8) {
        $summary['missing_pack_locales'][] = $locale;
        continue;
    }
    for ($i = 0; $i < 8; $i++) {
        $hubKey = 'hub_' . $i;
        $item = $pack[$locale][$i] ?? null;
        if (!is_array($item) || trim((string)($item['a'] ?? '')) === '') {
            $summary['hubs'][$hubKey]['missing_pack'][] = $locale;
            continue;
        }
        $question = trim((string)($item['q'] ?? ''));
        $answer = trim((string)$item['a']);
        if ($question === '') {
            $summary['hubs'][$hubKey]['missing_pack'][] = $locale;
            continue;
        }

        $selectOne->execute([
            ':type' => $type,
            ':entity' => $entity,
            ':key' => $hubKey,
            ':locale' => $locale,
        ]);
        /** @var array<string, mixed>|false $existing */
        $existing = $selectOne->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $same = trim((string)($existing['question'] ?? '')) === $question
                && trim((string)($existing['answer'] ?? '')) === $answer
                && (string)($existing['status'] ?? '') === 'enabled'
                && (int)($existing['sort_order'] ?? -1) === $i;
            if ($same) {
                $summary['hubs'][$hubKey]['unchanged']++;
                continue;
            }
            if ($apply) {
                $updateStmt->execute([
                    ':question' => $question,
                    ':answer' => $answer,
                    ':status' => 'enabled',
                    ':sort_order' => $i,
                    ':updated_at' => $now,
                    ':faq_id' => (int)$existing['faq_id'],
                ]);
                $row = $existing;
                $row['question'] = $question;
                $row['answer'] = $answer;
                $row['status'] = 'enabled';
                $row['sort_order'] = $i;
                $row['updated_at'] = $now;
                $publishRows[] = $row;
            }
            $summary['hubs'][$hubKey]['updated']++;
            continue;
        }

        if ($apply) {
            $insertStmt->execute([
                ':store_code' => (string)($template['store_code'] ?? ''),
                ':channel_code' => (string)($template['channel_code'] ?? ''),
                ':locale' => $locale,
                ':type' => $type,
                ':entity' => $entity,
                ':key' => $hubKey,
                ':question' => $question,
                ':answer' => $answer,
                ':sort_order' => $i,
                ':status' => 'enabled',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $faqId = (int)$insertStmt->fetchColumn();
            $publishRows[] = [
                'faq_id' => $faqId,
                'website_id' => 0,
                'store_code' => (string)($template['store_code'] ?? ''),
                'channel_code' => (string)($template['channel_code'] ?? ''),
                'locale_code' => $locale,
                'type_code' => $type,
                'entity_uuid' => $entity,
                'faq_key' => $hubKey,
                'question' => $question,
                'answer' => $answer,
                'sort_order' => $i,
                'status' => 'enabled',
            ];
        }
        $summary['hubs'][$hubKey]['inserted']++;
    }
}

if ($apply) {
    $pdo = null;

    $published = 0;
    $publishErrors = [];
    try {
        /** @var FaqResourceChangePublisher $publisher */
        $publisher = ObjectManager::getInstance(FaqResourceChangePublisher::class);
        foreach ($publishRows as $row) {
            try {
                $publisher->publish($row, 'upsert');
                ++$published;
            } catch (Throwable $e) {
                $publishErrors[] = $e->getMessage();
            }
        }
        $summary['publisher'] = [
            'class' => FaqResourceChangePublisher::class,
            'published' => $published,
            'errors' => array_slice(array_values(array_unique($publishErrors)), 0, 5),
        ];
    } catch (Throwable $e) {
        $summary['publisher'] = [
            'skipped' => true,
            'reason' => $e->getMessage(),
        ];
    }

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $selectOne = $pdo->prepare(
        'SELECT * FROM w_weline_faq_item
         WHERE website_id = 0 AND type_code = :type AND entity_uuid = :entity
           AND faq_key = :key AND locale_code = :locale
         LIMIT 1'
    );
} else {
    $summary['publisher'] = 'dry-run';
}

for ($i = 0; $i < 8; $i++) {
    $hubKey = 'hub_' . $i;
    $selectOne->execute([
        ':type' => $type,
        ':entity' => $entity,
        ':key' => $hubKey,
        ':locale' => 'ru_RU',
    ]);
    $row = $selectOne->fetch(PDO::FETCH_ASSOC);
    $packQ = (string)($pack['ru_RU'][$i]['q'] ?? '');
    $packA = (string)($pack['ru_RU'][$i]['a'] ?? '');
    $summary['sample_ru_RU'][$hubKey] = [
        'exists' => (bool)$row,
        'faq_id' => $row ? (int)$row['faq_id'] : null,
        'question' => $row ? (string)$row['question'] : ($apply ? null : $packQ . ' (would write)'),
        'answer_prefix' => $row
            ? mb_substr(trim((string)$row['answer']), 0, 64)
            : ($apply ? null : mb_substr($packA, 0, 64) . ' (would write)'),
        'matches_pack' => $row
            ? trim((string)$row['question']) === $packQ && trim((string)$row['answer']) === $packA
            : false,
        'not_english_how_soon' => $row
            ? !str_contains((string)$row['question'], 'How soon') && !str_contains((string)$row['answer'], 'In-stock orders')
            : null,
    ];
}

$totals = ['updated' => 0, 'inserted' => 0, 'unchanged' => 0];
foreach ($summary['hubs'] as $stats) {
    $totals['updated'] += $stats['updated'];
    $totals['inserted'] += $stats['inserted'];
    $totals['unchanged'] += $stats['unchanged'];
}
$summary['totals'] = $totals;

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
