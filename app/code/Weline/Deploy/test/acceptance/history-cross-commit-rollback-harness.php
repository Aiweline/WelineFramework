<?php

declare(strict_types=1);

/**
 * Isolated Deploy history-style cross-commit rollback harness.
 * Simulates「回滚到此版本」: c2 (demo-v2) → Orchestrator::rollback(c1) → demo-v1.
 *
 * Usage: php app/code/Weline/Deploy/test/acceptance/history-cross-commit-rollback-harness.php
 *
 * C4: this is Deploy Git/worktree only — not Schema MigrationBackup / module:rollback.
 */

$root = dirname(__DIR__, 6);
chdir($root);
require $root . '/app/bootstrap.php';

use Weline\Deploy\Model\DeployRelease;
use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployGitMetadataService;
use Weline\Deploy\Service\DeployOrchestratorService;
use Weline\Deploy\Service\DeployProjectCommandPolicyService;
use Weline\Deploy\Service\DeployReleaseHistoryService;
use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Deploy\Service\DeploySiteBackupService;
use Weline\Framework\Manager\ObjectManager;

$run = static function (string $cmd): void {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException($cmd . "\n" . implode("\n", $out));
    }
};

$tmp = sys_get_temp_dir() . '/deploy-history-rollback-' . bin2hex(random_bytes(4));
mkdir($tmp);
$modelRel = 'app/code/Shopify/DeployDemo/Model/DemoMarker.php';
mkdir($tmp . '/app/code/Shopify/DeployDemo/Model', 0775, true);
mkdir($tmp . '/bin', 0775, true);
mkdir($tmp . '/var/deploy', 0775, true);
file_put_contents($tmp . '/bin/w', "<?php\nexit(0);\n");
chmod($tmp . '/bin/w', 0755);

$v1 = "<?php\nnamespace Shopify\\DeployDemo\\Model;\nclass DemoMarker { public const DEMO_VERSION='demo-v1'; }\n";
$v2 = "<?php\nnamespace Shopify\\DeployDemo\\Model;\nclass DemoMarker { public const DEMO_VERSION='demo-v2'; }\n";
file_put_contents($tmp . '/' . $modelRel, $v1);
$run('git -C ' . escapeshellarg($tmp) . ' init -b main');
$run('git -C ' . escapeshellarg($tmp) . ' config user.email t@t.com');
$run('git -C ' . escapeshellarg($tmp) . ' config user.name t');
$run('git -C ' . escapeshellarg($tmp) . ' add -A');
$run('git -C ' . escapeshellarg($tmp) . ' commit -m c1-v1');
$c1 = trim((string)shell_exec('git -C ' . escapeshellarg($tmp) . ' rev-parse HEAD'));
file_put_contents($tmp . '/' . $modelRel, $v2);
$run('git -C ' . escapeshellarg($tmp) . ' add -A');
$run('git -C ' . escapeshellarg($tmp) . ' commit -m c2-v2');
$c2 = trim((string)shell_exec('git -C ' . escapeshellarg($tmp) . ' rev-parse HEAD'));
$run('git -C ' . escapeshellarg($tmp) . ' remote add origin ' . escapeshellarg($tmp));

file_put_contents($tmp . '/var/deploy/current.json', json_encode([
    'release_id' => 'release-c2',
    'deploy_version' => 'demo-v2',
    'worker_build_id' => 'w-c2',
    'git_commit' => $c2,
], JSON_THROW_ON_ERROR));

$history = new class extends DeployReleaseHistoryService {
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function start(
        string $releaseId,
        string $trigger,
        string $refType,
        string $ref,
        ?string $deployVersionHint,
        ?string $gitTag,
        array $context = [],
    ): DeployRelease {
        $this->events[] = [
            'op' => 'start',
            'trigger' => $trigger,
            'releaseId' => $releaseId,
            'refType' => $refType,
            'ref' => $ref,
        ];
        /** @var DeployRelease $m */
        $m = ObjectManager::getInstance(DeployRelease::class);
        $m->setData(['release_id' => $releaseId]);

        return $m;
    }

    public function markSuccess(
        string $releaseId,
        string $deployVersion,
        string $workerBuildId,
        string $gitCommit,
        ?string $gitBranch,
    ): void {
        $this->events[] = [
            'op' => 'success',
            'releaseId' => $releaseId,
            'deployVersion' => $deployVersion,
            'gitCommit' => $gitCommit,
        ];
    }

    public function markFailed(string $releaseId, string $errorMessage, string $outputTail = ''): void {
        $this->events[] = ['op' => 'failed', 'releaseId' => $releaseId, 'error' => $errorMessage];
    }
};

$orch = new DeployOrchestratorService(
    ObjectManager::getInstance(DeployConfigService::class),
    ObjectManager::getInstance(DeployReleaseRuntimeService::class),
    $history,
    ObjectManager::getInstance(DeployGitMetadataService::class),
    ObjectManager::getInstance(DeployProjectCommandPolicyService::class),
    ObjectManager::getInstance(DeploySiteBackupService::class),
    ObjectManager::getInstance(\Weline\Deploy\Service\DeployReleaseNotifier::class),
);

$result = $orch->rollback([
    'rollback_ref' => $c1,
    'no_backup' => true,
    'config' => [
        'DEPLOY_ROOT' => $tmp,
        'AFTER_DEPLOY_COMMAND' => '',
    ],
]);

$head = trim((string)shell_exec('git -C ' . escapeshellarg($tmp) . ' rev-parse HEAD'));
$marker = (string)@file_get_contents($tmp . '/' . $modelRel);
$ok = !empty($result['success'])
    && hash_equals($c1, $head)
    && str_contains($marker, 'demo-v1')
    && !str_contains($marker, 'demo-v2');

echo json_encode([
    'ok' => $ok,
    'lane' => 'deploy_git_worktree',
    'not' => 'schema_column_migration_backup',
    'from_commit' => $c2,
    'rollback_ref' => $c1,
    'head_after' => $head,
    'result_success' => (bool)($result['success'] ?? false),
    'result_message' => (string)($result['message'] ?? ''),
    'deploy_version' => (string)($result['deploy_version'] ?? ''),
    'marker_has_v1' => str_contains($marker, 'demo-v1'),
    'history_tail' => array_slice($history->events, -4),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$run('rm -rf ' . escapeshellarg($tmp));
exit($ok ? 0 : 1);
