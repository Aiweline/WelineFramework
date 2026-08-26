<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

final class MasterLeaseRuntimeIdentityDarwinBirthTest extends TestCase
{
    protected function tearDown(): void
    {
        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        parent::tearDown();
    }

    public function testCaptureProcessIdentityUsesDarwinLibprocBirthOnCurrentPid(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Darwin libproc birth capture is macOS-only.');
        }
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            self::markTestSkipped('FFI extension is required for Darwin process birth.');
        }

        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        $identity = new MasterLeaseRuntimeIdentity();
        $first = $identity->captureProcessIdentity((int)\getmypid());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $first['birth']);
        self::assertSame('', $first['pid_namespace_id']);

        // Clearing the success cache must not permanently disable later loads.
        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        $second = $identity->captureProcessIdentity((int)\getmypid());
        self::assertSame($first['birth'], $second['birth']);
    }

    public function testCaptureProcessIdentityFailsFastWhenPidIsDefinitelyMissing(): void
    {
        $identity = new MasterLeaseRuntimeIdentity(
            processInfoResolver: static fn (int $pid): array => ['exists' => false],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WLS process is not running.');
        $identity->captureProcessIdentity(9_999_991);
    }

    public function testInspectProcessTreatsMissingPidAsAbsentWithoutSlowPsProbe(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Darwin missing-PID fast path is macOS-only.');
        }

        MasterLeaseRuntimeIdentity::clearDarwinProcFfiCacheForTests();
        $identity = new MasterLeaseRuntimeIdentity();
        $missingPid = 9_999_992;
        while (@\posix_kill($missingPid, 0)) {
            ++$missingPid;
        }

        $startedAt = \hrtime(true);
        $info = $identity->inspectProcess($missingPid);
        $elapsedMs = (\hrtime(true) - $startedAt) / 1_000_000;

        self::assertSame(['exists' => false], $info);
        self::assertLessThan(
            250.0,
            $elapsedMs,
            'Missing PID inspection must not fall back to the multi-second ps probe.',
        );
    }
}
