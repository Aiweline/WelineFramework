<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Execute pending webhook release jobs in a CLI/cron process (not the WLS HTTP request cgroup).
 */
class DeployWebhookJobDrainService
{
    public function __construct(
        private readonly DeployWebhookJobQueue $queue,
        private readonly DeployWebhookRefResolver $refResolver,
        private readonly DeployOrchestratorService $orchestrator,
        private readonly DeployConfigService $configService,
        private readonly DeployReleaseNotifier $releaseNotifier,
    ) {
    }

    /**
     * @return array{processed:int,ok:int,failed:int,skipped:int,messages:list<string>}
     */
    public function drain(int $limit = 3): array
    {
        $processed = 0;
        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $messages = [];

        foreach ($this->queue->listPending($limit) as $job) {
            $processed++;
            $jobId = (string)($job['id'] ?? '');
            $ref = trim((string)($job['ref'] ?? ''));
            if ($jobId === '' || $ref === '') {
                $skipped++;
                $messages[] = 'skip invalid job';
                $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_DRAIN_SKIPPED, [
                    'job_id' => $jobId,
                    'ref' => $ref,
                    'trigger' => 'webhook',
                    'reason' => 'invalid_job',
                ]);
                continue;
            }

            if (!$this->queue->updateJob($jobId, ['status' => 'running', 'started_at' => time()])) {
                $failed++;
                $messages[] = $jobId . ': mark_running_failed';
                continue;
            }

            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_DRAIN_START, [
                'job_id' => $jobId,
                'ref' => $ref,
                'trigger' => 'webhook',
                'log_path' => (string)($job['log_path'] ?? ''),
            ]);

            try {
                $config = $this->configService->getWebhookShellConfig();
                if ($config === []) {
                    $config = $this->configService->getProjectDeployConfig();
                }
                $refInfo = $this->refResolver->resolve($ref, $config);
                if (!empty($refInfo['skipped'])) {
                    $reason = (string)($refInfo['reason'] ?? 'skipped');
                    $this->queue->updateJob($jobId, [
                        'status' => 'skipped',
                        'reason' => $reason,
                        'finished_at' => time(),
                    ]);
                    $skipped++;
                    $messages[] = $jobId . ': skipped ' . $reason;
                    $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_DRAIN_SKIPPED, [
                        'job_id' => $jobId,
                        'ref' => $ref,
                        'trigger' => 'webhook',
                        'reason' => $reason,
                    ]);
                    continue;
                }

                $force = !empty($job['force']);
                $noBackup = array_key_exists('no_backup', $job) ? (bool)$job['no_backup'] : true;
                $result = $this->orchestrator->release([
                    'trigger' => 'webhook',
                    'ref_type' => $refInfo['type'],
                    'ref' => $refInfo['ref'],
                    'deploy_version_hint' => $refInfo['deploy_version_hint'] ?? null,
                    'git_checkout' => $refInfo['git_checkout'] ?? null,
                    'git_tag' => ($refInfo['type'] === DeployWebhookRefResolver::TYPE_TAG)
                        ? ($refInfo['deploy_version_hint'] ?? null)
                        : null,
                    'force' => $force,
                    'no_backup' => $noBackup,
                    'printer' => null,
                    'config' => $config,
                ]);

                if (!empty($result['success'])) {
                    $this->queue->updateJob($jobId, [
                        'status' => 'done',
                        'release_id' => (string)($result['release_id'] ?? ''),
                        'deploy_version' => (string)($result['deploy_version'] ?? ''),
                        'finished_at' => time(),
                    ]);
                    $ok++;
                    $messages[] = $jobId . ': ok ' . (string)($result['deploy_version'] ?? '');
                } else {
                    $this->queue->updateJob($jobId, [
                        'status' => 'failed',
                        'message' => (string)($result['message'] ?? 'release_failed'),
                        'finished_at' => time(),
                    ]);
                    $failed++;
                    $messages[] = $jobId . ': fail ' . (string)($result['message'] ?? '');
                }
            } catch (\Throwable $e) {
                $this->queue->updateJob($jobId, [
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                    'finished_at' => time(),
                ]);
                $failed++;
                $messages[] = $jobId . ': exception ' . $e->getMessage();
            }
        }

        return compact('processed', 'ok', 'failed', 'skipped', 'messages');
    }
}
