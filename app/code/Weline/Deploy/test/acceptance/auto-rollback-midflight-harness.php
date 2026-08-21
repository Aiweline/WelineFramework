<?php
declare(strict_types=1);

/**
 * Isolated mid-flight release failure auto-rollback harness.
 * Usage: php app/code/Weline/Deploy/test/acceptance/auto-rollback-midflight-harness.php
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

$tmp = sys_get_temp_dir() . '/deploy-midflight-' . bin2hex(random_bytes(4));
mkdir($tmp);
$modelRel = 'app/code/Shopify/DeployDemo/Model/DemoMarker.php';
mkdir($tmp . '/app/code/Shopify/DeployDemo/Model', 0775, true);
mkdir($tmp . '/bin', 0775, true);
mkdir($tmp . '/var/deploy', 0775, true);

$modelV1 = <<<'PHP'
<?php
namespace Shopify\DeployDemo\Model;
class DemoMarker {
    public const DEMO_VERSION = 'demo-v1';
    public const DEMO_PAYLOAD = 'payload-v1-ok';
}
PHP;
$modelV2 = str_replace(['demo-v1', 'payload-v1-ok'], ['demo-v2', 'payload-v2-bad'], $modelV1);
file_put_contents($tmp . '/' . $modelRel, $modelV1);
file_put_contents($tmp . '/bin/w', "<?php\nfwrite(STDERR, \"harness-post-deploy-fail\\n\");\nexit(42);\n");
chmod($tmp . '/bin/w', 0755);

$run('git -C ' . escapeshellarg($tmp) . ' init -b main');
$run('git -C ' . escapeshellarg($tmp) . ' config user.email t@t.com');
$run('git -C ' . escapeshellarg($tmp) . ' config user.name t');
$run('git -C ' . escapeshellarg($tmp) . ' add -A');
$run('git -C ' . escapeshellarg($tmp) . ' commit -m c1-demo-v1');
$c1 = trim((string)shell_exec('git -C ' . escapeshellarg($tmp) . ' rev-parse HEAD'));
file_put_contents($tmp . '/' . $modelRel, $modelV2);
$run('git -C ' . escapeshellarg($tmp) . ' add -A');
$run('git -C ' . escapeshellarg($tmp) . ' commit -m c2-demo-v2');
$c2 = trim((string)shell_exec('git -C ' . escapeshellarg($tmp) . ' rev-parse HEAD'));
$run('git -C ' . escapeshellarg($tmp) . ' checkout -q ' . escapeshellarg($c1));
$run('git -C ' . escapeshellarg($tmp) . ' remote add origin ' . escapeshellarg($tmp));

file_put_contents($tmp . '/var/deploy/current.json', json_encode([
    'release_id' => 'pre-demo',
    'deploy_version' => 'demo-v1',
    'worker_build_id' => 'w-pre',
    'git_commit' => $c1,
], JSON_THROW_ON_ERROR));

$history = new class extends DeployReleaseHistoryService {
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public function start(string $releaseId, string $trigger, string $refType, string $ref, ?string $deployVersionHint, ?string $gitTag, array $context = []): DeployRelease {
        $this->events[] = ['op' => 'start', 'trigger' => $trigger, 'releaseId' => $releaseId, 'ref' => $ref];
        /** @var DeployRelease $m */
        $m = ObjectManager::getInstance(DeployRelease::class);
        $m->setData(['release_id' => $releaseId]);
        return $m;
    }
    public function markSuccess(string $releaseId, string $deployVersion, string $workerBuildId, string $gitCommit, ?string $gitBranch): void {
        $this->events[] = ['op' => 'success', 'releaseId' => $releaseId, 'deployVersion' => $deployVersion, 'gitCommit' => $gitCommit];
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
);

$git = ObjectManager::getInstance(DeployGitMetadataService::class);
$runtime = ObjectManager::getInstance(DeployReleaseRuntimeService::class);

$cases = [];

// Case A: forced auto-rollback (simulates production) after post-deploy failure
$resultForce = $orch->release([
    'trigger' => 'harness',
    'ref_type' => 'commit',
    'ref' => $c2,
    'git_checkout' => $c2,
    'force' => true,
    'no_backup' => true,
    'config' => [
        'DEPLOY_ROOT' => $tmp,
        'POST_DEPLOY_COMMAND' => 'php bin/w cache:clear',
        'AUTO_ROLLBACK_ON_FAILURE' => '1',
        'BACKUP_BEFORE_DEPLOY' => 'false',
        'GIT_UPDATE_MODE' => 'reset',
    ],
    'printer' => null,
]);
$modelAfterForce = (string)file_get_contents($tmp . '/' . $modelRel);
$commitAfterForce = $git->getFullCommit($tmp);
$currentAfterForce = $runtime->getCurrent($tmp);
$cases['forced_prod_auto_rollback'] = [
    'ok' => ($resultForce['success'] ?? true) === false
        && str_contains((string)($resultForce['message'] ?? ''), '已自动回滚')
        && str_contains($modelAfterForce, 'demo-v1')
        && !str_contains($modelAfterForce, 'demo-v2')
        && hash_equals($c1, $commitAfterForce)
        && (($currentAfterForce['deploy_version'] ?? '') === 'demo-v1'),
    'message' => $resultForce['message'] ?? '',
    'commit' => $commitAfterForce,
    'model_has_v1' => str_contains($modelAfterForce, 'demo-v1'),
    'deploy_version' => $currentAfterForce['deploy_version'] ?? null,
];

// Prepare for case B: move to c2 again without rollback (dev skip)
$run('git -C ' . escapeshellarg($tmp) . ' checkout -q -B main ' . escapeshellarg($c1));
file_put_contents($tmp . '/var/deploy/current.json', json_encode([
    'release_id' => 'pre-demo',
    'deploy_version' => 'demo-v1',
    'worker_build_id' => 'w-pre',
    'git_commit' => $c1,
], JSON_THROW_ON_ERROR));

$resultDev = $orch->release([
    'trigger' => 'harness',
    'ref_type' => 'commit',
    'ref' => $c2,
    'git_checkout' => $c2,
    'force' => true,
    'no_backup' => true,
    'config' => [
        'DEPLOY_ROOT' => $tmp,
        'POST_DEPLOY_COMMAND' => 'php bin/w cache:clear',
        'AUTO_ROLLBACK_ON_FAILURE' => '0',
        'BACKUP_BEFORE_DEPLOY' => 'false',
        'GIT_UPDATE_MODE' => 'reset',
    ],
    'printer' => null,
]);
$modelAfterDev = (string)file_get_contents($tmp . '/' . $modelRel);
$commitAfterDev = $git->getFullCommit($tmp);
$cases['dev_skips_auto_rollback'] = [
    'ok' => ($resultDev['success'] ?? true) === false
        && str_contains((string)($resultDev['message'] ?? ''), '开发环境跳过发布失败自动回滚')
        && str_contains($modelAfterDev, 'demo-v2')
        && hash_equals($c2, $commitAfterDev),
    'message' => $resultDev['message'] ?? '',
    'commit' => $commitAfterDev,
    'model_has_v2' => str_contains($modelAfterDev, 'demo-v2'),
];

$allOk = true;
foreach ($cases as $case) {
    if (empty($case['ok'])) {
        $allOk = false;
        break;
    }
}

echo json_encode([
    'ok' => $allOk,
    'c1' => $c1,
    'c2' => $c2,
    'cases' => $cases,
    'history_tail' => array_slice($history->events, -6),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$run('rm -rf ' . escapeshellarg($tmp));
exit($allOk ? 0 : 1);
