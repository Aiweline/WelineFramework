<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\EditService;
use LearningMcp\ProcessRunner;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectIndexer;
use LearningMcp\ProjectResolver;
use LearningMcp\ProjectRetriever;
use LearningMcp\SparseVectorizer;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-edit-context-' . bin2hex(random_bytes(5));
$failures = [];
$checks = 0;
$check = static function (bool $passed, string $message) use (&$failures, &$checks): void {
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
    mkdir($root . '/src', 0700, true);
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        // Keep the prior index limit to cover already-installed configurations.
        'index' => ['sidecar_enabled' => false, 'max_file_bytes' => 524_288],
    ], JSON_THROW_ON_ERROR));
    $config = Config::load($temporary . '/config.json', $temporary . '/data');
    $index = new ProjectIndex($config, ProjectResolver::resolve($root));
    $indexer = new ProjectIndexer($index, $config);
    $edit = new EditService($index, $indexer, $config);
    $retriever = new ProjectRetriever($index, new SparseVectorizer($config), $config);
    $runner = new ProcessRunner();

    $path = 'src/Target.php';
    $source = "<?php\nclass Target {\n    public static function run(): void\n    {\n"
        . "        \$GLOBALS['events'][] = 'before';\n";
    for ($line = 0; $line < 150; ++$line) {
        $source .= '        // Padding ' . $line . ' ' . str_repeat('a', 55) . "\n";
    }
    $source .= "        \$GLOBALS['events'][] = 'TAIL_MUST_REMAIN';\n    }\n}\n";
    file_put_contents($root . '/' . $path, $source);
    $indexer->indexPaths([$path]);
    $task = 'Change the first event emitted by Target::run from before to after while preserving all other behavior.';
    $options = ['paths' => [$path], 'symbols' => ['Target::run'], 'include_skills' => false, 'include_docs' => false];
    $bundle = $retriever->getEditBundle($task, $options + ['token_budget' => 8_000]);
    $regions = array_values(array_filter(
        $bundle['exact_regions'],
        static fn (array $region): bool => ($region['symbol'] ?? '') === 'Target::run',
    ));
    $region = $regions[0] ?? [];
    $check(count($regions) === 1 && str_contains((string) ($region['content'] ?? ''), 'TAIL_MUST_REMAIN'),
        'requested long method includes its tail when the bundle budget can hold it');
    $check(($region['content_complete'] ?? false) === true && ($region['truncated'] ?? true) === false,
        'a complete symbol replacement region identifies its content as complete');
    $prepared = $edit->prepare([
        'schema_version' => 'edit-plan.v1',
        'project_id' => $index->projectId(),
        'project_revision' => $index->revision(),
        'operations' => [[
            'kind' => 'replace_symbol',
            'path' => $path,
            'symbol_uid' => $region['symbol_uid'],
            'expected_file_sha256' => $region['expected_file_sha256'],
            'expected_digest' => $region['expected_digest'],
            'replacement' => str_replace("'before'", "'after'", $region['content']),
        ]],
        'validation_profile' => 'default',
    ]);
    $edit->apply($prepared['apply_token'], $prepared['plan_digest'], true, true);
    $validation = $edit->validate(['edit_id' => $prepared['edit_id'], 'profile' => 'default']);
    $runtime = $runner->run([
        PHP_BINARY,
        '-r',
        "\$GLOBALS['events']=[]; require " . var_export($root . '/' . $path, true)
            . "; Target::run(); echo json_encode(\$GLOBALS['events']);",
    ], $root);
    $check($validation['status'] === 'passed' && $runtime['exit_code'] === 0
        && json_decode($runtime['stdout'], true) === ['after', 'TAIL_MUST_REMAIN'],
        'replacing the returned method changes the first runtime event and preserves the tail event');
    $edit->rollback($prepared['edit_id']);

    $smallBundle = $retriever->getEditBundle($task, $options + ['token_budget' => 256]);
    $smallRegions = array_values(array_filter(
        $smallBundle['exact_regions'],
        static fn (array $entry): bool => ($entry['symbol'] ?? '') === 'Target::run',
    ));
    $smallRegion = $smallRegions[0] ?? [];
    $check(($smallBundle['ready_for_edit'] ?? true) === false
        && ($smallRegion['truncated'] ?? false) === true
        && ($smallRegion['content_complete'] ?? true) === false
        && ($smallRegion['symbol_end_line'] ?? 0) > ($smallRegion['end_line'] ?? 0)
        && ($smallRegion['required_tokens'] ?? 0) > 256,
        'insufficient symbol budget exposes the omitted range and does not claim complete edit context');

    $boundaryPath = 'src/Boundary.php';
    $before = "<?php\nclass Boundary { public static function ok(): bool { return true; } }\n";
    file_put_contents($root . '/' . $boundaryPath, $before);
    $indexer->indexPaths([$boundaryPath]);
    $after = $before . '/*' . str_repeat('x', 530_000) . "*/\n";
    $preparedBoundary = $edit->prepare([
        'schema_version' => 'edit-plan.v1',
        'project_id' => $index->projectId(),
        'project_revision' => $index->revision(),
        'operations' => [[
            'kind' => 'replace_text', 'path' => $boundaryPath, 'search' => $before, 'replacement' => $after,
            'expected_file_sha256' => 'sha256:' . hash('sha256', $before),
        ]],
    ]);
    $edit->apply($preparedBoundary['apply_token'], $preparedBoundary['plan_digest'], true, true);
    $boundaryValidation = $edit->validate(['edit_id' => $preparedBoundary['edit_id'], 'profile' => 'default']);
    $refresh = $edit->refreshIndex($preparedBoundary['edit_id']);
    $hashQuery = $index->pdo()->prepare('SELECT content_hash FROM indexed_files WHERE path = ?');
    $hashQuery->execute([$boundaryPath]);
    $indexedHash = $hashQuery->fetchColumn();
    $hashQuery->closeCursor();
    $check($boundaryValidation['status'] === 'passed' && $refresh['status'] === 'completed'
        && $indexedHash === 'sha256:' . hash('sha256', $after),
        'a valid 530 KB edited file remains indexed at its actual postimage hash');
    $edit->rollback($preparedBoundary['edit_id']);

    // Exercise the real partial-index result: per-file SQLite failures are reported
    // by ProjectIndexer without throwing, and must not become completed edits.
    $index->pdo()->exec("CREATE TRIGGER reject_boundary_index BEFORE INSERT ON indexed_files
        WHEN NEW.path = 'src/Boundary.php' BEGIN SELECT RAISE(ABORT, 'fixture index write failure'); END");
    $preparedFailure = $edit->prepare([
        'schema_version' => 'edit-plan.v1',
        'project_id' => $index->projectId(),
        'project_revision' => $index->revision(),
        'operations' => [[
            'kind' => 'replace_text', 'path' => $boundaryPath, 'search' => 'return true;', 'replacement' => 'return false;',
            'expected_file_sha256' => 'sha256:' . hash('sha256', $before),
        ]],
    ]);
    $edit->apply($preparedFailure['apply_token'], $preparedFailure['plan_digest'], true, true);
    $failedRefresh = $edit->refreshIndex($preparedFailure['edit_id']);
    $failedStatus = $edit->status($preparedFailure['edit_id']);
    $check($failedRefresh['status'] === 'pending'
        && ($failedStatus['index_refresh']['status'] ?? '') === 'pending',
        'a partial index write keeps the edit index pending instead of reporting completed');
    $index->pdo()->exec('DROP TRIGGER reject_boundary_index');
    $recoveredRefresh = $edit->refreshIndex($preparedFailure['edit_id']);
    if ($recoveredRefresh['status'] !== 'completed') {
        fwrite(STDERR, json_encode($recoveredRefresh, JSON_UNESCAPED_SLASHES) . "\n");
    }
    $check($recoveredRefresh['status'] === 'completed', 'the same edit finishes indexing once the actual index write succeeds');
    $edit->rollback($preparedFailure['edit_id']);
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    unset($edit, $retriever, $indexer, $index);
    gc_collect_cycles();
    $removeTree($temporary);
}

fwrite(STDOUT, json_encode(['checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES) . "\n");
exit($failures === [] ? 0 : 1);
