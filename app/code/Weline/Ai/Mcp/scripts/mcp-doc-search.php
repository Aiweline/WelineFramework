<?php

declare(strict_types=1);

/**
 * Query the Weline project intelligence MCP from a single PHP process.
 *
 * The STDIO MCP keeps its readiness token in memory, so `learningctl intelligence`
 * cannot chain prepare_project + knowledge tools across two executions. This helper
 * prepares the project and runs one knowledge search in the same process.
 *
 * Usage:
 *   php app/code/Weline/Ai/Mcp/scripts/mcp-doc-search.php "主题 Token 语义色" [limit]
 *   php app/code/Weline/Ai/Mcp/scripts/mcp-doc-search.php --task "给 Checkout 增加字段" [limit]
 */

require __DIR__ . '/../src/bootstrap.php';

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\Store;
use LearningMcp\ToolService;

$arguments = array_slice($argv, 1);
$mode = 'search_project_knowledge';
if (($arguments[0] ?? '') === '--task') {
    $mode = 'resolve_task_context';
    array_shift($arguments);
}
$query = trim((string) ($arguments[0] ?? ''));
$limit = max(1, min(12, (int) ($arguments[1] ?? 6)));
if ($query === '') {
    fwrite(STDERR, "usage: php app/code/Weline/Ai/Mcp/scripts/mcp-doc-search.php [--task] <query> [limit]\n");
    exit(1);
}

$repository = getcwd();
$session = 'doc-search-' . getmypid();
$config = Config::load();
$store = new Store($config);

try {
    $service = new ToolService($store, $config, new Analyzer($store, $config));
    $ready = $service->call('prepare_project', [
        'repository' => $repository,
        'client_session_id' => $session,
    ]);
    $readinessId = (string) ($ready['readiness_id'] ?? '');
    if ($readinessId === '') {
        fwrite(STDERR, "prepare_project returned no readiness_id\n");
        exit(2);
    }
    $payload = [
        'repository' => $repository,
        'client_session_id' => $session,
        'readiness_id' => $readinessId,
    ];
    if ($mode === 'resolve_task_context') {
        $payload['task'] = $query;
    } else {
        $payload['query'] = $query;
        $payload['limit'] = $limit;
    }
    $result = $service->call($mode, $payload);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} finally {
    $store->close();
}
