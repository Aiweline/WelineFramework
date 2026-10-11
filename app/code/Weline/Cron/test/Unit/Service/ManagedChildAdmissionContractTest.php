<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Service\ManagedChildAdmission;

final class ManagedChildAdmissionContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
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

    public function testDefaultsAndBounds(): void
    {
        self::assertSame(3, ManagedChildAdmission::DEFAULT_MAX);
        self::assertSame(1, ManagedChildAdmission::MIN_MAX);
        self::assertSame(16, ManagedChildAdmission::MAX_MAX);
        self::assertSame('cron/max_concurrent_managed_children', ManagedChildAdmission::CONFIG_KEY);
    }

    public function testNormalizeMaxClampsInvalidConfig(): void
    {
        self::assertSame(ManagedChildAdmission::DEFAULT_MAX, ManagedChildAdmission::normalizeMax(0));
        self::assertSame(ManagedChildAdmission::DEFAULT_MAX, ManagedChildAdmission::normalizeMax(-1));
        self::assertSame(1, ManagedChildAdmission::normalizeMax(1));
        self::assertSame(3, ManagedChildAdmission::normalizeMax(3));
        self::assertSame(ManagedChildAdmission::MAX_MAX, ManagedChildAdmission::normalizeMax(99));
    }

    public function testRunGuardsAdmissionBeforeClaim(): void
    {
        $path = \BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('ManagedChildAdmission', $src);
        self::assertStringContainsString('hasAvailableSlot', $src);
        self::assertStringContainsString('releaseClaimedLaunchForAdmission', $src);
        self::assertStringContainsString('受管计划任务并发已达上限', $src);

        $admissionPos = \strpos($src, 'hasAvailableSlot');
        $claimPos = \strpos($src, 'claimTaskLaunch(');
        $releasePos = \strpos($src, 'releaseClaimedLaunchForAdmission');
        self::assertNotFalse($admissionPos);
        self::assertNotFalse($claimPos);
        self::assertNotFalse($releasePos);
        self::assertLessThan($claimPos, $admissionPos);
        self::assertGreaterThan($claimPos, $releasePos);

        $servicePath = \BP . 'app/code/Weline/Cron/Service/ManagedChildAdmission.php';
        self::assertFileExists($servicePath);
        $serviceSrc = (string)\file_get_contents($servicePath);
        self::assertStringContainsString('probeManagedCronProcess', $serviceSrc);
        self::assertStringContainsString('CronStatus::BLOCK', $serviceSrc);
        self::assertStringContainsString('CronStatus::RUNNING', $serviceSrc);
    }
}
