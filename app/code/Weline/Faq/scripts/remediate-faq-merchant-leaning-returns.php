<?php

declare(strict_types=1);

/**
 * Remediate FAQ retail returns (faq_key=returns) for default website locales.
 *
 * Usage:
 *   php -d memory_limit=512M app/code/Weline/Faq/scripts/remediate-faq-merchant-leaning-returns.php --dry-run
 *   php -d memory_limit=512M app/code/Weline/Faq/scripts/remediate-faq-merchant-leaning-returns.php --apply
 */

use Weline\Faq\Service\FaqResourceChangePublisher;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run']);
$apply = isset($options['apply']) && !isset($options['dry-run']);

/** @var array{returns: array<string, array{question:string, answer:string}>} $pack */
$pack = require __DIR__ . '/data/faq-merchant-leaning-returns.v1.php';

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

$meta = [
    'type_code' => 'template',
    'entity_uuid' => 'retail',
    'faq_key' => 'returns',
];

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
     SET question = :question, answer = :answer, status = :status, updated_at = :updated_at
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
    'returns' => ['updated' => 0, 'inserted' => 0, 'unchanged' => 0, 'missing_pack' => []],
    'publisher' => 'pending',
    'sample' => [],
];

$publishRows = [];

$selectTpl->execute([
    ':type' => $meta['type_code'],
    ':entity' => $meta['entity_uuid'],
    ':key' => $meta['faq_key'],
    ':prefer' => 'zh_Hans_CN',
]);
/** @var array<string, mixed>|false $template */
$template = $selectTpl->fetch(PDO::FETCH_ASSOC);
if (!$template) {
    fwrite(STDERR, "error: missing template row for returns\n");
    exit(1);
}

foreach ($locales as $locale) {
    $copy = $pack['returns'][$locale] ?? null;
    if (!is_array($copy) || trim((string)($copy['answer'] ?? '')) === '') {
        $summary['returns']['missing_pack'][] = $locale;
        continue;
    }
    $question = trim((string)($copy['question'] ?? ''));
    $answer = trim((string)$copy['answer']);
    if ($question === '') {
        $question = (string)($template['question'] ?? '');
    }

    $selectOne->execute([
        ':type' => $meta['type_code'],
        ':entity' => $meta['entity_uuid'],
        ':key' => $meta['faq_key'],
        ':locale' => $locale,
    ]);
    /** @var array<string, mixed>|false $existing */
    $existing = $selectOne->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $same = trim((string)($existing['question'] ?? '')) === $question
            && trim((string)($existing['answer'] ?? '')) === $answer
            && (string)($existing['status'] ?? '') === 'enabled';
        if ($same) {
            $summary['returns']['unchanged']++;
            continue;
        }
        if ($apply) {
            $updateStmt->execute([
                ':question' => $question,
                ':answer' => $answer,
                ':status' => 'enabled',
                ':updated_at' => $now,
                ':faq_id' => (int)$existing['faq_id'],
            ]);
            $row = $existing;
            $row['question'] = $question;
            $row['answer'] = $answer;
            $row['status'] = 'enabled';
            $row['updated_at'] = $now;
            $publishRows[] = $row;
        }
        $summary['returns']['updated']++;
        continue;
    }

    if ($apply) {
        $insertStmt->execute([
            ':store_code' => (string)($template['store_code'] ?? ''),
            ':channel_code' => (string)($template['channel_code'] ?? ''),
            ':locale' => $locale,
            ':type' => $meta['type_code'],
            ':entity' => $meta['entity_uuid'],
            ':key' => $meta['faq_key'],
            ':question' => $question,
            ':answer' => $answer,
            ':sort_order' => (int)($template['sort_order'] ?? 0),
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
            'type_code' => $meta['type_code'],
            'entity_uuid' => $meta['entity_uuid'],
            'faq_key' => $meta['faq_key'],
            'question' => $question,
            'answer' => $answer,
            'sort_order' => (int)($template['sort_order'] ?? 0),
            'status' => 'enabled',
        ];
    }
    $summary['returns']['inserted']++;
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
} else {
    $summary['publisher'] = 'dry-run';
}

if ($apply) {
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
}

$sampleLocales = ['zh_Hans_CN', 'en_US', 'ru_RU', 'de_DE', 'ar_SA'];
foreach ($sampleLocales as $locale) {
    $selectOne->execute([
        ':type' => $meta['type_code'],
        ':entity' => $meta['entity_uuid'],
        ':key' => $meta['faq_key'],
        ':locale' => $locale,
    ]);
    $row = $selectOne->fetch(PDO::FETCH_ASSOC);
    $packAnswer = (string)($pack['returns'][$locale]['answer'] ?? '');
    $summary['sample'][$locale] = [
        'exists' => (bool)$row,
        'faq_id' => $row ? (int)$row['faq_id'] : null,
        'answer_prefix' => $row
            ? mb_substr(trim((string)$row['answer']), 0, 64)
            : ($apply ? null : mb_substr($packAnswer, 0, 64) . ' (would write)'),
        'matches_pack' => $row ? trim((string)$row['answer']) === $packAnswer : false,
        'no_seven_fourteen_promo' => $row
            ? !preg_match('/七日无理由|十四日|seven-day|fourteen|14 days|семь|четырнадцать/ui', (string)$row['answer'])
            : null,
    ];
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
