<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\Json;
use LearningMcp\ProjectResolver;
use LearningMcp\Store;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-event-storage-' . bin2hex(random_bytes(5));
$failures = [];
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$passed) {
        $failures[] = $message;
    }
    fwrite($passed ? STDOUT : STDERR, ($passed ? '[PASS] ' : '[FAIL] ') . $message . "\n");
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
};

try {
    $root = $temporary . '/project';
    mkdir($root, 0700, true);
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        'index' => ['sidecar_enabled' => false],
        'collector' => ['stored_event_chars' => 256],
    ], JSON_THROW_ON_ERROR));
    $config = Config::load($temporary . '/config.json', $temporary . '/data');
    $check((int) $config->get('collector.stored_event_chars') === 256, 'stored_event_chars is honored from config');
    $store = new Store($config);
    $resolved = ProjectResolver::resolve($root);
    $project = $resolved['project'];
    $project['repository'] = $resolved['repository'];
    $store->upsertProject($project);
    $sessionId = 'session:event-storage';
    $store->upsertSession([
        'id' => $sessionId,
        'project_id' => $project['id'],
        'cwd' => $root,
        'worktree' => $root,
        'status' => 'active',
    ]);
    $blob = str_repeat('tool-output-', 4_000);
    $skipped = $store->insertEvent([
        'event_id' => 'event:huge-tool',
        'project_id' => $project['id'],
        'session_id' => $sessionId,
        'type' => 'tool_result',
        'content_redacted' => $blob,
        'content_hash' => hash('sha256', $blob),
        'dedup_key' => 'huge-tool',
        'trust' => ['class' => 'tool', 'score' => 0.5],
        'metadata' => [
            'hook_event_name' => 'PostToolUse',
            'tool_name' => 'Shell',
            'tool_input' => ['command' => 'ssh prod'],
            'tool_result' => $blob,
        ],
    ]);
    $check(($skipped['inserted'] ?? true) === false, 'tool_result is not inserted');
    $check(($skipped['skip_reason'] ?? '') === 'event_type_not_persisted', 'tool_result skip_reason is event_type_not_persisted');
    $toolRows = (int) $store->database()->query(
        "SELECT COUNT(*) FROM events WHERE id = 'event:huge-tool' OR event_type = 'tool_result'",
    )->fetchColumn();
    $check($toolRows === 0, 'tool firehose never becomes a durable event row');

    $prompt = str_repeat('user-rule-', 400);
    $store->insertEvent([
        'event_id' => 'event:user-prompt',
        'project_id' => $project['id'],
        'session_id' => $sessionId,
        'type' => 'user_message',
        'content_redacted' => $prompt,
        'content_hash' => hash('sha256', $prompt),
        'dedup_key' => 'user-prompt',
        'trust' => ['class' => 'user', 'score' => 1.0],
        'metadata' => [
            'hook_event_name' => 'beforeSubmitPrompt',
            'prompt' => $prompt,
        ],
    ]);
    $row = $store->database()->query(
        "SELECT content_redacted, metadata_json, event_type FROM events WHERE id = 'event:user-prompt'",
    )->fetch(PDO::FETCH_ASSOC);
    $content = (string) ($row['content_redacted'] ?? '');
    $metadata = Json::decode((string) ($row['metadata_json'] ?? '{}'), []);
    $check(($row['event_type'] ?? '') === 'user_message', 'user_message remains the extraction feedstock');
    $check(mb_strlen($content, 'UTF-8') <= 257, 'persisted user_message is truncated to stored_event_chars');
    $check(!isset($metadata['prompt']), 'persisted metadata does not keep the full prompt copy');
    $check(($metadata['content_truncated'] ?? false) === true, 'compact metadata records content_truncated');
    $check((int) ($metadata['original_content_bytes'] ?? 0) === strlen($prompt), 'compact metadata records original_content_bytes');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    if (isset($store)) {
        $store->close();
    }
    $removeTree($temporary);
}

fwrite(STDOUT, json_encode(['checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES) . "\n");
exit($failures === [] ? 0 : 1);
