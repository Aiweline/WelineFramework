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
}
