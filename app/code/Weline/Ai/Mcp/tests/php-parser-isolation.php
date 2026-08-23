<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\ProcessRunner;
use LearningMcp\PhpSymbolParser;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectIndexer;
use LearningMcp\ProjectResolver;

require dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-parser-isolation-' . bin2hex(random_bytes(5));
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $removeTree($path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }
    @rmdir($path);
};

try {
    mkdir($temporary . '/project/src', 0700, true);
    $configPath = $temporary . '/config.json';
    file_put_contents($configPath, json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        'index' => ['max_file_bytes' => 8_388_608],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $config = Config::load($configPath);
    $sourcePath = $temporary . '/project/src/Large.php';
    $runner = new ProcessRunner();

    $pathSensitiveSource = '<?php namespace Isolation; function worker_path(): void {}';
    $pathSensitiveName = ' src/Leading.php';
    $directPathSensitive = (new PhpSymbolParser())->parse($pathSensitiveSource, $pathSensitiveName);
    $workerPathSensitive = $runner->run(
        [PHP_BINARY, dirname(__DIR__) . '/bin/php-symbol-parser-worker', $pathSensitiveName],
        dirname(__DIR__),
        $pathSensitiveSource,
        10,
    );
    $workerPathSensitiveParsed = json_decode($workerPathSensitive['stdout'], true);
    if ($workerPathSensitive['exit_code'] !== 0 || $workerPathSensitiveParsed !== $directPathSensitive) {
        throw new RuntimeException('Parser worker did not preserve exact path-sensitive parser output');
    }

    $nearThresholdPrefix = "<?php\nnamespace Isolation;\nfinal class NearThreshold { public function run(): int {\n";
    $nearThresholdSuffix = "return 1;\n}}\nfunction f(): void {}\n";
    $nearThresholdTargetBytes = 65_535;
    $nearThresholdCalls = intdiv(
        $nearThresholdTargetBytes - strlen($nearThresholdPrefix) - strlen($nearThresholdSuffix),
        5,
    );
    $nearThreshold = $nearThresholdPrefix . str_repeat("f();\n", $nearThresholdCalls);
    $nearThreshold .= str_repeat(
        ' ',
        $nearThresholdTargetBytes - strlen($nearThreshold) - strlen($nearThresholdSuffix),
    ) . $nearThresholdSuffix;
    if (strlen($nearThreshold) !== $nearThresholdTargetBytes) {
        throw new RuntimeException('Near-threshold fixture has an unexpected size');
    }
    file_put_contents($temporary . '/project/src/NearThreshold.php', $nearThreshold);

    $baseline = "<?php\nnamespace Isolation;\nfinal class Large { public function stable(): bool { return true; } }\n"
        . '/*' . str_repeat('isolated-worker-success-', 30_000) . '*/';
    if (strlen($baseline) < 524_288) {
        throw new RuntimeException('Baseline fixture does not exercise the parser worker');
    }
    file_put_contents($sourcePath, $baseline);

    $index = new ProjectIndex($config, ProjectResolver::resolve($temporary . '/project'));
    $indexer = new ProjectIndexer($index, $config, $runner);
    $nearThresholdResult = $indexer->indexPaths(['src/NearThreshold.php']);
    $nearThresholdSymbol = $index->pdo()->query(
        "SELECT fq_name FROM symbols WHERE fq_name = 'Isolation\\NearThreshold::run' LIMIT 1"
    )->fetchColumn();
    if (($nearThresholdResult['freshness'] ?? null) !== 'current'
        || $nearThresholdSymbol !== 'Isolation\\NearThreshold::run') {
        throw new RuntimeException('Near-threshold adversarial PHP did not parse safely inline');
    }
    $initial = $indexer->indexPaths(['src/Large.php']);
    if (($initial['freshness'] ?? null) !== 'current') {
        throw new RuntimeException('Baseline file did not index successfully');
    }
    $baselineSymbol = $index->pdo()->query(
        "SELECT fq_name, kind FROM symbols WHERE fq_name = 'Isolation\\Large::stable' LIMIT 1"
    )->fetch();
    if (!is_array($baselineSymbol)
        || ($baselineSymbol['fq_name'] ?? null) !== 'Isolation\\Large::stable'
        || ($baselineSymbol['kind'] ?? null) !== 'method') {
        throw new RuntimeException('Baseline worker parse did not preserve the expected method symbol');
    }
    $before = $index->pdo()->query(
        "SELECT content_hash FROM indexed_files WHERE path = 'src/Large.php'"
    )->fetchColumn();
    if (!is_string($before) || $before === '') {
        throw new RuntimeException('Baseline file hash is unavailable');
    }

    $resourceBomb = "<?php\nnamespace Isolation;\nfinal class Large { public function stable(): int {\n"
        . str_repeat("f();\n", 100_000)
        . "return 1;\n}}\nfunction f(): void {}\n";
    if (strlen($resourceBomb) >= 524_288) {
        throw new RuntimeException('Resource fixture must stay below the former isolation threshold');
    }
    file_put_contents($sourcePath, $resourceBomb);
    $result = $indexer->indexPaths(['src/Large.php']);
    $state = $index->state();
    $after = $index->pdo()->query(
        "SELECT content_hash FROM indexed_files WHERE path = 'src/Large.php'"
    )->fetchColumn();

    if (($result['freshness'] ?? null) !== 'partial') {
        throw new RuntimeException('Parser resource failure did not return partial freshness');
    }
    if (($state['phase'] ?? null) !== 'idle') {
        throw new RuntimeException('Parser resource failure stranded the index outside idle');
    }
    if (!str_contains(implode("\n", $result['errors'] ?? []), 'src/Large.php')) {
        throw new RuntimeException('Parser resource failure omitted the affected path');
    }
    if (!hash_equals($before, (string) $after)) {
        throw new RuntimeException('Parser resource failure replaced the last valid indexed revision');
    }

    $largeOutputSource = "<?php\nnamespace Isolation;\nfinal class LargeOutput { public function run(): int {\n"
        . str_repeat("f();\n", 50_000)
        . "return 1;\n}}\nfunction f(): void {}\n";
    file_put_contents($sourcePath, $largeOutputSource);
    $largeOutput = $indexer->indexPaths(['src/Large.php']);
    $largeOutputState = $index->state();
    $largeOutputAfter = $index->pdo()->query(
        "SELECT content_hash FROM indexed_files WHERE path = 'src/Large.php'"
    )->fetchColumn();
    if (($largeOutput['freshness'] ?? null) !== 'partial'
        || ($largeOutputState['phase'] ?? null) !== 'idle'
        || !str_contains(implode("\n", $largeOutput['errors'] ?? []), 'bounded')
        || !hash_equals($before, (string) $largeOutputAfter)) {
        throw new RuntimeException('Truncated parser output was not rejected before parent JSON decoding');
    }

    file_put_contents($sourcePath, $resourceBomb);

    $index->close();
    unset($indexer, $index);

    $protocolInput = implode("\n", [
        json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'parser-isolation-test', 'version' => '1'],
            ],
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_edit_bundle',
                'arguments' => [
                    'repository' => $temporary . '/project',
                    'task' => 'Read the exact parser isolation fixture without modifying it.',
                    'paths' => ['src/Large.php'],
                    'include_docs' => false,
                    'include_skills' => false,
                    'max_regions' => 4,
                    'max_chunks_per_file' => 1,
                    'token_budget' => 2_000,
                    'task_contract' => [
                        'goal' => 'Verify parser failure isolation at the MCP transport boundary.',
                        'known_paths' => ['src/Large.php'],
                        'requirements' => ['Read only.'],
                        'allowed_scope' => ['src/Large.php'],
                        'forbidden_scope' => ['All writes.'],
                        'authorized_actions' => ['Read-only indexing.'],
                        'acceptance_criteria' => ['The MCP process remains available.'],
                        'validation_expectations' => ['A subsequent resources/read succeeds.'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/read',
            'params' => ['uri' => 'ui://weline/execution-run-v1.html'],
        ], JSON_THROW_ON_ERROR),
    ]) . "\n";
    $protocol = $runner->run(
        [
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/bin/learning-mcp',
            '--config',
            $configPath,
        ],
        dirname(__DIR__),
        $protocolInput,
        60,
    );
    if ($protocol['exit_code'] !== 0) {
        throw new RuntimeException('MCP process did not survive parser resource failure: ' . $protocol['stderr']);
    }
    $responses = [];
    foreach (explode("\n", trim($protocol['stdout'])) as $line) {
        $response = json_decode($line, true);
        if (is_array($response) && isset($response['id'])) {
            $responses[(int) $response['id']] = $response;
        }
    }
    if (!isset($responses[2])) {
        throw new RuntimeException('MCP tool call returned no response before resources/read');
    }
    $resourceHtml = $responses[3]['result']['contents'][0]['text'] ?? '';
    if (!is_string($resourceHtml) || !str_contains($resourceHtml, '<!doctype html>')) {
        throw new RuntimeException('MCP resources/read failed after parser resource failure');
    }

    fwrite(STDOUT, json_encode([
        'baseline_bytes' => strlen($baseline),
        'baseline_symbol' => (string) $baselineSymbol['fq_name'],
        'near_threshold_bytes' => strlen($nearThreshold),
        'near_threshold_symbol' => (string) $nearThresholdSymbol,
        'path_sensitive_worker_match' => true,
        'source_bytes' => strlen($resourceBomb),
        'large_output_bytes' => strlen($largeOutputSource),
        'truncated_output_rejected' => true,
        'freshness' => $result['freshness'],
        'phase' => $state['phase'],
        'errors' => count($result['errors'] ?? []),
        'previous_hash_retained' => true,
        'transport_survived' => true,
        'resource_bytes' => strlen($resourceHtml),
        'peak_bytes' => memory_get_peak_usage(true),
    ], JSON_THROW_ON_ERROR) . "\n");
} finally {
    if (isset($index) && $index instanceof ProjectIndex) {
        $index->close();
    }
    $removeTree($temporary);
}
