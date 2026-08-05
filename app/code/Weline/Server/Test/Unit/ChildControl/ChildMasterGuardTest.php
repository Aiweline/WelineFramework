<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\ChildControl;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\IPC\OrphanGuard;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

final class ChildMasterGuardTest extends TestCase
{
    /** @var list<string> */
    private array $instances = [];

    protected function tearDown(): void
    {
        foreach ($this->instances as $instance) {
            $path = MasterLeaseManager::pathForInstance($instance);
            @\unlink($path);
            @\unlink(MasterLeaseManager::lockPathForInstance($instance));
            @\rmdir(\dirname($path));
        }
        $this->instances = [];
    }

    public function testOrphanGuardDoesNotTrustConnectedIpcWhenMasterPidIsMissing(): void
    {
        $guard = new OrphanGuard(0, 1, 1, null, 180);
        self::assertTrue($guard->shouldExit(999_999_999, true, false, 'UT-Orphan'));
    }

    public function testStrictGuardUsesProtectedSchema2IdentityAndFreshness(): void
    {
        $now = 1_000.0;
        [$manager, $instance, $path, $token] = $this->lease($now);
        $guard = $this->guard($manager, $instance, $path, $token, true);

        self::assertFalse($guard->shouldExit(true));
        $now += MasterLeaseManager::HEARTBEAT_STALE_SEC + 1.0;
        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('stale', \strtolower($guard->getLastExitReason()));
    }

    public function testCompatibilityGuardMayTolerateStaleHeartbeatButNotBirthOrTokenMismatch(): void
    {
        $now = 2_000.0;
        $namespace = 'pid:[4026532111]';
        $birth = 'fixed-birth';
        [$manager, $instance, $path, $token] = $this->lease($now, $namespace, $birth);
        $guard = $this->guard($manager, $instance, $path, $token, false);

        $now += MasterLeaseManager::HEARTBEAT_STALE_SEC + 1.0;
        self::assertFalse($guard->shouldExit(true));

        $wrongToken = $this->guard($manager, $instance, $path, \str_repeat('f', 64), false);
        self::assertTrue($wrongToken->shouldExit(true));

        $birth = 'reused-pid-birth';
        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('owner', \strtolower($guard->getLastExitReason()));
    }

    public function testStoppingStateAndExpectedIdentityMismatchExit(): void
    {
        $now = 3_000.0;
        [$manager, $instance, $path, $token] = $this->lease($now);
        $pid = (int)\getmypid();

        $wrongPid = new ChildMasterGuard(
            $pid + 1,
            $path,
            $token,
            'UT-Child',
            $instance,
            7,
            0.0,
            $manager,
            true,
        );
        self::assertTrue($wrongPid->shouldExit(true));

        $manager->markStopping($instance, $pid, $token);
        $guard = $this->guard($manager, $instance, $path, $token, true);
        self::assertTrue($guard->shouldExit(true));
        self::assertStringContainsString('not running', \strtolower($guard->getLastExitReason()));
    }

    private function guard(
        MasterLeaseManager $manager,
        string $instance,
        string $path,
        string $token,
        bool $strict,
    ): ChildMasterGuard {
        return new ChildMasterGuard(
            (int)\getmypid(),
            $path,
            $token,
            'UT-Child',
            $instance,
            7,
            0.0,
            $manager,
            $strict,
        );
    }

    /** @return array{MasterLeaseManager,string,string,string} */
    private function lease(
        float &$now,
        ?string &$namespace = null,
        ?string &$birth = null,
    ): array
    {
        $namespace ??= 'pid:[4026532001]';
        $birth ??= 'fixed-birth';
        $pid = (int)\getmypid();
        $boot = \str_repeat('7', 64);
        $runtime = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: static fn (): string => $boot,
            monotonicClock: static function () use (&$now): float {
                return $now;
            },
            processInfoResolver: static function (int $candidate) use ($pid, &$birth): array {
                return [
                    'exists' => $candidate === $pid,
                    'name' => $candidate === $pid ? 'php' : '',
                    'command' => $candidate === $pid ? 'php bin/w --name=unit-master' : '',
                    'start_time' => $candidate === $pid ? $birth : '',
                ];
            },
            managedProcessVerifier: static fn (int $candidate, string $instance): bool => $candidate === $pid,
            pidNamespaceResolver: static function (int $candidate) use ($pid, &$namespace): ?string {
                return $candidate === $pid ? $namespace : null;
            },
        );
        $manager = new MasterLeaseManager($runtime);
        $instance = 'child-guard-' . \bin2hex(\random_bytes(5));
        $this->instances[] = $instance;
        $token = \str_repeat('a', 64);
        $path = $manager->writeRunning($instance, $pid, 19191, 7, $token);

        return [$manager, $instance, $path, $token];
    }
}
