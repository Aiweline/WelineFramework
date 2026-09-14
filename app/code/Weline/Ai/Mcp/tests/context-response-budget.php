<?php

declare(strict_types=1);

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\GuidanceWorkflowCatalog;
use LearningMcp\HardConstraintsCatalog;
use LearningMcp\Json;
use LearningMcp\McpServer;
use LearningMcp\Store;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-context-budget-' . bin2hex(random_bytes(5));
$checks = [];
$check = static function (bool $passed, string $message) use (&$checks): void {
    $checks[] = ['passed' => $passed, 'message' => $message];
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};
$process = null;
$pipes = [];
try {
    mkdir($temporary . '/project/docs', 0700, true);
    mkdir($temporary . '/project/app/code', 0700, true);
    file_put_contents($temporary . '/project/docs/Queue.md', "# Queue budget diagnosis\n\n" . str_repeat("Queue retry persistence diagnosis keeps existing jobs intact.\n", 400));
    $configPath = $temporary . '/config.json';
    file_put_contents($configPath, Json::encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        'index' => ['sidecar_enabled' => false],
    ]));
    $environment = getenv();
    unset($environment['LEARNING_MCP_BOUND_REPOSITORY'], $environment['WELINE_MCP_RESPONSE_FORMAT']);
    $environment['WELINE_MCP_TOOL_PROFILE'] = 'compact';
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/bin/learning-mcp', '--config', $configPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $temporary . '/stderr.log', 'a']],
        $pipes,
        $temporary . '/project',
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start isolated MCP STDIO process');
    }
    stream_set_timeout($pipes[1], 30);
    $sequence = 0;
    $request = static function (string $method, array $params) use (&$sequence, &$pipes): array {
        fwrite($pipes[0], Json::encode(['jsonrpc' => '2.0', 'id' => ++$sequence, 'method' => $method, 'params' => $params]) . "\n");
        $line = fgets($pipes[1]);
        if ($line === false) {
            throw new RuntimeException('MCP STDIO did not produce a complete response');
        }
        $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (isset($response['error'])) {
            throw new RuntimeException(Json::encode($response['error']));
        }
        return $response['result'];
    };
    $init = $request('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'context-budget-test', 'version' => '1']]);
    $scope = ['repository' => $temporary . '/project', 'client_session_id' => 'context-budget-test'];
    $prepare = $request('tools/call', ['name' => 'prepare_project', 'arguments' => $scope]);
    $ready = $prepare['structuredContent'] ?? [];
    if (($ready['ready'] ?? false) !== true) {
        throw new RuntimeException('Fixture not ready: ' . Json::encode($ready));
    }
    $scope['readiness_id'] = $ready['readiness_id'];
    $response = $request('tools/call', ['name' => 'resolve_task_context', 'arguments' => $scope + [
        'task' => 'Queue retry persistence diagnosis', 'paths' => ['docs/Queue.md'], 'token_budget' => 3500,
    ]]);
    $body = $response['structuredContent'] ?? [];
    $guidanceSchema = json_decode((string) file_get_contents(dirname(__DIR__) . '/schemas/guidance-bundle.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $requiredFields = $guidanceSchema['required'] ?? [];
    $check(array_diff($requiredFields, array_keys($body)) === [], 'actual guidance response contains every schema-required field');
    $compactBody = $body;
    unset($compactBody['rules'], $compactBody['sources'], $compactBody['pinned_fragments']);
    $check(array_diff($requiredFields, array_keys($compactBody)) === [], 'legacy rules and source aliases are optional in the guidance schema');
    $check(isset($guidanceSchema['properties']['rules'], $guidanceSchema['properties']['sources']), 'guidance schema still accepts legacy rules and source arrays');
    $actualTokens = (int) ceil(mb_strlen(Json::encode($response), 'UTF-8') / 4);
    $check(($response['isError'] ?? true) === false, 'actual STDIO guidance call succeeds');
    $check($actualTokens <= 3500, '3500-token request bounds the complete serialized tools/call result');
    $check(($body['token_usage']['estimated'] ?? -1) === $actualTokens, 'reported estimate includes the final response envelope');
    $check(($body['fragments'] ?? []) !== [], 'bounded result retains useful indexed content');
    $check(($body['workflow_contract']['surfaces'] ?? []) === [], 'queue diagnosis does not include unrelated frontend surfaces');
    $check(!isset($body['pinned_fragments']) && !isset($body['workflow_contract']['frontend_development']), 'normal context omits duplicated pinned and frontend aliases');
    $check(isset($body['workflow_contract']['authoritative_hard_rules_index']), 'compact guidance preserves its authoritative rules entry');
    $check(mb_strlen((string) ($init['instructions'] ?? ''), 'UTF-8') <= 3200, 'server bootstrap stays bounded when hosts expand it per tool');
    $frontendResponse = $request('tools/call', ['name' => 'resolve_task_context', 'arguments' => $scope + [
        'task' => 'Theme widget JavaScript module loading', 'token_budget' => 3500,
    ]]);
    $frontendBody = $frontendResponse['structuredContent'] ?? [];
    $frontendNorms = $frontendBody['workflow_contract']['surfaces']['frontend_development']['norms'] ?? [];
    $check(in_array('theme_js_module_declare_only', array_column($frontendNorms, 'id'), true), 'matched frontend task retains the mandatory module loading norm');
    $smallResponse = $request('tools/call', ['name' => 'resolve_task_context', 'arguments' => $scope + [
        'task' => 'Queue retry persistence diagnosis', 'token_budget' => 256,
    ]]);
    $smallUsage = $smallResponse['structuredContent']['token_usage'] ?? [];
    $smallActual = (int) ceil(mb_strlen(Json::encode($smallResponse), 'UTF-8') / 4);
    $check(($smallUsage['estimated'] ?? -1) === $smallActual
        && (($smallUsage['budget_exceeded'] ?? false) === ($smallActual > 256)), 'budget below mandatory envelope cost is reported truthfully');
    $overlap = GuidanceWorkflowCatalog::mergeFragments([
        ['path' => 'Code.php', 'start_line' => 2, 'end_line' => 2, 'content' => 'method body', 'content_hash' => 'small'],
        ['path' => 'Code.php', 'start_line' => 1, 'end_line' => 3, 'content' => "class body\nmethod body\nend", 'content_hash' => 'large'],
    ], []);
    $check(count($overlap) === 1 && str_contains((string) ($overlap[0]['content'] ?? ''), 'class body'), 'contained source fragments are delivered once');

    $config = Config::load($configPath);
    $store = new Store($config);
    $server = new McpServer(new ToolService($store, $config, new Analyzer($store, $config)));
    $envelope = new ReflectionMethod($server, 'toolResponse');
    $marker = 'UNIQUE_REPLACEMENT_BODY_' . str_repeat('z', 200);
    $guidanceResult = [
        'schema_version' => 'guidance-bundle.v1',
        'fragments' => [['path' => 'docs/Queue.md', 'content' => $marker]],
        'token_usage' => ['budget' => 3500, 'estimated' => 100],
    ];
    $guidanceEnvelope = $envelope->invoke($server, 'resolve_task_context', $guidanceResult, false);
    $check(substr_count(Json::encode($guidanceEnvelope), $marker) === 1, 'guidance body has one default wire carrier');
    $check(($guidanceEnvelope['structuredContent']['fragments'][0]['content'] ?? '') === $marker, 'single carrier preserves complete structured guidance content');
    putenv('WELINE_MCP_RESPONSE_FORMAT=legacy_mirror');
    $legacy = $envelope->invoke($server, 'resolve_task_context', $guidanceResult, false);
    $check(str_contains((string) ($legacy['content'][1]['text'] ?? ''), $marker), 'explicit legacy mirror remains available to text-only hosts');
    putenv('WELINE_MCP_RESPONSE_FORMAT');
    $store->close();
    $frontend = GuidanceWorkflowCatalog::frontendDevelopmentSurface();
    $check(!str_contains(Json::encode($frontend['template_surface_rules'] ?? []), 'load via @static(...js)'), 'frontend requirements do not recommend forbidden direct module loading');
    $check(!str_contains(Json::encode(HardConstraintsCatalog::workflowHardRules()), 'use external @static JS'), 'hard rules agree with the JS module declaration contract');
    echo Json::encode(['actual_response_tokens' => $actualTokens, 'checks' => $checks], true) . "\n";
} catch (Throwable $exception) {
    $check(false, $exception::class . ': ' . $exception->getMessage());
    fwrite(STDERR, Json::encode($checks, true) . "\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    $removeTree($temporary);
}
exit(count(array_filter($checks, static fn (array $row): bool => !$row['passed'])) === 0 ? 0 : 1);
