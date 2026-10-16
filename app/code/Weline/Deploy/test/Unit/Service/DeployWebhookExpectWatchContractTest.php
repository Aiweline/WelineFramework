<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * ExpectWatch: prefix/mode, tip arrival SHA/time-window, kind state, fail-open.
 */
final class DeployWebhookExpectWatchContractTest extends TestCase
{
    public function testExpectWatchServiceAndCronExist(): void
    {
        $base = dirname(__DIR__, 3);
        self::assertFileExists($base . '/Service/DeployWebhookExpectWatchService.php');
        self::assertFileExists($base . '/Cron/DeployWebhookExpectCron.php');

        $watch = (string)file_get_contents($base . '/Service/DeployWebhookExpectWatchService.php');
        $cron = (string)file_get_contents($base . '/Cron/DeployWebhookExpectCron.php');
        $queue = (string)file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
        $git = (string)file_get_contents($base . '/Service/DeployGitMetadataService.php');
        $history = (string)file_get_contents($base . '/Service/DeployReleaseHistoryService.php');
        $resolver = (string)file_get_contents($base . '/Service/DeployWebhookRefResolver.php');

        self::assertStringContainsString("'* * * * *'", $cron);
        self::assertStringContainsString('DeployWebhookExpectWatchService', $cron);
        self::assertStringContainsString('webhook-expect.json', $watch);
        self::assertStringContainsString('DEFAULT_GRACE_SEC = 60', $watch);
        self::assertStringContainsString('KIND_TAG', $watch);
        self::assertStringContainsString('KIND_BRANCH_TIP', $watch);
        self::assertStringContainsString('resolveTriggerMode', $watch);
        self::assertStringContainsString('listRemoteBranchTip', $watch);
        self::assertStringContainsString('hasBranchTipArrived', $watch);
        self::assertStringContainsString('TIP_JOB_SKEW_SEC', $watch);
        self::assertStringContainsString('bootstrap_tip_sha', $watch);
        self::assertStringContainsString('PHASE_WEBHOOK_MISS', $watch);
        self::assertStringContainsString('branch_tip_webhook_miss_after_grace', $watch);
        self::assertStringContainsString('resolveNotifyDeployRoot', $watch);
        self::assertStringContainsString("'deploy_root' => \$this->resolveNotifyDeployRoot(\$config)", $watch);
        // Must not auto-deploy on miss.
        self::assertStringNotContainsString('deploy:release', $watch);
        self::assertStringNotContainsString('orchestrator->release', $watch);
        // Tip must not use local tracking-only helper as the sole source.
        self::assertStringNotContainsString('getRemoteBranchCommit', $watch);

        self::assertStringContainsString('function listRemoteBranchTip', $git);
        self::assertStringContainsString("ls-remote", $git);
        self::assertStringContainsString('function findByGitCommit', $history);
        self::assertStringContainsString('function findByRefCreatedSince', $queue);
        self::assertStringContainsString('public function resolveTriggerMode', $resolver);
    }

    public function testTipArrivalMustNotUseBareFindByRefAlone(): void
    {
        $watch = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DeployWebhookExpectWatchService.php');
        // Extract hasBranchTipArrived body roughly.
        self::assertMatchesRegularExpression(
            '/function hasBranchTipArrived[\s\S]*?TIP_JOB_SKEW_SEC[\s\S]*?findByRefCreatedSince/',
            $watch
        );
        // Inside hasBranchTipArrived, bare findByRef( without CreatedSince is forbidden.
        if (!preg_match(
            '/function hasBranchTipArrived\s*\([^)]*\)\s*:\s*bool\s*\{([\s\S]*?)\n    (?:\/\*\*|public function|private function)/',
            $watch,
            $m
        )) {
            self::fail('could not isolate hasBranchTipArrived body');
        }
        $body = $m[1];
        self::assertStringNotContainsString('->findByRef(', $body);
        self::assertStringContainsString('findByRefCreatedSince', $body);
        self::assertStringContainsString('findByGitCommit', $body);
        self::assertStringContainsString('TIP_JOB_SKEW_SEC', $body);
    }

    public function testNormalizeGraceIsWallClockBounded(): void
    {
        $min = \Weline\Deploy\Service\DeployWebhookExpectWatchService::MIN_GRACE_SEC;
        $max = \Weline\Deploy\Service\DeployWebhookExpectWatchService::MAX_GRACE_SEC;
        $def = \Weline\Deploy\Service\DeployWebhookExpectWatchService::DEFAULT_GRACE_SEC;
        self::assertSame(60, $min);
        self::assertSame(180, $max);
        self::assertSame(60, $def);
    }
}
