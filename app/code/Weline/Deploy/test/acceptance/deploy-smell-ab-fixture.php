<?php

declare(strict_types=1);

/**
 * Local fixture: branch tip miss + skip-worktree gate (no Gitee).
 * Usage: php app/code/Weline/Deploy/test/acceptance/deploy-smell-ab-fixture.php
 */

$root = dirname(__DIR__, 6);
chdir($root);
require $root . '/app/bootstrap.php';

use Weline\Deploy\Service\DeployGitMetadataService;
use Weline\Deploy\Service\DeployReleaseNotifier;
use Weline\Deploy\Service\DeployWebhookExpectWatchService;
use Weline\Deploy\Service\DeployWebhookJobQueue;
use Weline\Framework\Manager\ObjectManager;

$out = [
    'ok' => false,
    'tip' => [],
    'skip_worktree' => [],
    'errors' => [],
];

try {
    /** @var DeployWebhookExpectWatchService $watch */
    $watch = ObjectManager::getInstance(DeployWebhookExpectWatchService::class);
    /** @var DeployWebhookJobQueue $queue */
    $queue = ObjectManager::getInstance(DeployWebhookJobQueue::class);
    /** @var DeployReleaseNotifier $notifier */
    $notifier = ObjectManager::getInstance(DeployReleaseNotifier::class);
    /** @var DeployGitMetadataService $git */
    $git = ObjectManager::getInstance(DeployGitMetadataService::class);

    // Unique branch so leftover queue jobs from prior fixture runs cannot false-arrive.
    $branch = 'expect-tip-' . substr(sha1((string)microtime(true)), 0, 10);
    $tipSha = str_repeat('a', 40);
    $ref = 'refs/heads/' . $branch;
    $firstSeen = time() - 120;
    $out['tip']['branch'] = $branch;

    // Tip not arrived without job / current match.
    $out['tip']['bare_find_by_ref_alone_forbidden'] = true;
    $arrivedBefore = $watch->hasBranchTipArrived($branch, $tipSha, $ref, $firstSeen);
    $out['tip']['arrived_before_enqueue'] = $arrivedBefore;

    // Historical job with same ref but old created_at must NOT clear.
    $oldJob = $queue->enqueue($ref, false, true, '');
    if (!empty($oldJob['ok'])) {
        $queue->updateJob((string)$oldJob['job_id'], ['created_at' => $firstSeen - 600]);
    }
    $arrivedOld = $watch->hasBranchTipArrived($branch, $tipSha, $ref, $firstSeen);
    $out['tip']['arrived_with_stale_job'] = $arrivedOld;

    // Same-wave job (created within skew) clears.
    $wave = $queue->enqueue($ref, false, true, '');
    $arrivedWave = $watch->hasBranchTipArrived($branch, $tipSha, $ref, $firstSeen);
    $out['tip']['arrived_with_wave_job'] = $arrivedWave;
    $out['tip']['wave_enqueued'] = !empty($wave['ok']);

    // Emit miss for evidence when not arrived (stale path already proven).
    if (!$arrivedBefore) {
        $notifier->notifyPhase(DeployReleaseNotifier::PHASE_WEBHOOK_MISS, [
            'ref' => $ref,
            'deploy_version' => $tipSha,
            'git_commit' => $tipSha,
            'trigger' => 'expect_watch_fixture',
            'waited_s' => '120',
            'reason' => 'branch_tip_webhook_miss_after_grace',
            'dedupe_salt' => 'tip-fixture-' . substr($tipSha, 0, 8),
        ]);
        $out['tip']['miss_emitted'] = true;
    }

    // skip-worktree detection on a temp git repo.
    $tmp = sys_get_temp_dir() . '/deploy-skipwt-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $run = static function (string $cmd) use ($tmp): void {
        $outLines = [];
        $code = 0;
        exec('cd ' . escapeshellarg($tmp) . ' && ' . $cmd . ' 2>&1', $outLines, $code);
        if ($code !== 0) {
            throw new RuntimeException($cmd . "\n" . implode("\n", $outLines));
        }
    };
    $run('git init -q');
    $run('git config user.email skipwt@test.local');
    $run('git config user.name skipwt');
    file_put_contents($tmp . '/tracked.txt', "v1\n");
    $run('git add tracked.txt');
    $run('git commit -qm init');
    $run('git update-index --skip-worktree tracked.txt');

    $paths = $git->listSkipWorktreePaths($tmp);
    $out['skip_worktree']['detected'] = $paths;
    $out['skip_worktree']['has_tracked'] = in_array('tracked.txt', $paths, true);

    $notifier->notifyPhase(DeployReleaseNotifier::PHASE_WORKDIR_BLOCKED, [
        'deploy_root' => $tmp,
        'trigger' => 'fixture',
        'error_message' => 'skip-worktree: tracked.txt',
        'reason' => 'skip_worktree',
    ]);
    $out['skip_worktree']['workdir_blocked_emitted'] = true;

    // cleanup tmp
    $run('git update-index --no-skip-worktree tracked.txt');
    exec('rm -rf ' . escapeshellarg($tmp));

    $out['ok'] = $out['tip']['arrived_before_enqueue'] === false
        && $out['tip']['arrived_with_stale_job'] === false
        && $out['tip']['arrived_with_wave_job'] === true
        && !empty($out['tip']['miss_emitted'])
        && !empty($out['skip_worktree']['has_tracked'])
        && !empty($out['skip_worktree']['workdir_blocked_emitted']);
} catch (Throwable $e) {
    $out['errors'][] = $e->getMessage();
    $out['ok'] = false;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit($out['ok'] ? 0 : 1);
