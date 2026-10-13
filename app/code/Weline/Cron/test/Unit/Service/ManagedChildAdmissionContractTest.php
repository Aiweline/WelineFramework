<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Service\HostCapacityProbe;
use Weline\Cron\Service\ManagedChildAdmission;
use Weline\SystemConfig\Model\SystemConfig;

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
        self::assertSame(1, ManagedChildAdmission::DEFAULT_HOST_CPU_RESERVE);
        self::assertSame('cron/max_concurrent_managed_children', ManagedChildAdmission::CONFIG_KEY);
        self::assertSame('cron/host_capacity_admission', ManagedChildAdmission::CONFIG_HOST_CAPACITY_ADMISSION);
        self::assertSame('cron/host_cpu_reserve', ManagedChildAdmission::CONFIG_HOST_CPU_RESERVE);
    }

    public function testNormalizeMaxClampsInvalidConfig(): void
    {
        self::assertSame(ManagedChildAdmission::DEFAULT_MAX, ManagedChildAdmission::normalizeMax(0));
        self::assertSame(ManagedChildAdmission::DEFAULT_MAX, ManagedChildAdmission::normalizeMax(-1));
        self::assertSame(1, ManagedChildAdmission::normalizeMax(1));
        self::assertSame(3, ManagedChildAdmission::normalizeMax(3));
        self::assertSame(ManagedChildAdmission::MAX_MAX, ManagedChildAdmission::normalizeMax(99));
    }

    public function testParseTruthy(): void
    {
        self::assertTrue(ManagedChildAdmission::parseTruthy(null, true));
        self::assertFalse(ManagedChildAdmission::parseTruthy(null, false));
        self::assertFalse(ManagedChildAdmission::parseTruthy('0', true));
        self::assertFalse(ManagedChildAdmission::parseTruthy('off', true));
        self::assertTrue(ManagedChildAdmission::parseTruthy('1', false));
        self::assertTrue(ManagedChildAdmission::parseTruthy('yes', false));
    }

    public function testEffectiveMaxCapsTwoCoreHostToOne(): void
    {
        $admission = new ManagedChildAdmission(
            $this->configMap([
                ManagedChildAdmission::CONFIG_KEY => 3,
                ManagedChildAdmission::CONFIG_HOST_CAPACITY_ADMISSION => '1',
                ManagedChildAdmission::CONFIG_HOST_CPU_RESERVE => 1,
            ]),
            new HostCapacityProbe(2, 0.2),
        );
        self::assertSame(3, $admission->maxConcurrent());
        self::assertSame(1, $admission->hostCapacityMax());
        self::assertSame(1, $admission->effectiveMax());
    }

    public function testEffectiveMaxRespectsEscapeHatch(): void
    {
        $admission = new ManagedChildAdmission(
            $this->configMap([
                ManagedChildAdmission::CONFIG_KEY => 3,
                ManagedChildAdmission::CONFIG_HOST_CAPACITY_ADMISSION => '0',
                ManagedChildAdmission::CONFIG_HOST_CPU_RESERVE => 1,
            ]),
            new HostCapacityProbe(2, 0.2),
        );
        self::assertSame(3, $admission->effectiveMax());
        self::assertFalse($admission->isLoadSaturated());
    }

    public function testLoadSaturatedDefersSlot(): void
    {
        $admission = new ManagedChildAdmission(
            $this->configMap([
                ManagedChildAdmission::CONFIG_KEY => 3,
                ManagedChildAdmission::CONFIG_HOST_CAPACITY_ADMISSION => '1',
                ManagedChildAdmission::CONFIG_HOST_CPU_RESERVE => 1,
            ]),
            new HostCapacityProbe(2, 2.5),
        );
        self::assertTrue($admission->isLoadSaturated());
        self::assertFalse($admission->hasAvailableSlot());
    }

    public function testRunGuardsAdmissionBeforeClaimUsesEffectiveMax(): void
    {
        $path = \BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('ManagedChildAdmission', $src);
        self::assertStringContainsString('hasAvailableSlot', $src);
        self::assertStringContainsString('effectiveMax()', $src);
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
        self::assertStringContainsString('effectiveMax', $serviceSrc);
        self::assertStringContainsString('isLoadSaturated', $serviceSrc);
        self::assertStringContainsString('HostCapacityProbe', $serviceSrc);
    }

    /**
     * @param array<string, mixed> $map
     */
    private function configMap(array $map): SystemConfig
    {
        $config = $this->createMock(SystemConfig::class);
        $config->method('getConfig')->willReturnCallback(
            static function (string $key, string $module, string $area, mixed $default = null) use ($map): mixed {
                if (\array_key_exists($key, $map)) {
                    return $map[$key];
                }

                return $default;
            },
        );

        return $config;
    }
}
