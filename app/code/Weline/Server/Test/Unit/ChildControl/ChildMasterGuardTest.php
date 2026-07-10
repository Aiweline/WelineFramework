<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\ChildControl;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\IPC\OrphanGuard;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\Service\MasterLeaseManager;

final class ChildMasterGuardTest extends TestCase
{
    /** @var list<string> */
    private array $leaseFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->leaseFiles as $file) {
            @\unlink($file);
            @\rmdir(\dirname($file));
        }
        $this->leaseFiles = [];
    }

    public function testOrphanGuardDoesNotTrustConnectedIpcWhenMasterPidIsMissing(): void
    {
        $guard = new OrphanGuard(0, 1, 1, null, 180);

        self::assertTrue($guard->shouldExit($this->missingPid(), true, false, 'UT-Orphan'));
    }

    public function testChildMasterGuardExitsWhenLeaseStateIsStopping(): void
    {
        $guard = $this->guardForLease([
            'state' => MasterLeaseManager::STATE_STOPPING,
        ]);

        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('state=stopping', $guard->getLastExitReason());
    }

    public function testChildMasterGuardExitsWhenLeaseTokenDoesNotMatch(): void
    {
        $guard = $this->guardForLease([
            'master_token' => 'other-token',
        ]);

        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('token mismatch', $guard->getLastExitReason());
    }

    public function testChildMasterGuardExitsWhenLeasePidOrInstanceDoesNotMatch(): void
    {
        $guard = $this->guardForLease([
            'master_pid' => (int)\getmypid() + 100000,
        ]);

        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('PID mismatch', $guard->getLastExitReason());

        $guard = $this->guardForLease([
            'instance' => 'other-instance',
        ]);

        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('instance mismatch', $guard->getLastExitReason());
    }

    public function testChildMasterGuardAllowsMatchingRunningLease(): void
    {
        $guard = $this->guardForLease();

        self::assertFalse($guard->shouldExit(true));
        self::assertSame('', $guard->getLastExitReason());
    }

    public function testChildMasterGuardTrustsFreshMatchingLeaseBeforeSlowPidProbe(): void
    {
        $missingPid = $this->missingPid();
        $path = $this->writeLease([
            'master_pid' => $missingPid,
        ]);
        $guard = new ChildMasterGuard(
            $missingPid,
            $path,
            'unit-token',
            'UT-Child',
            'unit-instance',
            7,
            0.0
        );

        self::assertFalse($guard->shouldExit(true));
        self::assertSame('', $guard->getLastExitReason());
        self::assertFalse($guard->shouldExit(true));
        self::assertSame('', $guard->getLastExitReason());
        self::assertFalse($guard->shouldExit(true));
        self::assertSame('', $guard->getLastExitReason());
    }

    public function testChildMasterGuardExitsWhenLeaseIsStaleAndPidIsMissing(): void
    {
        $missingPid = $this->missingPid();
        $path = $this->writeLease([
            'master_pid' => $missingPid,
            'updated_at' => \microtime(true) - MasterLeaseManager::HEARTBEAT_STALE_SEC - 1,
        ]);
        $guard = new ChildMasterGuard(
            $missingPid,
            $path,
            'unit-token',
            'UT-Child',
            'unit-instance',
            7,
            0.0
        );

        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('heartbeat stale', $guard->getLastExitReason());
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function guardForLease(array $overrides = []): ChildMasterGuard
    {
        $path = $this->writeLease($overrides);

        return new ChildMasterGuard(
            (int)\getmypid(),
            $path,
            'unit-token',
            'UT-Child',
            'unit-instance',
            7,
            0.0
        );
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function writeLease(array $overrides = []): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-master-lease-ut-' . \bin2hex(\random_bytes(4));
        self::assertTrue(@\mkdir($dir, 0777, true) || \is_dir($dir));
        $path = $dir . DIRECTORY_SEPARATOR . 'master_lease.json';
        $payload = \array_merge([
            'instance' => 'unit-instance',
            'master_pid' => (int)\getmypid(),
            'control_port' => 19191,
            'master_epoch' => 7,
            'master_token' => 'unit-token',
            'state' => MasterLeaseManager::STATE_RUNNING,
            'updated_at' => \microtime(true),
        ], $overrides);

        self::assertNotFalse(@\file_put_contents(
            $path,
            \json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ));
        $this->leaseFiles[] = $path;

        return $path;
    }

    private function missingPid(): int
    {
        return 999999999;
    }
}
