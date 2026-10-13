<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Single-flight: concurrent webhook must queue, never race Git / auto-rollback.
 */
final class DeployReleaseFlightGateContractTest extends TestCase
{
    public function testFlightGateAndBusyQueueWiring(): void
    {
        $base = dirname(__DIR__, 3);
        self::assertFileExists($base . '/Service/DeployReleaseFlightGate.php');
        $gate = (string)file_get_contents($base . '/Service/DeployReleaseFlightGate.php');
        self::assertStringContainsString('release.lock', $gate);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $gate);
        self::assertStringContainsString("BUSY_MESSAGE = 'release_busy'", $gate);

        $orch = (string)file_get_contents($base . '/Service/DeployOrchestratorService.php');
        self::assertStringContainsString('DeployReleaseFlightGate', $orch);
        self::assertStringContainsString('tryAcquire', $orch);
        self::assertStringContainsString("'busy' => true", $orch);
        self::assertStringContainsString('flightGate->release()', $orch);
        // Busy before history/Git: message constant, no auto-rollback path for busy.
        self::assertMatchesRegularExpression(
            '/tryAcquire\(\$deployRoot\).*BUSY_MESSAGE/s',
            $orch
        );

        $dispatcher = (string)file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
        self::assertStringContainsString('hasInFlight()', $dispatcher);
        self::assertStringContainsString('PHASE_DEFERRED', $dispatcher);
        self::assertStringContainsString('release_busy', $dispatcher);

        $queue = (string)file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
        self::assertStringContainsString('function hasInFlight', $queue);
        self::assertStringContainsString('dispatched_external', $queue);

        $releaseCli = (string)file_get_contents($base . '/Console/Deploy/Release.php');
        self::assertStringContainsString("status' => 'pending'", $releaseCli);
        self::assertStringContainsString('release_cli_busy', $releaseCli);

        $reconciler = (string)file_get_contents($base . '/Service/DeployWebhookExternalJobReconciler.php');
        self::assertStringContainsString("'kind' => 'busy'", $reconciler);
        self::assertStringContainsString('requeued busy', $reconciler);

        $drain = (string)file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');
        self::assertStringContainsString('hasInFlight()', $drain);
        self::assertStringContainsString('single-flight', $drain);

        $notifier = (string)file_get_contents($base . '/Service/DeployReleaseNotifier.php');
        self::assertStringContainsString("PHASE_DEFERRED = 'deferred'", $notifier);
        self::assertStringContainsString('已排队等待进行中发布', $notifier);
    }
}
