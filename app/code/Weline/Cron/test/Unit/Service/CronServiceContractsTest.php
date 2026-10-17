<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCronServiceContractsPass().
 */
final class CronServiceContractsTest extends TestCase
{
    public function testAllCronServiceContractsPass(): void
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
            'ManagedChildAdmission::testSoftAdmissionRemovedFromRunDispatcher' => function () {
            $this->setUpBeforeClass_ManagedChildAdmission();
            $this->assert_ManagedChildAdmission_testSoftAdmissionRemovedFromRunDispatcher();
        },
        ];
    }

private function setUpBeforeClass_ManagedChildAdmission(): void
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
}

private function assert_ManagedChildAdmission_testSoftAdmissionRemovedFromRunDispatcher(): void
{
    $path = \BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringNotContainsString('ManagedChildAdmission', $src);
    self::assertStringNotContainsString('hasAvailableSlot', $src);
    self::assertStringNotContainsString('releaseClaimedLaunchForAdmission', $src);
    self::assertStringNotContainsString('受管计划任务并发已达上限', $src);
    self::assertStringContainsString('claimTaskLaunch(', $src);
    self::assertStringContainsString('requiresManagedChildSerialization', $src);
    self::assertStringContainsString('no soft', $src);
    self::assertFileDoesNotExist(\BP . 'app/code/Weline/Cron/Service/ManagedChildAdmission.php');
    self::assertFileDoesNotExist(\BP . 'app/code/Weline/Cron/Service/HostCapacityProbe.php');
}
}
