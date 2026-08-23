<?php

declare(strict_types=1);

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\ProcessRunner;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectReadinessService;
use LearningMcp\ProjectResolver;
use LearningMcp\Store;
use LearningMcp\ToolException;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-readiness-' . bin2hex(random_bytes(6));
$repository = $temporary . DIRECTORY_SEPARATOR . 'project';
$dataDirectory = $temporary . DIRECTORY_SEPARATOR . 'data';
$failures = [];

function readinessCheck(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "[PASS] {$label}\n");
        return;
    }
    $failures[] = $label;
    fwrite(STDERR, "[FAIL] {$label}\n");
}

function readinessRemoveTree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        readinessRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

try {
    mkdir($repository . '/app/code/Acme/Demo/etc', 0700, true);
    file_put_contents($repository . '/app/code/Acme/Demo/register.php', "<?php\n");
    file_put_contents(
        $repository . '/app/code/Acme/Demo/etc/module.php',
        "<?php\nreturn ['name' => 'Acme_Demo', 'version' => '1.0.0'];\n",
    );
    file_put_contents(
        $temporary . '/config.json',
        json_encode([
            'data_dir' => $dataDirectory,
            'analysis' => ['provider' => 'none'],
            'index' => ['sidecar_enabled' => false, 'include_tests' => true],
            'knowledge' => [
                'auto_generate_skills' => false,
                'learning_skills' => ['enabled' => false, 'inject_on_prompt' => false],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    );

    $config = Config::load($temporary . '/config.json');
    $resolved = ProjectResolver::resolve($repository, false);
    $index = new ProjectIndex($config, $resolved);
    $service = new ProjectReadinessService($config, new ProcessRunner());

    $missing = $service->prepare($index, ['client_session_id' => 'session-a']);
    readinessCheck($missing['schema_version'] === 'project-readiness.v1', 'prepare_project returns project-readiness.v1');
    readinessCheck($missing['status'] === 'needs_repair', 'missing module documents block readiness');
    readinessCheck(count($missing['repair_bundle']['operations'] ?? []) === 3, 'repair bundle deterministically lists the three missing documents');

    $unauthorizedRejected = false;
    try {
        $service->repair($index, [
            'client_session_id' => 'session-a',
            'repair_bundle_id' => $missing['repair_bundle']['bundle_id'],
            'authorized' => false,
        ]);
    } catch (ToolException $exception) {
        $unauthorizedRejected = $exception->errorCode === 'REPAIR_AUTHORIZATION_REQUIRED';
    }
    readinessCheck($unauthorizedRejected, 'repair never writes without explicit authorization');

    $ready = $service->repair($index, [
        'client_session_id' => 'session-a',
        'repair_bundle_id' => $missing['repair_bundle']['bundle_id'],
        'authorized' => true,
    ]);
    readinessCheck($ready['status'] === 'ready', 'authorized repair reindexes and reaches ready');
    readinessCheck(is_string($ready['readiness_id'] ?? null) && $ready['readiness_id'] !== '', 'ready response binds a readiness id');

    $sessionMismatchRejected = false;
    try {
        $service->assertReady($index, [
            'client_session_id' => 'session-b',
            'readiness_id' => $ready['readiness_id'],
        ]);
    } catch (ToolException $exception) {
        $sessionMismatchRejected = $exception->errorCode === 'READINESS_SESSION_MISMATCH';
    }
    readinessCheck($sessionMismatchRejected, 'readiness cannot cross client sessions');

    $directives = $service->setDirectives($index, [
        'client_session_id' => 'session-a',
        'readiness_id' => $ready['readiness_id'],
        'directives' => ['本次只修改 Acme_Demo，不调整公共 API。'],
    ]);
    readinessCheck($directives['persisted'] === false, 'session directives explicitly remain memory-only');
    readinessCheck(count($service->directives($index, 'session-a')) === 1, 'session directives can be resolved for task context');

    $secretRejected = false;
    try {
        $service->setDirectives($index, [
            'client_session_id' => 'session-a',
            'readiness_id' => $ready['readiness_id'],
            'directives' => ['OPENAI_API_KEY=sk-test-secret-value'],
        ]);
    } catch (ToolException $exception) {
        $secretRejected = $exception->errorCode === 'SESSION_DIRECTIVE_SECRET_REJECTED';
    }
    readinessCheck($secretRejected, 'session directives reject credential-shaped content');

    $readme = $repository . '/app/code/Acme/Demo/doc/README.md';
    $beforeHash = hash_file('sha256', $readme);
    file_put_contents($readme, (string) file_get_contents($readme) . "\n外部更新。\n");
    $fresh = $service->assertReady($index, [
        'client_session_id' => 'session-a',
        'readiness_id' => $ready['readiness_id'],
    ]);
    readinessCheck($fresh['refreshed'] === true, 'next guarded call detects and indexes an external document edit');
    readinessCheck(($fresh['documents']['app/code/Acme/Demo/doc/README.md'] ?? '') !== $beforeHash, 'fresh readiness binds the new document hash');

    $store = new Store($config);
    $tools = new ToolService($store, $config, new Analyzer($store, $config));
    $unpreparedToolRejected = false;
    try {
        $tools->call('resolve_task_context', [
            'repository' => $repository,
            'client_session_id' => 'tool-session',
            'readiness_id' => 'missing',
            'task' => '了解 Acme_Demo 的模块规范',
        ]);
    } catch (ToolException $exception) {
        $unpreparedToolRejected = $exception->errorCode === 'PROJECT_NOT_PREPARED';
    }
    readinessCheck($unpreparedToolRejected, 'ToolService blocks knowledge tools before prepare_project');
    $toolReady = $tools->call('prepare_project', [
        'repository' => $repository,
        'client_session_id' => 'tool-session',
    ]);
    $guidance = $tools->call('resolve_task_context', [
        'repository' => $repository,
        'client_session_id' => 'tool-session',
        'readiness_id' => $toolReady['readiness_id'],
        'task' => '了解 Acme_Demo 的模块规范',
        'module' => 'Acme_Demo',
    ]);
    readinessCheck($guidance['schema_version'] === 'guidance-bundle.v1', 'resolve_task_context returns guidance-bundle.v1 after readiness');
    readinessCheck(($guidance['_project_readiness']['status'] ?? '') === 'ready', 'guarded tool response carries compact readiness evidence');
    $alias = $tools->call('resolve_skill', [
        'repository' => $repository,
        'client_session_id' => 'tool-session',
        'readiness_id' => $toolReady['readiness_id'],
        'task' => 'Acme_Demo 开发规范',
        'module' => 'Acme_Demo',
    ]);
    readinessCheck(($alias['compatibility_alias'] ?? '') === 'resolve_skill' && $alias['static_skill_files'] === false, 'resolve_skill is a dynamic document-query alias');
    unset($tools);
    gc_collect_cycles();
    $store->close();

    unlink($repository . '/app/code/Acme/Demo/doc/需求.md');
    $missingAfterReadyRejected = false;
    try {
        $service->assertReady($index, [
            'client_session_id' => 'session-a',
            'readiness_id' => $ready['readiness_id'],
        ]);
    } catch (ToolException $exception) {
        $missingAfterReadyRejected = $exception->errorCode === 'PROJECT_NEEDS_REPAIR';
    }
    readinessCheck($missingAfterReadyRejected, 'document removal invalidates readiness before knowledge access');

    $index->close();
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, '[FAIL] unexpected exception: ' . $exception->getMessage() . "\n");
} finally {
    readinessRemoveTree($temporary);
}

fwrite(STDOUT, json_encode([
    'schema_version' => 'project-readiness-tests.v1',
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

exit($failures === [] ? 0 : 1);
