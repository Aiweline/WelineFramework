<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployProjectCommandPolicyService;
use Weline\Deploy\Service\DeployReleaseHistoryService;
use Weline\Deploy\Service\DeployWebhookExternalJobReconciler;
use Weline\Deploy\Service\DeployWebhookPierceGate;
use Weline\Framework\Http\Fpc\FpcPierceStamp;

/**
 * Aggregated contract suite (merged from 13 *ContractTest files).
 * Observe one overall pass/fail via testAllDeployServiceContractsPass().
 */
final class DeployServiceContractsTest extends TestCase
{
    public function testAllDeployServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'DeployGitVersionNoForcedBackup::testOrchestratorDoesNotForceProdSiteTar' => function () {
            $this->assert_DeployGitVersionNoForcedBackup_testOrchestratorDoesNotForceProdSiteTar();
        },
            'DeployGitVersionNoForcedBackup::testCliReleaseDefaultsToNoBackupUnlessBackupFlag' => function () {
            $this->assert_DeployGitVersionNoForcedBackup_testCliReleaseDefaultsToNoBackupUnlessBackupFlag();
        },
            'DeployGitVersionNoForcedBackup::testSiteBackupExcludesVarBackupDirectory' => function () {
            $this->assert_DeployGitVersionNoForcedBackup_testSiteBackupExcludesVarBackupDirectory();
        },
            'DeployPostDeployPolicy::testSetupUpgradeAndDeployUpgradeAreAllowed' => function () {
            $this->assert_DeployPostDeployPolicy_testSetupUpgradeAndDeployUpgradeAreAllowed();
        },
            'DeployPostDeployPolicy::testEmptyNormalizesToEmptyString' => function () {
            $this->assert_DeployPostDeployPolicy_testEmptyNormalizesToEmptyString();
        },
            'DeployPostDeployPolicy::testOrchestratorDefaultsEmptyPostDeployToSetupUpgrade' => function () {
            $this->assert_DeployPostDeployPolicy_testOrchestratorDefaultsEmptyPostDeployToSetupUpgrade();
        },
            'DeployPostDeployPolicy::testKeepMaintenanceFlagAllowedAndInjected' => function () {
            $this->assert_DeployPostDeployPolicy_testKeepMaintenanceFlagAllowedAndInjected();
        },
            'DeployMaintenanceWindow::testOrchestratorAndBuildOwnRuntimeWindow' => function () {
            $this->assert_DeployMaintenanceWindow_testOrchestratorAndBuildOwnRuntimeWindow();
        },
            'DeployPostDeployPolicy::testReleaseAfterDoesNotTouchFpcOrThemeStaticVersion' => function () {
            $this->assert_DeployPostDeployPolicy_testReleaseAfterDoesNotTouchFpcOrThemeStaticVersion();
        },
            'DeployPostDeployPolicy::testCoreUpdateDocumentsFollowUpUpgrade' => function () {
            $this->assert_DeployPostDeployPolicy_testCoreUpdateDocumentsFollowUpUpgrade();
        },
            'DeployProtectedTrackedFiles::testSnapshotUsesLsFilesAndCopiesTrackedFilesOnly' => function () {
            $this->assert_DeployProtectedTrackedFiles_testSnapshotUsesLsFilesAndCopiesTrackedFilesOnly();
        },
            'DeployProtectedTrackedFiles::testRestoreNeverRemovePathProtectRootsAndUnlinksTrackedExtras' => function () {
            $this->assert_DeployProtectedTrackedFiles_testRestoreNeverRemovePathProtectRootsAndUnlinksTrackedExtras();
        },
            'DeployProtectedTrackedFiles::testListTrackedFilesFailsClosedOnLsFilesError' => function () {
            $this->assert_DeployProtectedTrackedFiles_testListTrackedFilesFailsClosedOnLsFilesError();
        },
            'DeployReleaseAutoRollback::testOrchestratorCatchPathInvokesAutoRollbackHelper' => function () {
            $this->assert_DeployReleaseAutoRollback_testOrchestratorCatchPathInvokesAutoRollbackHelper();
        },
            'DeployReleaseAutoRollback::testGitMetadataSupportsLocalRestoreWithoutFetch' => function () {
            $this->assert_DeployReleaseAutoRollback_testGitMetadataSupportsLocalRestoreWithoutFetch();
        },
            'DeployReleaseFlightGate::testFlightGateAndBusyQueueWiring' => function () {
            $this->assert_DeployReleaseFlightGate_testFlightGateAndBusyQueueWiring();
        },
            'DeployReleaseMarkFailedTruncate::testMarkFailedUsesTruncateHelperAndMaxCharsConstants' => function () {
            $this->assert_DeployReleaseMarkFailedTruncate_testMarkFailedUsesTruncateHelperAndMaxCharsConstants();
        },
            'DeployReleaseMarkFailedTruncate::testTruncateFailedMessagesCapsErrorAndOutputTail' => function () {
            $this->assert_DeployReleaseMarkFailedTruncate_testTruncateFailedMessagesCapsErrorAndOutputTail();
        },
            'DeployReleaseMarkFailedTruncate::testTruncateFailedMessagesCapsStandaloneOutputTail' => function () {
            $this->assert_DeployReleaseMarkFailedTruncate_testTruncateFailedMessagesCapsStandaloneOutputTail();
        },
            'DeployReleaseMarkFailedTruncate::testTruncateFailedMessagesLeavesShortErrorUnchanged' => function () {
            $this->assert_DeployReleaseMarkFailedTruncate_testTruncateFailedMessagesLeavesShortErrorUnchanged();
        },
            'DeployReleaseNotifier::testNotifierAndTopicProviderExistWithRequiredPhases' => function () {
            $this->assert_DeployReleaseNotifier_testNotifierAndTopicProviderExistWithRequiredPhases();
        },
            'DeployReleaseNotifier::testOrchestratorAndAsyncPathsWireNotifier' => function () {
            $this->assert_DeployReleaseNotifier_testOrchestratorAndAsyncPathsWireNotifier();
        },
            'DeploySkipWorktreeGate::testGitMetadataAndOrchestratorWireSkipWorktreeGate' => function () {
            $this->assert_DeploySkipWorktreeGate_testGitMetadataAndOrchestratorWireSkipWorktreeGate();
        },
            'DeployWebhookAsyncAck::testWebhookReleaseReturnsAcceptedAsyncAndDoesNotCallOrchestratorSync' => function () {
            $this->assert_DeployWebhookAsyncAck_testWebhookReleaseReturnsAcceptedAsyncAndDoesNotCallOrchestratorSync();
        },
            'DeployWebhookAsyncAck::testAsyncDispatcherSpawnsDetachedDeployRelease' => function () {
            $this->assert_DeployWebhookAsyncAck_testAsyncDispatcherSpawnsDetachedDeployRelease();
        },
            'DeployWebhookExpectWatch::testExpectWatchServiceAndCronExist' => function () {
            $this->assert_DeployWebhookExpectWatch_testExpectWatchServiceAndCronExist();
        },
            'DeployWebhookExpectWatch::testTipArrivalMustNotUseBareFindByRefAlone' => function () {
            $this->assert_DeployWebhookExpectWatch_testTipArrivalMustNotUseBareFindByRefAlone();
        },
            'DeployWebhookExpectWatch::testNormalizeGraceIsWallClockBounded' => function () {
            $this->assert_DeployWebhookExpectWatch_testNormalizeGraceIsWallClockBounded();
        },
            'DeployWebhookExternalReconcile::testReconcilerAndWiringExist' => function () {
            $this->assert_DeployWebhookExternalReconcile_testReconcilerAndWiringExist();
        },
            'DeployWebhookExternalReconcile::testReadEvidenceParsesTrailerAndLegacySuccess' => function () {
            $this->assert_DeployWebhookExternalReconcile_testReadEvidenceParsesTrailerAndLegacySuccess();
        },
            'DeployWebhookPierceGate::testSourcePinsWlsGateAndAckKey' => function () {
            $this->assert_DeployWebhookPierceGate_testSourcePinsWlsGateAndAckKey();
        },
            'DeployWebhookPierceGate::testConfigSavePreservesAckFingerprint' => function () {
            $this->assert_DeployWebhookPierceGate_testConfigSavePreservesAckFingerprint();
        },
            'DeployWebhookPierceGate::testAppendPierceQueryPreservesPathAndSetsQuery' => function () {
            $this->assert_DeployWebhookPierceGate_testAppendPierceQueryPreservesPathAndSetsQuery();
        },
            'DeployWebhookPierceGate::testAppendPierceSkippedWhenNotWlsMode' => function () {
            $this->assert_DeployWebhookPierceGate_testAppendPierceSkippedWhenNotWlsMode();
        },
            'DeployWebhookPierceGate::testAckPendingClearsAfterAcknowledge' => function () {
            $this->assert_DeployWebhookPierceGate_testAckPendingClearsAfterAcknowledge();
        },
            'DeployWebhookQueue::testQueueAndDrainAndTriggerPath' => function () {
            $this->assert_DeployWebhookQueue_testQueueAndDrainAndTriggerPath();
        },
        ];
    }

private function assert_DeployGitVersionNoForcedBackup_testOrchestratorDoesNotForceProdSiteTar(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployOrchestratorService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('return !$noBackup;', $source);
    self::assertStringContainsString("no_backup'] ?? true", $source);
    // Must not reintroduce "prod always backup" gate inside shouldRunBackup.
    if (preg_match('/function shouldRunBackup\(bool \$noBackup\): bool\s*\{(?P<body>.*?)\n    \}/s', $source, $m) === 1) {
        self::assertStringNotContainsString('isProductionDeploy()', $m['body']);
        self::assertStringContainsString('return !$noBackup;', $m['body']);
    } else {
        self::fail('shouldRunBackup not found');
    }
}

private function assert_DeployGitVersionNoForcedBackup_testCliReleaseDefaultsToNoBackupUnlessBackupFlag(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Console/Deploy/Release.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("\$noBackup    = !isset(\$args['backup']);", $source);
    self::assertStringContainsString("'--backup'", $source);
}

private function assert_DeployGitVersionNoForcedBackup_testSiteBackupExcludesVarBackupDirectory(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeploySiteBackupService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("exclude='var/backup'", $source);
}

private function assert_DeployPostDeployPolicy_testSetupUpgradeAndDeployUpgradeAreAllowed(): void
{
    $policy = new DeployProjectCommandPolicyService();
    self::assertSame('php bin/w setup:upgrade', $policy->normalizePostDeployCommand('php bin/w setup:upgrade'));
    self::assertSame('php bin/w deploy:upgrade', $policy->normalizePostDeployCommand('php bin/w deploy:upgrade'));
    self::assertSame('php bin/w setup:upgrade && php bin/w server:reload -r', $policy->normalizePostDeployCommand('php bin/w setup:upgrade && php bin/w server:reload -r'));
}

private function assert_DeployPostDeployPolicy_testEmptyNormalizesToEmptyString(): void
{
    $policy = new DeployProjectCommandPolicyService();
    self::assertSame('', $policy->normalizePostDeployCommand(''));
}

private function assert_DeployPostDeployPolicy_testOrchestratorDefaultsEmptyPostDeployToSetupUpgrade(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy/Service/DeployOrchestratorService.php');
    self::assertStringContainsString("php bin/w setup:upgrade", $src);
    self::assertStringContainsString('POST_DEPLOY 为空', $src);
    self::assertStringContainsString('默认执行 setup:upgrade', $src);
}

private function assert_DeployPostDeployPolicy_testKeepMaintenanceFlagAllowedAndInjected(): void
{
    $policy = new DeployProjectCommandPolicyService();
    self::assertSame(
        'php bin/w setup:upgrade --keep-maintenance',
        $policy->normalizePostDeployCommand('php bin/w setup:upgrade --keep-maintenance')
    );
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy/Service/DeployOrchestratorService.php');
    self::assertStringContainsString('ensureKeepMaintenanceOnSetupUpgrade', $src);
    self::assertStringContainsString('--keep-maintenance', $src);
    $policySrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy/Service/DeployProjectCommandPolicyService.php');
    self::assertStringContainsString("'--keep-maintenance' => true", $policySrc);
}

private function assert_DeployMaintenanceWindow_testOrchestratorAndBuildOwnRuntimeWindow(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    $window = (string) file_get_contents($base . '/Service/DeployMaintenanceWindowService.php');
    self::assertStringContainsString('enableRuntimeGate', $window);
    self::assertStringContainsString('disableRuntimeGate', $window);
    self::assertStringContainsString('deploy:maintenance-window', $window);
    self::assertStringNotContainsString('enableFull', $window);
    self::assertStringNotContainsString('beginWave', $window);

    $orch = (string) file_get_contents($base . '/Service/DeployOrchestratorService.php');
    self::assertStringContainsString('DeployMaintenanceWindowService', $orch);
    self::assertStringContainsString('$this->maintenanceWindow->begin', $orch);
    self::assertStringContainsString('$this->maintenanceWindow->end', $orch);
    self::assertGreaterThanOrEqual(2, substr_count($orch, '$this->maintenanceWindow->begin'));
    self::assertGreaterThanOrEqual(2, substr_count($orch, '$this->maintenanceWindow->end'));

    $build = (string) file_get_contents($base . '/Console/Deploy/Build.php');
    self::assertStringContainsString('DeployMaintenanceWindowService', $build);
    self::assertStringContainsString('$maintenanceWindow->begin', $build);
    self::assertStringContainsString('$maintenanceWindow->end', $build);

    $module = (string) file_get_contents($base . '/etc/module.php');
    self::assertStringContainsString("'Weline_Maintenance'", $module);
}

private function assert_DeployPostDeployPolicy_testReleaseAfterDoesNotTouchFpcOrThemeStaticVersion(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy/Observer/ReleaseAfter.php');
    self::assertStringNotContainsString('use Weline\Framework\Deploy\DeployFpcInvalidation', $src);
    self::assertStringNotContainsString("setConfig('theme_static_version'", $src);
    self::assertStringNotContainsString('->afterRelease(', $src);
    self::assertStringNotContainsString('recursiveCopy', $src);
    self::assertStringContainsString('Intentionally empty', $src);
}

private function assert_DeployPostDeployPolicy_testCoreUpdateDocumentsFollowUpUpgrade(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy/Console/Update/Core.php');
    self::assertStringContainsString('setup:upgrade', $src);
    self::assertStringContainsString('deploy:upgrade', $src);
    self::assertStringContainsString('不算静态发布完成', $src);
}

private function assert_DeployProtectedTrackedFiles_testSnapshotUsesLsFilesAndCopiesTrackedFilesOnly(): void
{
    $source = $this->orchestratorSource_DeployProtectedTrackedFiles();
    self::assertStringContainsString('listTrackedFilesUnder', $source);
    self::assertStringContainsString("['git', 'ls-files', '-z', '--', \$relativePath]", $source);
    if (preg_match('/function snapshotProtectedPaths\(string \$deployRoot\): string\s*\{(?P<body>.*?)\n    \}/s', $source, $m) !== 1) {
        self::fail('snapshotProtectedPaths not found');
    }
    $body = $m['body'];
    self::assertStringContainsString('listTrackedFilesUnder', $body);
    self::assertStringContainsString('copyPath($source, $target)', $body);
    // Must not whole-tree copy the protect root directory without ls-files.
    self::assertStringNotContainsString("\$source = \$deployRoot . DS . str_replace('/', DS, \$relativePath);\n            if (!file_exists(\$source)) {\n                continue;\n            }\n            \$target = \$snapshotRoot . str_replace('/', DS, \$relativePath);\n            \$this->copyPath(\$source, \$target);", $body);
}

private function assert_DeployProtectedTrackedFiles_testRestoreNeverRemovePathProtectRootsAndUnlinksTrackedExtras(): void
{
    $source = $this->orchestratorSource_DeployProtectedTrackedFiles();
    if (preg_match('/function restoreProtectedPaths\(string \$deployRoot, string \$snapshotId\): void\s*\{(?P<body>.*?)\n    \}/s', $source, $m) !== 1) {
        self::fail('restoreProtectedPaths not found');
    }
    $body = $m['body'];
    self::assertStringContainsString('listSnapshotFilesUnder', $body);
    self::assertStringContainsString('listTrackedFilesUnder', $body);
    self::assertStringContainsString('@unlink($path)', $body);
    self::assertStringContainsString('$this->removePath($snapshotRoot)', $body);
    // Forbidden: wipe protect root tree before copy.
    self::assertStringNotContainsString('removePath($target)', $body);
    self::assertStringNotContainsString('if (is_dir($source))', $body);
}

private function assert_DeployProtectedTrackedFiles_testListTrackedFilesFailsClosedOnLsFilesError(): void
{
    $source = $this->orchestratorSource_DeployProtectedTrackedFiles();
    if (preg_match('/function listTrackedFilesUnder\(string \$deployRoot, string \$relativePath\): array\s*\{(?P<body>.*?)\n    \}/s', $source, $m) !== 1) {
        self::fail('listTrackedFilesUnder not found');
    }
    $body = $m['body'];
    self::assertStringContainsString('ls-files', $body);
    self::assertStringContainsString('$exitCode !== 0', $body);
    self::assertStringContainsString('RuntimeException', $body);
    // No silent whole-tree fallback.
    self::assertStringNotContainsString('RecursiveDirectoryIterator', $body);
    self::assertStringNotContainsString('copyPath', $body);
}

private function orchestratorSource_DeployProtectedTrackedFiles(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployOrchestratorService.php';
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function assert_DeployReleaseAutoRollback_testOrchestratorCatchPathInvokesAutoRollbackHelper(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployOrchestratorService.php');
    self::assertStringContainsString('autoRollbackAfterReleaseFailure', $source);
    self::assertStringContainsString("'auto_rollback'", $source);
    self::assertStringContainsString('restoreLocalCommit', $source);
    self::assertStringContainsString('$gitUpdateStarted = true', $source);
    self::assertStringContainsString('$versionStampWritten = true', $source);
    self::assertStringContainsString('isProductionDeploy', $source);
    self::assertStringContainsString('开发环境跳过发布失败自动回滚', $source);
}

private function assert_DeployReleaseAutoRollback_testGitMetadataSupportsLocalRestoreWithoutFetch(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployGitMetadataService.php');
    $method = $this->extractMethod_DeployReleaseAutoRollback($source, 'restoreLocalCommit');
    self::assertNotSame('', $method);
    self::assertStringNotContainsString('->fetch(', $method);
    self::assertStringContainsString("'checkout', '-B'", $method);
    self::assertStringContainsString("'--detach'", $method);
}

private function extractMethod_DeployReleaseAutoRollback(string $source, string $name): string
{
    $start = strpos($source, 'public function ' . $name . '(');
    self::assertNotFalse($start, 'method ' . $name . ' not found');
    $brace = strpos($source, '{', $start);
    self::assertNotFalse($brace);
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    self::fail('unclosed method ' . $name);
}

private function assert_DeployReleaseFlightGate_testFlightGateAndBusyQueueWiring(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    self::assertFileExists($base . '/Service/DeployReleaseFlightGate.php');
    $gate = (string) file_get_contents($base . '/Service/DeployReleaseFlightGate.php');
    self::assertStringContainsString('release.lock', $gate);
    self::assertStringContainsString('LOCK_EX | LOCK_NB', $gate);
    self::assertStringContainsString("BUSY_MESSAGE = 'release_busy'", $gate);
    $orch = (string) file_get_contents($base . '/Service/DeployOrchestratorService.php');
    self::assertStringContainsString('DeployReleaseFlightGate', $orch);
    self::assertStringContainsString('tryAcquire', $orch);
    self::assertStringContainsString("'busy' => true", $orch);
    self::assertStringContainsString('flightGate->release()', $orch);
    // Busy before history/Git: message constant, no auto-rollback path for busy.
    self::assertMatchesRegularExpression('/tryAcquire\(\$deployRoot\).*BUSY_MESSAGE/s', $orch);
    $dispatcher = (string) file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
    self::assertStringContainsString('hasInFlight()', $dispatcher);
    self::assertStringContainsString('PHASE_DEFERRED', $dispatcher);
    self::assertStringContainsString('release_busy', $dispatcher);
    $queue = (string) file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
    self::assertStringContainsString('function hasInFlight', $queue);
    self::assertStringContainsString('dispatched_external', $queue);
    $releaseCli = (string) file_get_contents($base . '/Console/Deploy/Release.php');
    self::assertStringContainsString("status' => 'pending'", $releaseCli);
    self::assertStringContainsString('release_cli_busy', $releaseCli);
    $reconciler = (string) file_get_contents($base . '/Service/DeployWebhookExternalJobReconciler.php');
    self::assertStringContainsString("'kind' => 'busy'", $reconciler);
    self::assertStringContainsString('requeued busy', $reconciler);
    $drain = (string) file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');
    self::assertStringContainsString('hasInFlight()', $drain);
    self::assertStringContainsString('single-flight', $drain);
    $notifier = (string) file_get_contents($base . '/Service/DeployReleaseNotifier.php');
    self::assertStringContainsString("PHASE_DEFERRED = 'deferred'", $notifier);
    self::assertStringContainsString('已排队等待进行中发布', $notifier);
}

private function assert_DeployReleaseMarkFailedTruncate_testMarkFailedUsesTruncateHelperAndMaxCharsConstants(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployReleaseHistoryService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('ERROR_MESSAGE_MAX_CHARS = 500', $source);
    self::assertStringContainsString('OUTPUT_TAIL_MAX_CHARS = 1000', $source);
    self::assertStringContainsString('function truncateFailedMessages(', $source);
    self::assertStringContainsString('function clipUtf8(', $source);
    self::assertStringContainsString('$this->truncateFailedMessages($errorMessage, $outputTail)', $source);
    self::assertStringContainsString('schema_fields_OUTPUT_TAIL', $source);
}

private function assert_DeployReleaseMarkFailedTruncate_testTruncateFailedMessagesCapsErrorAndOutputTail(): void
{
    $service = new DeployReleaseHistoryService();
    $long = str_repeat('x', DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS + 400);
    $result = $service->truncateFailedMessages($long, 'existing-tail');
    self::assertSame(DeployReleaseHistoryService::ERROR_MESSAGE_MAX_CHARS, mb_strlen($result['error_message'], 'UTF-8'));
    self::assertSame(DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS, mb_strlen($result['output_tail'], 'UTF-8'));
    // Prefer error-summary first segment (not the far end of the log).
    self::assertSame(mb_substr($long, 0, DeployReleaseHistoryService::ERROR_MESSAGE_MAX_CHARS, 'UTF-8'), $result['error_message']);
    self::assertSame(mb_substr($long, 0, DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS, 'UTF-8'), $result['output_tail']);
}

private function assert_DeployReleaseMarkFailedTruncate_testTruncateFailedMessagesCapsStandaloneOutputTail(): void
{
    $service = new DeployReleaseHistoryService();
    $longTail = str_repeat('y', DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS + 250);
    $result = $service->truncateFailedMessages('short failure', $longTail);
    self::assertSame('short failure', $result['error_message']);
    self::assertSame(DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS, mb_strlen($result['output_tail'], 'UTF-8'));
    self::assertSame(mb_substr($longTail, 0, DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS, 'UTF-8'), $result['output_tail']);
}

private function assert_DeployReleaseMarkFailedTruncate_testTruncateFailedMessagesLeavesShortErrorUnchanged(): void
{
    $service = new DeployReleaseHistoryService();
    $result = $service->truncateFailedMessages('short failure', '');
    self::assertSame('short failure', $result['error_message']);
    self::assertSame('', $result['output_tail']);
}

private function assert_DeployReleaseNotifier_testNotifierAndTopicProviderExistWithRequiredPhases(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    self::assertFileExists($base . '/Service/DeployReleaseNotifier.php');
    self::assertFileExists($base . '/Extends/NotificationTopicProvider.php');
    self::assertFileExists($base . '/extends.php');
    $notifier = (string) file_get_contents($base . '/Service/DeployReleaseNotifier.php');
    $provider = (string) file_get_contents($base . '/Extends/NotificationTopicProvider.php');
    $extends = (string) file_get_contents($base . '/extends.php');
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
    foreach (['queued', 'drain_start', 'started', 'backup', 'git_done', 'post_deploy_start', 'post_deploy_done', 'stamp_written', 'reload', 'success', 'failed', 'drain_skipped', 'webhook_miss', 'deferred', 'workdir_blocked', 'rollback_started', 'rollback_success', 'rollback_failed'] as $phase) {
        self::assertStringContainsString("'" . $phase . "'", $notifier, 'missing phase const/map: ' . $phase);
    }
}

private function assert_DeployReleaseNotifier_testOrchestratorAndAsyncPathsWireNotifier(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    $orch = (string) file_get_contents($base . '/Service/DeployOrchestratorService.php');
    $async = (string) file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
    $drain = (string) file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');
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
    $notifier = (string) file_get_contents($base . '/Service/DeployReleaseNotifier.php');
    self::assertStringContainsString("PHASE_ABANDONED = 'abandoned'", $notifier);
}

private function assert_DeploySkipWorktreeGate_testGitMetadataAndOrchestratorWireSkipWorktreeGate(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    $git = (string) file_get_contents($base . '/Service/DeployGitMetadataService.php');
    $orch = (string) file_get_contents($base . '/Service/DeployOrchestratorService.php');
    $notifier = (string) file_get_contents($base . '/Service/DeployReleaseNotifier.php');
    self::assertStringContainsString('function listSkipWorktreePaths', $git);
    self::assertStringContainsString("ls-files', '-v'", $git);
    self::assertStringContainsString('assertNoSkipWorktree', $orch);
    self::assertStringContainsString('listSkipWorktreePaths', $orch);
    self::assertStringContainsString('PHASE_WORKDIR_BLOCKED', $orch);
    // Must appear in both release and rollback git paths.
    self::assertSame(2, substr_count($orch, 'assertNoSkipWorktree($deployRoot'));
    // Must NOT auto-clear skip-worktree (no update-index write path).
    self::assertStringNotContainsString("update-index', '--no-skip-worktree'", $orch);
    self::assertStringNotContainsString("update-index', '--no-skip-worktree'", $git);
    self::assertStringNotContainsString('runGit([\'update-index\'', $orch);
    self::assertStringContainsString("PHASE_WORKDIR_BLOCKED = 'workdir_blocked'", $notifier);
    self::assertStringContainsString("self::PHASE_WORKDIR_BLOCKED => 'error'", $notifier);
    // Non-terminal: failed still fires after throw.
    self::assertStringNotContainsString('self::PHASE_WORKDIR_BLOCKED,', $notifier);
}

private function assert_DeployWebhookAsyncAck_testWebhookReleaseReturnsAcceptedAsyncAndDoesNotCallOrchestratorSync(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployWebhookReleaseService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('DeployWebhookAsyncDispatcher', $source);
    self::assertStringContainsString("'accepted' => true", $source);
    self::assertStringContainsString("'async' => true", $source);
    self::assertStringContainsString("'status' => 202", $source);
    self::assertStringNotContainsString('$this->orchestrator->release', $source);
    // Git-versioned deploy: detached CLI always passes --no-backup (third arg true).
    self::assertMatchesRegularExpression('/dispatchRelease\s*\(\s*\(string\)\$refInfo\[\'ref\'\]\s*,\s*\$force\s*,\s*true\s*\)/', $source);
    // tag/ref 透传：body 只带 tag / tag_name 时归一成 refs/tags/<tag>，仍走同一条 dispatchRelease，不新增轮询。
    self::assertStringContainsString('resolveWebhookRef', $source);
    self::assertStringContainsString("'refs/tags/' . \$tag", $source);
    self::assertStringContainsString('tag_name', $source);
}

private function assert_DeployWebhookAsyncAck_testAsyncDispatcherSpawnsDetachedDeployRelease(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployWebhookAsyncDispatcher.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('deploy:release', $source);
    self::assertStringContainsString('--no-backup', $source);
    self::assertStringContainsString('--job-id=', $source);
    self::assertStringContainsString('resolvePhpBinary', $source);
    self::assertStringContainsString('jobQueue', $source);
    self::assertStringContainsString('enqueue', $source);
    self::assertStringContainsString('systemdRunDetach', $source);
    self::assertStringContainsString("'transport' => 'queue'", $source);
    self::assertStringContainsString('DeployReleaseNotifier', $source);
    self::assertStringContainsString('PHASE_QUEUED', $source);
    self::assertStringContainsString('PHASE_DEFERRED', $source);
    self::assertStringContainsString('hasInFlight', $source);
    self::assertStringContainsString('DeployReleaseFlightGate', $source);
}

private function assert_DeployWebhookExpectWatch_testExpectWatchServiceAndCronExist(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    self::assertFileExists($base . '/Service/DeployWebhookExpectWatchService.php');
    // tag 到达轮询任务已删除：发布由 webhook 命中直接派发，不再每分钟轮询 git。
    self::assertFileDoesNotExist($base . '/Cron/DeployWebhookExpectCron.php');
    $watch = (string) file_get_contents($base . '/Service/DeployWebhookExpectWatchService.php');
    $queue = (string) file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
    $git = (string) file_get_contents($base . '/Service/DeployGitMetadataService.php');
    $history = (string) file_get_contents($base . '/Service/DeployReleaseHistoryService.php');
    $resolver = (string) file_get_contents($base . '/Service/DeployWebhookRefResolver.php');
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
    self::assertStringContainsString('isCronUpdateEnabled', $watch);
    self::assertStringContainsString('selectCronWinnerRef', $watch);
    self::assertStringContainsString('pickNewestTag', $watch);
    self::assertStringContainsString('asyncDispatcher->dispatchRelease', $watch);
    // Miss alert path must not call Orchestrator; cron path may dispatchRelease only.
    self::assertStringNotContainsString('orchestrator->release', $watch);
    self::assertStringNotContainsString('deploy:release', $watch);
    // Tip must not use local tracking-only helper as the sole source.
    self::assertStringNotContainsString('getRemoteBranchCommit', $watch);
    self::assertStringContainsString('function listRemoteBranchTip', $git);
    self::assertStringContainsString("ls-remote", $git);
    self::assertStringContainsString('function findByGitCommit', $history);
    self::assertStringContainsString('function findByRefCreatedSince', $queue);
    self::assertStringContainsString('public function resolveTriggerMode', $resolver);
}

private function assert_DeployWebhookExpectWatch_testTipArrivalMustNotUseBareFindByRefAlone(): void
{
    $watch = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployWebhookExpectWatchService.php');
    // Extract hasBranchTipArrived body roughly.
    self::assertMatchesRegularExpression('/function hasBranchTipArrived[\s\S]*?TIP_JOB_SKEW_SEC[\s\S]*?findByRefCreatedSince/', $watch);
    // Inside hasBranchTipArrived, bare findByRef( without CreatedSince is forbidden.
    if (!preg_match('/function hasBranchTipArrived\s*\([^)]*\)\s*:\s*bool\s*\{([\s\S]*?)\n    (?:\/\*\*|public function|private function)/', $watch, $m)) {
        self::fail('could not isolate hasBranchTipArrived body');
    }
    $body = $m[1];
    self::assertStringNotContainsString('->findByRef(', $body);
    self::assertStringContainsString('findByRefCreatedSince', $body);
    self::assertStringContainsString('findByGitCommit', $body);
    self::assertStringContainsString('TIP_JOB_SKEW_SEC', $body);
}

private function assert_DeployWebhookExpectWatch_testNormalizeGraceIsWallClockBounded(): void
{
    $min = \Weline\Deploy\Service\DeployWebhookExpectWatchService::MIN_GRACE_SEC;
    $max = \Weline\Deploy\Service\DeployWebhookExpectWatchService::MAX_GRACE_SEC;
    $def = \Weline\Deploy\Service\DeployWebhookExpectWatchService::DEFAULT_GRACE_SEC;
    self::assertSame(60, $min);
    self::assertSame(180, $max);
    self::assertSame(60, $def);
}

private function assert_DeployWebhookExternalReconcile_testReconcilerAndWiringExist(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    self::assertFileExists($base . '/Service/DeployWebhookExternalJobReconciler.php');
    $queue = (string) file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
    self::assertStringContainsString('function listByStatus', $queue);
    $async = (string) file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
    self::assertStringContainsString("--job-id=", $async);
    // job-id must be appended after enqueue (job_id known).
    self::assertMatchesRegularExpression('/\$jobId\s*=\s*\(string\)\$queued\[\'job_id\'\];\s*.*?\$argv\[\]\s*=\s*\'--job-id=\'\s*\.\s*\$jobId/s', $async);
    $release = (string) file_get_contents($base . '/Console/Deploy/Release.php');
    self::assertStringContainsString('DeployWebhookJobQueue', $release);
    self::assertStringContainsString('closeoutJob', $release);
    self::assertStringContainsString('printResultTrailer', $release);
    self::assertStringContainsString('RESULT_TRAILER_PREFIX', $release);
    $reconcilerSrc = (string) file_get_contents($base . '/Service/DeployWebhookExternalJobReconciler.php');
    self::assertStringContainsString("RESULT_TRAILER_PREFIX = '# deploy-job-result '", $reconcilerSrc);
    // updateJob before exit(1)
    $failPos = strpos($release, "exit(1)");
    $closePos = strpos($release, 'closeoutJob');
    self::assertNotFalse($failPos);
    self::assertNotFalse($closePos);
    self::assertLessThan($failPos, $closePos);
    $drain = (string) file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');
    self::assertStringContainsString('DeployWebhookExternalJobReconciler', $drain);
    self::assertStringContainsString('externalReconciler->reconcile', $drain);
    self::assertStringContainsString("'reconciled'", $drain);
}

private function assert_DeployWebhookExternalReconcile_testReadEvidenceParsesTrailerAndLegacySuccess(): void
{
    $reconciler = new DeployWebhookExternalJobReconciler(new class extends \Weline\Deploy\Service\DeployWebhookJobQueue
    {
    });
    $dir = sys_get_temp_dir() . '/weline-deploy-reconcile-' . bin2hex(random_bytes(4));
    self::assertTrue(@mkdir($dir, 0755, true) || is_dir($dir));
    $trailer = $dir . '/trailer.log';
    file_put_contents($trailer, "noise\n" . DeployWebhookExternalJobReconciler::RESULT_TRAILER_PREFIX . '{"success":true,"job_id":"j1","release_id":"r1","deploy_version":"abc123","message":""}' . "\n");
    $ev = $reconciler->readEvidence($trailer);
    self::assertSame('success', $ev['kind']);
    self::assertSame('abc123', $ev['deploy_version']);
    self::assertSame('r1', $ev['release_id']);
    $legacy = $dir . '/legacy.log';
    file_put_contents($legacy, "版本戳已写入：92e240e7b\n发布成功：92e240e7b\n");
    $ev2 = $reconciler->readEvidence($legacy);
    self::assertSame('success', $ev2['kind']);
    self::assertSame('92e240e7b', $ev2['deploy_version']);
    $failLog = $dir . '/fail.log';
    file_put_contents($failLog, DeployWebhookExternalJobReconciler::RESULT_TRAILER_PREFIX . '{"success":false,"job_id":"j2","release_id":"","deploy_version":"","message":"boom"}' . "\n");
    $ev3 = $reconciler->readEvidence($failLog);
    self::assertSame('failed', $ev3['kind']);
    self::assertSame('boom', $ev3['message']);
    @unlink($trailer);
    @unlink($legacy);
    @unlink($failLog);
    @rmdir($dir);
}

private function assert_DeployWebhookPierceGate_testSourcePinsWlsGateAndAckKey(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Service/DeployWebhookPierceGate.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('Runtime::isWls()', $source);
    self::assertStringContainsString('ACK_CONFIG_KEY', $source);
    self::assertStringContainsString('webhook_pierce_ack_hash', $source);
    self::assertStringContainsString('FpcPierceStamp::QUERY_NAME', $source);
    self::assertStringContainsString('appendPierceQuery', $source);
}

private function assert_DeployWebhookPierceGate_testConfigSavePreservesAckFingerprint(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy' . '/Controller/Backend/Config.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('DeployWebhookPierceGate::ACK_CONFIG_KEY', $source);
    self::assertStringContainsString('confirmPierceUrl', $source);
    self::assertStringContainsString('pierceAckPending', $source);
}

private function assert_DeployWebhookPierceGate_testAppendPierceQueryPreservesPathAndSetsQuery(): void
{
    $gate = new class extends DeployWebhookPierceGate
    {
        public function __construct()
        {
            // Skip parent: methods under test are overridden.
        }
        public function shouldAttachPierceToWebhookUrl(): bool
        {
            return true;
        }
        public function currentPierceSecret(): string
        {
            return \str_repeat('ab', 32);
        }
    };
    $url = $gate->appendPierceQuery('https://example.test/~wh~deadbeef');
    self::assertStringStartsWith('https://example.test/~wh~deadbeef?', $url);
    self::assertStringContainsString(FpcPierceStamp::QUERY_NAME . '=', $url);
    self::assertStringContainsString(\str_repeat('ab', 32), $url);
}

private function assert_DeployWebhookPierceGate_testAppendPierceSkippedWhenNotWlsMode(): void
{
    $gate = new class extends DeployWebhookPierceGate
    {
        public function __construct()
        {
        }
        public function shouldAttachPierceToWebhookUrl(): bool
        {
            return false;
        }
    };
    $base = 'https://example.test/~wh~deadbeef';
    self::assertSame($base, $gate->appendPierceQuery($base));
}

private function assert_DeployWebhookPierceGate_testAckPendingClearsAfterAcknowledge(): void
{
    $secret = \str_repeat('cd', 32);
    $ackBox = (object) ['hash' => ''];
    $gate = new class($secret, $ackBox) extends DeployWebhookPierceGate
    {
        public function __construct(private readonly string $secret, private readonly object $ackBox)
        {
        }
        public function shouldAttachPierceToWebhookUrl(): bool
        {
            return true;
        }
        public function currentPierceSecret(): string
        {
            return $this->secret;
        }
        public function acknowledgedFingerprint(): string
        {
            return (string) $this->ackBox->hash;
        }
        public function acknowledgeCurrentPierce(): bool
        {
            $this->ackBox->hash = $this->currentPierceFingerprint();
            return $this->ackBox->hash !== '';
        }
    };
    self::assertTrue($gate->isPierceAckPending());
    self::assertTrue($gate->acknowledgeCurrentPierce());
    self::assertFalse($gate->isPierceAckPending());
    self::assertSame(\hash('sha256', $secret), $ackBox->hash);
}

private function assert_DeployWebhookQueue_testQueueAndDrainAndTriggerPath(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Deploy';
    self::assertFileExists($base . '/Service/DeployWebhookJobQueue.php');
    self::assertFileExists($base . '/Service/DeployWebhookJobDrainService.php');
    // 两个轮询任务全部删除：发布由 webhook 命中直接派发，队列维护搬到触发路径。
    self::assertFileDoesNotExist($base . '/Cron/DeployWebhookDrainCron.php');
    self::assertFileDoesNotExist($base . '/Cron/DeployWebhookExpectCron.php');
    self::assertFileExists($base . '/Console/Deploy/Webhook/Drain.php');
    $queue = (string) file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
    self::assertStringContainsString('webhook-queue', $queue);
    self::assertStringContainsString("'status' => self::STATUS_PENDING", $queue);
    self::assertStringContainsString('function findByRef', $queue);
    self::assertStringContainsString('function listByStatus', $queue);
    self::assertStringContainsString('function hasInFlight', $queue);
    self::assertStringContainsString('function abandonIncomplete', $queue);
    self::assertStringContainsString('function abandonStaleIncomplete', $queue);
    self::assertStringContainsString('STATUS_ABANDONED', $queue);
    self::assertStringContainsString('STALE_INCOMPLETE_SECONDS = 1800', $queue);
    $dispatcher = (string) file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
    self::assertStringContainsString('abandonIncomplete(', $dispatcher);
    self::assertStringContainsString('abandonStaleIncomplete(', $dispatcher);
    self::assertStringContainsString('superseded_by_newer_deploy', $dispatcher);
    self::assertStringContainsString('PHASE_ABANDONED', $dispatcher);
    // webhook 触发路径自带维护：有界 reconcile + abandonStaleIncomplete，失败不阻断本次发布。
    self::assertStringContainsString('DeployWebhookExternalJobReconciler', $dispatcher);
    self::assertStringContainsString('MAINTENANCE_RECONCILE_LIMIT = 20', $dispatcher);
    self::assertStringContainsString('externalReconciler->reconcile(self::MAINTENANCE_RECONCILE_LIMIT)', $dispatcher);
    self::assertStringContainsString('$this->reconcileExitedExternalJobs($logPath);', $dispatcher);
    self::assertStringContainsString('$stale = $this->jobQueue->abandonStaleIncomplete();', $dispatcher);
    self::assertStringContainsString('catch (\Throwable $e) {', $dispatcher);
    self::assertStringContainsString('# maintenance_failed: ', $dispatcher);
    // 维护必须在入队之前。
    $maintainPos = strpos($dispatcher, 'reconcileExitedExternalJobs($logPath);');
    $enqueuePos = strpos($dispatcher, '$this->jobQueue->enqueue(');
    self::assertNotFalse($maintainPos);
    self::assertNotFalse($enqueuePos);
    self::assertLessThan($enqueuePos, $maintainPos);
    $drain = (string) file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');
    self::assertStringContainsString('abandonStaleIncomplete(', $drain);
    self::assertStringContainsString('PHASE_ABANDONED', $drain);
}
}
