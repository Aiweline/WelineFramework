<?php

declare(strict_types=1);

/**
 * Real-machine fixture: incomplete jobs are abandoned on supersede and after 30m TTL.
 * Run: php app/code/Weline/Deploy/test/acceptance/deploy-webhook-supersede-stale-fixture.php
 */

$root = dirname(__DIR__, 6);
chdir($root);
require $root . '/app/bootstrap.php';

use Weline\Deploy\Service\DeployWebhookJobQueue;
use Weline\Framework\Manager\ObjectManager;

$out = [
    'ok' => false,
    'supersede' => [],
    'stale' => [],
    'errors' => [],
];

try {
    /** @var DeployWebhookJobQueue $queue */
    $queue = ObjectManager::getInstance(DeployWebhookJobQueue::class);
    $dir = $queue->queueDir();
    $stamp = 'fixture-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

    $oldPending = $queue->enqueue('refs/heads/dev', true, true, '');
    $oldRunningId = '';
    if (!empty($oldPending['ok'])) {
        $oldRunningId = (string)$oldPending['job_id'];
        $queue->updateJob($oldRunningId, [
            'status' => DeployWebhookJobQueue::STATUS_RUNNING,
            'started_at' => time() - 60,
            'message' => $stamp . '-running',
        ]);
    } else {
        $out['errors'][] = 'enqueue_old_failed';
    }

    $superseded = $queue->abandonIncomplete('superseded_by_newer_webhook');
    $out['supersede']['count'] = count($superseded);
    $out['supersede']['ids'] = array_column($superseded, 'job_id');
    $out['supersede']['includes_running'] = $oldRunningId !== '' && in_array($oldRunningId, $out['supersede']['ids'], true);

    $stale2 = $queue->enqueue('refs/heads/dev', true, true, '');
    $stale2Id = !empty($stale2['ok']) ? (string)$stale2['job_id'] : '';
    if ($stale2Id !== '') {
        $queue->updateJob($stale2Id, [
            'status' => DeployWebhookJobQueue::STATUS_PENDING,
            'created_at' => time() - (DeployWebhookJobQueue::STALE_INCOMPLETE_SECONDS + 5),
            'updated_at' => time() - (DeployWebhookJobQueue::STALE_INCOMPLETE_SECONDS + 5),
            'message' => $stamp . '-stale2',
        ]);
    } else {
        $out['errors'][] = 'enqueue_stale2_failed';
    }

    $fresh = $queue->enqueue('refs/heads/dev', true, true, '');
    $freshId = !empty($fresh['ok']) ? (string)$fresh['job_id'] : '';

    $staleAbandoned = $queue->abandonStaleIncomplete();
    $out['stale']['count'] = count($staleAbandoned);
    $out['stale']['ids'] = array_column($staleAbandoned, 'job_id');
    $out['stale']['includes_stale2'] = $stale2Id !== '' && in_array($stale2Id, $out['stale']['ids'], true);
    $out['stale']['spares_fresh'] = $freshId === '' || !in_array($freshId, $out['stale']['ids'], true);

    if ($freshId !== '') {
        $queue->updateJob($freshId, [
            'status' => DeployWebhookJobQueue::STATUS_ABANDONED,
            'message' => $stamp . '-cleanup',
            'finished_at' => time(),
        ]);
    }

    $out['ok'] = $out['supersede']['includes_running']
        && $out['stale']['includes_stale2']
        && $out['stale']['spares_fresh']
        && $out['errors'] === [];
    $out['queue_dir'] = $dir;
    $out['stamp'] = $stamp;
} catch (Throwable $e) {
    $out['errors'][] = $e->getMessage();
    $out['ok'] = false;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit($out['ok'] ? 0 : 1);
