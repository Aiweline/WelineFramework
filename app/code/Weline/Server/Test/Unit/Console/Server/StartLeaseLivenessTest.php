<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Server;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

/**
 * 运行态判活以 master lease 为准（state=running + PID 存活 + birth 一致）。
 * 端口占用/实例文件在过渡态会误判，从而起第二代并收掉旧代（2026-10-26 实测断站 2 分钟）。
 */
final class StartLeaseLivenessTest extends TestCase
{
    private const BIRTH = '8f65e8343c019e4be04e65b3842b21aadf51fe3ef5a591c3118daf2164025ffb';

    public function testRunningLeaseWithMatchingBirthProvesServingMaster(): void
    {
        $lease = [
            'state' => 'running',
            'master_pid' => 4242,
            'master_process_birth' => self::BIRTH,
        ];
        self::assertTrue(Start::leaseReportsServingMaster($lease));
        self::assertTrue(Start::leaseIdentityProvesServingMaster($lease, ['birth' => self::BIRTH]));
    }

    public function testLeaseBirthIsCaseInsensitiveButMustMatchExactly(): void
    {
        $lease = [
            'state' => 'running',
            'master_pid' => 4242,
            'master_process_birth' => \strtoupper(self::BIRTH),
        ];
        self::assertTrue(Start::leaseIdentityProvesServingMaster($lease, ['birth' => self::BIRTH]));
        self::assertFalse(
            Start::leaseIdentityProvesServingMaster($lease, ['birth' => \str_repeat('a', 64)])
        );
    }

    public function testNonRunningLeaseIsNotServingEvidence(): void
    {
        $lease = [
            'state' => 'stopped',
            'master_pid' => 4242,
            'master_process_birth' => self::BIRTH,
        ];
        self::assertFalse(Start::leaseReportsServingMaster($lease));
        self::assertFalse(Start::leaseIdentityProvesServingMaster($lease, ['birth' => self::BIRTH]));
    }

    public function testMissingOrInvalidBirthIsNotServingEvidence(): void
    {
        $lease = [
            'state' => 'running',
            'master_pid' => 4242,
            'master_process_birth' => '',
        ];
        self::assertFalse(Start::leaseIdentityProvesServingMaster($lease, ['birth' => self::BIRTH]));
        self::assertFalse(Start::leaseIdentityProvesServingMaster($lease, []));

        $shortBirthLease = [
            'state' => 'running',
            'master_pid' => 4242,
            'master_process_birth' => 'deadbeef',
        ];
        self::assertFalse(
            Start::leaseIdentityProvesServingMaster($shortBirthLease, ['birth' => 'deadbeef'])
        );
    }

    public function testZeroOrNegativePidIsNotServingEvidence(): void
    {
        $lease = [
            'state' => 'running',
            'master_pid' => 0,
            'master_process_birth' => self::BIRTH,
        ];
        self::assertFalse(Start::leaseReportsServingMaster($lease));
        self::assertFalse(Start::leaseIdentityProvesServingMaster($lease, ['birth' => self::BIRTH]));
    }
}
