<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\HostProcessPoolPolicy;

final class HostProcessPoolPolicyTest extends TestCase
{
    public function testSuggestTwoCoreTightMemoryIsOne(): void
    {
        $policy = HostProcessPoolPolicy::forTest(2, 700);
        self::assertSame(1, $policy->suggest(10, 32));
        self::assertSame(2, $policy->detectLogicalCpus());
        self::assertSame(700, $policy->detectAvailableMemoryMb());
    }

    public function testSuggestTwoCoreEvenWithRoomStillCpuBoundToOne(): void
    {
        // reserveCpus = cpus-1 → cpuBudget=1; mem alone would allow more.
        $policy = HostProcessPoolPolicy::forTest(2, 4000);
        self::assertSame(1, $policy->suggest(10, 32));
    }

    public function testSuggestEightCoreCpuBudgetIsSix(): void
    {
        // reserve=max(1,floor(8*0.25))=2 → cpuBudget=6; mem ample still CPU-bound.
        $policy = HostProcessPoolPolicy::forTest(8, 8192);
        self::assertSame(6, $policy->suggest(10, 32));
    }

    public function testSuggestFourteenCoreHitsAutoCeiling(): void
    {
        // reserve=floor(14*0.25)=3 → cpuBudget=11 → clamp to ceiling 10.
        $policy = HostProcessPoolPolicy::forTest(14, 16384);
        self::assertSame(10, $policy->suggest(10, 32));
    }

    public function testSuggestNullMemorySkipsMemCap(): void
    {
        $policy = HostProcessPoolPolicy::forTest(8, null);
        self::assertNull($policy->detectAvailableMemoryMb());
        self::assertSame(6, $policy->suggest(10, 32)); // cpuBudget = 8 - max(1,floor(8*0.25))=6
    }

    public function testResolveOverrideWins(): void
    {
        $policy = HostProcessPoolPolicy::forTest(2, 700);
        $d = $policy->resolve(4, 'auto', 10, 32);
        self::assertSame(4, $d['concurrency']);
        self::assertSame('override', $d['source']);
    }

    public function testResolveNumericEnvWins(): void
    {
        $policy = HostProcessPoolPolicy::forTest(2, 700);
        $d = $policy->resolve(null, '4', 10, 32);
        self::assertSame(4, $d['concurrency']);
        self::assertSame('env', $d['source']);
    }

    public function testResolveEmptyOrAutoUsesHost(): void
    {
        $policy = HostProcessPoolPolicy::forTest(2, 700);
        foreach (['', 'auto', 'AUTO'] as $env) {
            $d = $policy->resolve(null, $env, 10, 32);
            self::assertSame(1, $d['concurrency'], 'env=' . $env);
            self::assertSame('host_auto', $d['source'], 'env=' . $env);
        }
    }

    public function testResolveClampsEnvAndOverrideToMax(): void
    {
        $policy = HostProcessPoolPolicy::forTest(16, 32000);
        self::assertSame(32, $policy->resolve(100, null, 10, 32)['concurrency']);
        self::assertSame(32, $policy->resolve(null, '99', 10, 32)['concurrency']);
    }

    public function testParseLinuxMeminfoAvailableMb(): void
    {
        $sample = "MemTotal:        8048344 kB\nMemAvailable:    2048000 kB\n";
        self::assertSame(2000, HostProcessPoolPolicy::parseLinuxMeminfoAvailableMb($sample));
        self::assertNull(HostProcessPoolPolicy::parseLinuxMeminfoAvailableMb('MemTotal: 1 kB'));
    }

    public function testParseDarwinVmStatAvailableMb(): void
    {
        $sample = <<<'TXT'
Mach Virtual Memory Statistics: (page size of 16384 bytes)
Pages free:                               1000.
Pages active:                             2000.
Pages inactive:                           3000.
Pages speculative:                         400.
Pages purgeable:                           100.
TXT;
        // (1000+3000+400+100)*16384 / 1048576 = 70.3125 → 70
        self::assertSame(70, HostProcessPoolPolicy::parseDarwinVmStatAvailableMb($sample));
        self::assertNull(HostProcessPoolPolicy::parseDarwinVmStatAvailableMb('not vm_stat'));
    }

    public function testParseWindowsFreePhysicalMemoryMb(): void
    {
        self::assertSame(2000, HostProcessPoolPolicy::parseWindowsFreePhysicalMemoryMb("FreePhysicalMemory=2048000\r\n"));
        self::assertSame(2000, HostProcessPoolPolicy::parseWindowsFreePhysicalMemoryMb("2048000"));
        self::assertNull(HostProcessPoolPolicy::parseWindowsFreePhysicalMemoryMb(''));
    }

    public function testSourceContainsCrossPlatformProbes(): void
    {
        // __DIR__ = .../Framework/Test/Unit/Deploy → 上三级到 Framework
        $srcPath = dirname(__DIR__, 3) . '/Deploy/HostProcessPoolPolicy.php';
        self::assertFileExists($srcPath);
        $src = (string)file_get_contents($srcPath);
        self::assertStringContainsString('NUMBER_OF_PROCESSORS', $src);
        self::assertStringContainsString('getconf _NPROCESSORS_ONLN', $src);
        self::assertStringContainsString('hw.logicalcpu', $src);
        self::assertStringContainsString('vm_stat', $src);
        self::assertStringContainsString('FreePhysicalMemory', $src);
        self::assertStringContainsString('Win32_OperatingSystem', $src);
        self::assertStringContainsString("PHP_OS_FAMILY === 'Darwin'", $src);
        self::assertStringContainsString("PHP_OS_FAMILY === 'Windows'", $src);
    }

    public function testLiveHostDetectionUsesRealMachineResources(): void
    {
        $policy = new HostProcessPoolPolicy();
        $cpus = $policy->detectLogicalCpus();
        self::assertGreaterThanOrEqual(1, $cpus);

        // Regression: macOS/Windows must not collapse to the Linux-only fallback of 1
        // when the machine clearly has more cores (getconf / NUMBER_OF_PROCESSORS / sysctl).
        if (\PHP_OS_FAMILY === 'Darwin' && \function_exists('shell_exec')) {
            $expected = (int)trim((string)@shell_exec('sysctl -n hw.logicalcpu 2>/dev/null'));
            if ($expected > 1) {
                self::assertSame($expected, $cpus, 'Darwin logical CPU probe must match sysctl');
            }
            $mem = $policy->detectAvailableMemoryMb();
            self::assertNotNull($mem, 'Darwin vm_stat available memory must be detectable');
            self::assertGreaterThan(0, (int)$mem);
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $envCpus = (int)HostProcessPoolPolicy::envRaw('NUMBER_OF_PROCESSORS');
            if ($envCpus > 1) {
                self::assertSame($envCpus, $cpus, 'Windows CPU probe must honor NUMBER_OF_PROCESSORS');
            }
        }

        if (\PHP_OS_FAMILY === 'Linux' && is_readable('/proc/cpuinfo')) {
            $cpuinfo = (string)file_get_contents('/proc/cpuinfo');
            $procCount = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if ($procCount > 1) {
                self::assertSame($procCount, $cpus, 'Linux CPU probe must match /proc/cpuinfo');
            }
        }

        $decision = $policy->resolve(null, 'auto', 10, 32);
        self::assertSame('host_auto', $decision['source']);
        self::assertSame($cpus, $decision['cpus']);
        $mem = $decision['mem_avail_mb'];
        $memAllowsParallel = $mem === null || $mem >= (HostProcessPoolPolicy::MB_PER_WORKER * 2);
        if ($cpus > 2 && $memAllowsParallel) {
            self::assertGreaterThan(
                1,
                $decision['concurrency'],
                'multi-core host_auto must not force serial concurrency=1 when memory allows'
            );
        }
    }
}
