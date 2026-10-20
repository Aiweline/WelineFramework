<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\EarlyDatabaseAccessGate;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;
use Weline\Framework\Test\Console\E2e\CleanupBrowsers;
use Weline\Framework\Test\Service\E2eBrowserCleanupService;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllConsoleContractsPass().
 */
final class ConsoleContractsTest extends TestCase
{
    public function testAllConsoleContractsPass(): void
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
            'E2eBrowserCleanup::testCleanupCommandTipMentionsAutomationLeftovers' => function () {
            $this->assert_E2eBrowserCleanup_testCleanupCommandTipMentionsAutomationLeftovers();
        },
            'E2eBrowserCleanup::testCleanupServiceDryRunReturnsStructuredReport' => function () {
            $this->assert_E2eBrowserCleanup_testCleanupServiceDryRunReturnsStructuredReport();
        },
            'E2eBrowserCleanup::testCleanupScriptExistsAndExportsMatcherHelpers' => function () {
            $this->assert_E2eBrowserCleanup_testCleanupScriptExistsAndExportsMatcherHelpers();
        },
            'EarlyDatabaseAccessGateUpgradeWait::testSetupWithoutForceWaitsAndTimesOutWhileSharedHeld' => function () {
            $this->setUp_EarlyDatabaseAccessGateUpgradeWait();
            try {
                $this->assert_EarlyDatabaseAccessGateUpgradeWait_testSetupWithoutForceWaitsAndTimesOutWhileSharedHeld();
            } finally {
                $this->tearDown_EarlyDatabaseAccessGateUpgradeWait();
            }
        },
            'EarlyDatabaseAccessGateUpgradeWait::testForceDoesNotUseLongWaitWhenSharedHeldByNonCron' => function () {
            $this->setUp_EarlyDatabaseAccessGateUpgradeWait();
            try {
                $this->assert_EarlyDatabaseAccessGateUpgradeWait_testForceDoesNotUseLongWaitWhenSharedHeldByNonCron();
            } finally {
                $this->tearDown_EarlyDatabaseAccessGateUpgradeWait();
            }
        },
            'EarlyDatabaseAccessGateUpgradeWait::testHotSetupDoesNotPublishIntent' => function () {
            $this->setUp_EarlyDatabaseAccessGateUpgradeWait();
            try {
                $this->assert_EarlyDatabaseAccessGateUpgradeWait_testHotSetupDoesNotPublishIntent();
            } finally {
                $this->tearDown_EarlyDatabaseAccessGateUpgradeWait();
            }
        },
            'EarlyDatabaseAccessGateUpgradeWait::testForcePathIsDocumentedInGateSource' => function () {
            $this->setUp_EarlyDatabaseAccessGateUpgradeWait();
            try {
                $this->assert_EarlyDatabaseAccessGateUpgradeWait_testForcePathIsDocumentedInGateSource();
            } finally {
                $this->tearDown_EarlyDatabaseAccessGateUpgradeWait();
            }
        },
            'PhpUnitRunFalseGreen::testLegacyCommandRegistrationResolvesToCanonicalRunner' => function () {
            $this->assert_PhpUnitRunFalseGreen_testLegacyCommandRegistrationResolvesToCanonicalRunner();
        },
            'PhpUnitRunFalseGreen::testCanonicalRunnerRejectsZeroTestOutput' => function () {
            $this->assert_PhpUnitRunFalseGreen_testCanonicalRunnerRejectsZeroTestOutput();
        },
            'TestRunnerAllowsAnyDeployMode::testRunnersDoNotGateOnDeployMode' => function () {
            $this->assert_TestRunnerAllowsAnyDeployMode_testRunnersDoNotGateOnDeployMode();
        },
            'PhpCliMemoryFloor::testBinWDoesNotRaiseMemoryAtRuntime' => function () {
            $this->assert_PhpCliMemoryFloor_testBinWDoesNotRaiseMemoryAtRuntime();
        },
        ];
    }

private function assert_PhpCliMemoryFloor_testBinWDoesNotRaiseMemoryAtRuntime(): void
{
    $binW = (string) \file_get_contents(BP . 'bin/w');
    $binM = (string) \file_get_contents(BP . 'bin/m');
    self::assertStringContainsString('PhpCliRuntimePreflight::enforce', $binW);
    self::assertStringContainsString('PhpCliRuntimePreflight::enforce', $binM);

    $preflight = (string) \file_get_contents(
        BP . 'app/code/Weline/Framework/Console/PhpCliRuntimePreflight.php'
    );
    self::assertStringNotContainsString('raiseCliMemoryFloor', $preflight);
    self::assertStringNotContainsString('CLI_MEMORY_FLOOR', $preflight);
    self::assertStringNotContainsString('2048M', $preflight);
    self::assertStringNotContainsString("ini_set('memory_limit'", $preflight);
}

private function assert_E2eBrowserCleanup_testCleanupCommandTipMentionsAutomationLeftovers(): void
{
    $command = new CleanupBrowsers();
    $tip = $command->tip();
    self::assertIsString($tip);
    self::assertNotSame('', trim($tip));
    self::assertTrue(str_contains($tip, 'Playwright') || str_contains($tip, 'Chrome') || str_contains($tip, '浏览器'), 'tip should mention browser cleanup');
}

private function assert_E2eBrowserCleanup_testCleanupServiceDryRunReturnsStructuredReport(): void
{
    $service = new E2eBrowserCleanupService();
    $report = $service->cleanup(['dry_run' => true]);
    self::assertArrayHasKey('ok', $report);
    self::assertArrayHasKey('killedPids', $report);
    self::assertArrayHasKey('removedPaths', $report);
    self::assertArrayHasKey('closedTabs', $report);
    self::assertTrue($report['dryRun']);
    self::assertIsArray($report['killedPids']);
    self::assertIsArray($report['removedPaths']);
}

private function assert_E2eBrowserCleanup_testCleanupScriptExistsAndExportsMatcherHelpers(): void
{
    $script = BP . 'tests/e2e/framework/cleanup-orphaned-browsers.js';
    self::assertFileExists($script);
    $source = (string) file_get_contents($script);
    self::assertStringContainsString('isOrphanAutomationBrowser', $source);
    self::assertStringContainsString('chrome-headless-shell', $source);
    self::assertStringContainsString('Google Chrome.app/Contents/MacOS/Google Chrome', $source);
}

/** @var resource|null */
private $sharedHolder_EarlyDatabaseAccessGateUpgradeWait = null;

private function setUpBeforeClass_EarlyDatabaseAccessGateUpgradeWait(): void
{
    parent::setUpBeforeClass();
    if (!\defined('BP')) {
        $dir = __DIR__;
        while ($dir !== \dirname($dir)) {
            if (\is_file($dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
                \define('BP', $dir . DIRECTORY_SEPARATOR);
                break;
            }
            $dir = \dirname($dir);
        }
    }
    self::assertTrue(\defined('BP'), 'BP must resolve to repository root');
    require_once \BP . 'app/code/Weline/Framework/Setup/Lock/SetupDatabaseAccessLock.php';
    require_once \BP . 'app/code/Weline/Framework/Setup/Lock/SetupUpgradeIntent.php';
    require_once \BP . 'app/code/Weline/Framework/Phrase/DatabaseFreeTranslator.php';
    require_once \BP . 'app/code/Weline/Framework/Console/EarlyDatabaseAccessGate.php';
}

private function setUp_EarlyDatabaseAccessGateUpgradeWait(): void
{
    parent::setUp();
    SetupDatabaseAccessLock::releaseCliBootstrapLease();
    SetupUpgradeIntent::clearIfOwner();
    $intent = SetupUpgradeIntent::path();
    if (is_file($intent)) {
        @unlink($intent);
    }
    SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
}

private function tearDown_EarlyDatabaseAccessGateUpgradeWait(): void
{
    if (is_resource($this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait)) {
        @flock($this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait, LOCK_UN);
        @fclose($this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait);
        $this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait = null;
    }
    SetupDatabaseAccessLock::releaseCliBootstrapLease();
    SetupUpgradeIntent::clearIfOwner();
    $intent = SetupUpgradeIntent::path();
    if (is_file($intent)) {
        @unlink($intent);
    }
    SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
    parent::tearDown();
}

private function assert_EarlyDatabaseAccessGateUpgradeWait_testSetupWithoutForceWaitsAndTimesOutWhileSharedHeld(): void
{
    $lockPath = SetupDatabaseAccessLock::path();
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait = fopen($lockPath, 'c+b');
    self::assertIsResource($this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait);
    self::assertTrue(flock($this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait, LOCK_SH | LOCK_NB));
    SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(600);
    // No -f: cooperative wait path.
    $exit = EarlyDatabaseAccessGate::prepare(['bin/w', 's:up'], 600);
    self::assertSame(75, $exit);
    self::assertFalse(SetupUpgradeIntent::isOwnedByCurrentProcess());
    self::assertFalse(SetupUpgradeIntent::isActive());
}

private function assert_EarlyDatabaseAccessGateUpgradeWait_testForceDoesNotUseLongWaitWhenSharedHeldByNonCron(): void
{
    $lockPath = SetupDatabaseAccessLock::path();
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait = fopen($lockPath, 'c+b');
    self::assertIsResource($this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait);
    self::assertTrue(flock($this->sharedHolder_EarlyDatabaseAccessGateUpgradeWait, LOCK_SH | LOCK_NB));
    $started = hrtime(true);
    // -f: force path (~5s), must not fall into 900s cooperative wait.
    $exit = EarlyDatabaseAccessGate::prepare(['bin/w', 's:up', '-f']);
    $elapsedMs = (int) ((hrtime(true) - $started) / 1000000);
    self::assertSame(75, $exit);
    self::assertLessThan(12000, $elapsedMs);
    self::assertFalse(SetupUpgradeIntent::isActive());
}

private function assert_EarlyDatabaseAccessGateUpgradeWait_testHotSetupDoesNotPublishIntent(): void
{
    $exit = EarlyDatabaseAccessGate::prepare(['bin/w', 's:up', '--hot']);
    self::assertNull($exit);
    self::assertFalse(SetupUpgradeIntent::isActive());
    SetupDatabaseAccessLock::releaseCliBootstrapLease();
}

private function assert_EarlyDatabaseAccessGateUpgradeWait_testForcePathIsDocumentedInGateSource(): void
{
    $src = (string) file_get_contents(\BP . 'app/code/Weline/Framework/Console/EarlyDatabaseAccessGate.php');
    self::assertStringContainsString('acquireExclusiveForced', $src);
    self::assertStringContainsString('terminateCronLockHolders', $src);
    self::assertStringContainsString('FORCE_EXCLUSIVE_WAIT_MS', $src);
}

private function assert_PhpUnitRunFalseGreen_testLegacyCommandRegistrationResolvesToCanonicalRunner(): void
{
    $reflection = new \ReflectionClass(\Weline\Framework\UnitTest\Console\PhpUnit\Run::class);
    self::assertStringStartsWith(BP . 'app' . DS . 'code' . DS, (string) $reflection->getFileName(), 'phpunit:run 不得回落到 vendor 中的历史实现。');
    self::assertTrue($reflection->isSubclassOf(\Weline\Framework\Test\Console\PhpUnit\Run::class));
}

private function assert_PhpUnitRunFalseGreen_testCanonicalRunnerRejectsZeroTestOutput(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Test' . '/Console/PhpUnit/Run.php');
    self::assertStringContainsString('outputConfirmsTestExecution', $source);
    self::assertStringContainsString('测试运行器未确认执行任何测试', $source);
    self::assertStringContainsString('$exitCode = 2', $source);
    self::assertStringContainsString('$totalTests === 0 && $expectedTotalTests > 0', $source, '报告必须优先采用 PHPUnit 的实际 case 数，禁止出现超过 100% 的假统计。');
}

/** @return list<string> */
private function runnerSources_TestRunnerAllowsAnyDeployMode(): array
{
    // __DIR__ = …/Framework/Test/Unit/Console → Test root = dirname×2
    $testRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Framework/Test';
    $frameworkRoot = dirname($testRoot);
    return [$testRoot . '/Console/E2e/Run.php', $testRoot . '/Console/PhpUnit/Run.php', $testRoot . '/Console/PhpUnit/Status.php', $testRoot . '/Console/PhpUnit/Stop.php', $testRoot . '/Service/TestRunService.php', $frameworkRoot . '/UnitTest/Console/E2e/Run.php', $frameworkRoot . '/UnitTest/Console/PhpUnit/Status.php', $frameworkRoot . '/UnitTest/Console/PhpUnit/Stop.php'];
}

private function assert_TestRunnerAllowsAnyDeployMode_testRunnersDoNotGateOnDeployMode(): void
{
    foreach ($this->runnerSources_TestRunnerAllowsAnyDeployMode() as $path) {
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString("Env::system('deploy') !== 'dev'", $src, $path . ' must not refuse non-dev deploy');
        self::assertStringNotContainsString('非开发环境禁止运行', $src, $path . ' must not show deploy-mode forbid message');
        self::assertStringNotContainsString('assertDevDeploy', $src, $path . ' must not call assertDevDeploy');
    }
}
}
