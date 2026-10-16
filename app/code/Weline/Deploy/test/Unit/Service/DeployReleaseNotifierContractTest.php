<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * DeployReleaseNotifier: topic, phases, fail-open, non-terminal dedupe, terminal salt.
 */
final class DeployReleaseNotifierContractTest extends TestCase
{
    public function testNotifierAndTopicProviderExistWithRequiredPhases(): void
    {
        $base = dirname(__DIR__, 3);
        self::assertFileExists($base . '/Service/DeployReleaseNotifier.php');
        self::assertFileExists($base . '/Extends/NotificationTopicProvider.php');
        self::assertFileExists($base . '/extends.php');

        $notifier = (string)file_get_contents($base . '/Service/DeployReleaseNotifier.php');
        $provider = (string)file_get_contents($base . '/Extends/NotificationTopicProvider.php');
        $extends = (string)file_get_contents($base . '/extends.php');

        self::assertStringContainsString("TOPIC_DEPLOY_RELEASE = 'deploy_release'", $provider);
        self::assertStringContainsString('NotificationTopicProviderInterface', $extends);
        self::assertStringContainsString('w_msg(', $notifier);
        self::assertStringContainsString('fail-open', $notifier);
        self::assertStringContainsString('alreadyEmitted', $notifier);
        self::assertStringContainsString('TERMINAL_PHASES', $notifier);
        self::assertStringContainsString('dedupe_salt', $notifier);
        self::assertStringContainsString('resolveDeployRoot', $notifier);
        self::assertStringContainsString('titleRootHint', $notifier);
        self::assertStringContainsString("'deploy_root' => \$deployRoot", $notifier);
        self::assertStringContainsString("'project_root' => \$deployRoot", $notifier);

        foreach ([
            'queued',
            'drain_start',
            'started',
            'backup',
            'git_done',
            'post_deploy_start',
            'post_deploy_done',
            'stamp_written',
            'reload',
            'success',
            'failed',
            'drain_skipped',
            'webhook_miss',
            'deferred',
            'workdir_blocked',
            'rollback_started',
            'rollback_success',
            'rollback_failed',
        ] as $phase) {
            self::assertStringContainsString("'" . $phase . "'", $notifier, 'missing phase const/map: ' . $phase);
        }
    }

    public function testOrchestratorAndAsyncPathsWireNotifier(): void
    {
        $base = dirname(__DIR__, 3);
        $orch = (string)file_get_contents($base . '/Service/DeployOrchestratorService.php');
        $async = (string)file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
        $drain = (string)file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');

        self::assertStringContainsString('DeployReleaseNotifier', $orch);
        self::assertStringContainsString('PHASE_STARTED', $orch);
        self::assertStringContainsString('PHASE_SUCCESS', $orch);
        self::assertStringContainsString('PHASE_FAILED', $orch);
        self::assertStringContainsString('PHASE_ROLLBACK_STARTED', $orch);
        self::assertStringContainsString('markFailed($releaseId, $error, $outputTail)', $orch);

        self::assertStringContainsString('PHASE_QUEUED', $async);
        self::assertStringContainsString('PHASE_ABANDONED', $async);
        self::assertStringContainsString('PHASE_DRAIN_START', $drain);
        self::assertStringContainsString('PHASE_DRAIN_SKIPPED', $drain);

        $notifier = (string)file_get_contents($base . '/Service/DeployReleaseNotifier.php');
        self::assertStringContainsString("PHASE_ABANDONED = 'abandoned'", $notifier);
    }
}
