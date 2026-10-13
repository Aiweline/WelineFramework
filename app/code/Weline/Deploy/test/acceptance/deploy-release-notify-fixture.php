<?php

declare(strict_types=1);

/**
 * Local fixture: phase notify + webhook_miss path (no Gitee push).
 * Usage: php app/code/Weline/Deploy/test/acceptance/deploy-release-notify-fixture.php
 */

$root = dirname(__DIR__, 6);
chdir($root);
require $root . '/app/bootstrap.php';

use Weline\Deploy\Service\DeployReleaseNotifier;
use Weline\Deploy\Service\DeployWebhookExpectWatchService;
use Weline\Deploy\Service\DeployWebhookJobQueue;
use Weline\Framework\Manager\ObjectManager;

$out = [
    'ok' => false,
    'phases' => [],
    'expect' => [],
    'errors' => [],
];

try {
    /** @var DeployReleaseNotifier $notifier */
    $notifier = ObjectManager::getInstance(DeployReleaseNotifier::class);
    $releaseId = 'fixture-notify-' . date('YmdHis');
    $phases = [
        DeployReleaseNotifier::PHASE_QUEUED,
        DeployReleaseNotifier::PHASE_DRAIN_START,
        DeployReleaseNotifier::PHASE_STARTED,
        DeployReleaseNotifier::PHASE_GIT_DONE,
        DeployReleaseNotifier::PHASE_SUCCESS,
    ];
    foreach ($phases as $phase) {
        $notifier->notifyPhase($phase, [
            'release_id' => $releaseId,
            'job_id' => 'job-' . $releaseId,
            'ref' => 'refs/tags/fixture-notify-tag',
            'deploy_version' => 'fixture-notify-tag',
            'trigger' => 'fixture',
            'elapsed_s' => 1,
        ]);
        $out['phases'][] = $phase;
    }

    // Second non-terminal same key should dedupe (no throw).
    $notifier->notifyPhase(DeployReleaseNotifier::PHASE_STARTED, [
        'release_id' => $releaseId,
        'ref' => 'refs/tags/fixture-notify-tag',
        'trigger' => 'fixture',
    ]);

    /** @var DeployWebhookExpectWatchService $watch */
    $watch = ObjectManager::getInstance(DeployWebhookExpectWatchService::class);
    $statePath = $watch->statePath();
    $backup = is_file($statePath) ? (string)file_get_contents($statePath) : null;

    $tag = 'expect-miss-' . substr(sha1((string)microtime(true)), 0, 8);
    $ref = 'refs/tags/' . $tag;
    $now = time();
    $state = [
        'bootstrapped' => true,
        'bootstrap_seen' => [],
        'pending' => [
            $tag => [
                'ref' => $ref,
                'first_seen_at' => $now - 120,
                'notified_at' => null,
            ],
        ],
        'updated_at' => $now,
    ];
    $dir = dirname($statePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(
        $statePath,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    );

    // Force tick path for pending without depending on live ls-remote:
    // synthesize by calling hasArrived + notifier directly for miss, then clear via enqueue.
    if (!$watch->hasArrived($tag, $ref)) {
        $notifier->notifyPhase(DeployReleaseNotifier::PHASE_WEBHOOK_MISS, [
            'ref' => $ref,
            'deploy_version' => $tag,
            'trigger' => 'expect_watch_fixture',
            'waited_s' => '120',
            'reason' => 'webhook_miss_after_grace',
            'dedupe_salt' => $tag,
        ]);
        $out['expect']['miss_emitted'] = true;
    }

    /** @var DeployWebhookJobQueue $queue */
    $queue = ObjectManager::getInstance(DeployWebhookJobQueue::class);
    $enqueued = $queue->enqueue($ref, false, true, '');
    $found = $queue->findByRef($ref);
    $out['expect']['enqueued'] = !empty($enqueued['ok']);
    $out['expect']['find_by_ref'] = $found !== null;
    $out['expect']['has_arrived_after_enqueue'] = $watch->hasArrived($tag, $ref);
    $out['expect']['grace_normalized_30'] = $watch->normalizeGrace(30);
    $out['expect']['grace_normalized_200'] = $watch->normalizeGrace(200);
    $out['release_id'] = $releaseId;
    $out['tag'] = $tag;
    $out['ok'] = $out['expect']['enqueued']
        && $out['expect']['find_by_ref']
        && $out['expect']['has_arrived_after_enqueue']
        && $out['expect']['grace_normalized_30'] === 60
        && $out['expect']['grace_normalized_200'] === 180
        && count($out['phases']) === 5;

    if ($backup !== null) {
        file_put_contents($statePath, $backup);
    } elseif (is_file($statePath)) {
        // Leave fixture state only if we created it; prefer restore empty bootstrap.
        @unlink($statePath);
    }
} catch (Throwable $e) {
    $out['errors'][] = $e->getMessage();
    $out['ok'] = false;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit($out['ok'] ? 0 : 1);
