<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\FullPageCacheReclaimableAdapter;
use Weline\Framework\Runtime\MemoryReclaimableRegistry;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Memory\HostMemoryPressureCoordinator;

final class MemoryPressureIpcAndReclaimTest extends TestCase
{
    /** @var list<string> */
    private array $sandboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $sandbox) {
            $this->removeTree($sandbox);
        }
        $this->sandboxes = [];
    }

    public function testMemoryPressureMessageEncodesLevelAndStagger(): void
    {
        $encoded = ControlMessage::memoryPressure('yellow', 50);
        $decoded = ControlMessage::decode($encoded);
        self::assertSame(ControlMessage::TYPE_MEMORY_PRESSURE, $decoded['type'] ?? null);
        self::assertSame('yellow', $decoded['level'] ?? null);
        self::assertSame(50, (int)($decoded['stagger_ms'] ?? 0));
    }

    public function testReclaimReportIncludesBytes(): void
    {
        $encoded = ControlMessage::memoryReclaimReport(4096, 'red', ['worker_id' => 2]);
        $decoded = ControlMessage::decode($encoded);
        self::assertSame(ControlMessage::TYPE_MEMORY_RECLAIM_REPORT, $decoded['type'] ?? null);
        self::assertSame(4096, (int)($decoded['reclaim_bytes'] ?? 0));
        self::assertSame('red', $decoded['host_level_applied'] ?? null);
        self::assertSame(2, (int)($decoded['worker_id'] ?? 0));
    }

    public function testFpcAdapterIsLastResortPriority(): void
    {
        $adapter = new FullPageCacheReclaimableAdapter();
        self::assertSame(100, $adapter->reclaimPriority());
        $registry = new MemoryReclaimableRegistry();
        $registry->register($adapter);
        self::assertCount(1, $registry->all());
    }

    public function testHostCapacityMutationClaimSerialisesProjectsAndFencesStaleRelease(): void
    {
        $directory = $this->sandbox();
        $bootIdentity = \str_repeat('1', 64);
        $first = new HostMemoryPressureCoordinator($directory, $bootIdentity);
        $second = new HostMemoryPressureCoordinator($directory, $bootIdentity);
        $ownerA = \str_repeat('a', 64);
        $ownerB = \str_repeat('b', 64);

        $firstToken = $first->claim($ownerA, 'scale_down', 100.0, 20.0);
        self::assertNotNull($firstToken);
        self::assertNull($second->claim($ownerB, 'scale_down', 100.1, 20.0));

        $secondToken = $second->claim($ownerB, 'scale_down', 120.1, 20.0);
        self::assertNotNull($secondToken);
        self::assertFalse($first->release($ownerA, (string)$firstToken));
        self::assertNull($first->claim($ownerA, 'scale_up', 120.2, 20.0));

        self::assertTrue($second->release($ownerB, (string)$secondToken));
        self::assertNotNull($first->claim($ownerA, 'scale_up', 120.3, 20.0));
    }

    public function testHostCapacityMutationClaimRecoversFromClockRollback(): void
    {
        $directory = $this->sandbox();
        $coordinator = new HostMemoryPressureCoordinator(
            $directory,
            \str_repeat('2', 64),
        );

        self::assertNotNull($coordinator->claim(
            \str_repeat('c', 64),
            'scale_down',
            1000.0,
            30.0,
        ));
        self::assertNotNull($coordinator->claim(
            \str_repeat('d', 64),
            'scale_down',
            900.0,
            30.0,
        ));
    }

    public function testHostCapacityMutationClaimSupersedesPreviousBootEvenWhenClockIncreases(): void
    {
        $directory = $this->sandbox();
        $previousBoot = new HostMemoryPressureCoordinator(
            $directory,
            \str_repeat('3', 64),
        );
        $currentBoot = new HostMemoryPressureCoordinator(
            $directory,
            \str_repeat('4', 64),
        );

        self::assertNotNull($previousBoot->claim(
            \str_repeat('c', 64),
            'scale_down',
            1.0,
            300.0,
        ));
        self::assertNotNull($currentBoot->claim(
            \str_repeat('d', 64),
            'scale_down',
            2.0,
            300.0,
        ));
    }

    public function testEmergencyScaleDownPreemptsRecoveryClaimAndFencesOldRelease(): void
    {
        $directory = $this->sandbox();
        $bootIdentity = \str_repeat('5', 64);
        $recoveringProject = new HostMemoryPressureCoordinator(
            $directory,
            $bootIdentity,
        );
        $criticalProject = new HostMemoryPressureCoordinator(
            $directory,
            $bootIdentity,
        );
        $recoveryToken = $recoveringProject->claim(
            \str_repeat('e', 64),
            'scale_up',
            100.0,
            20.0,
        );
        self::assertNotNull($recoveryToken);

        $shrinkToken = $criticalProject->claim(
            \str_repeat('f', 64),
            'scale_down',
            100.1,
            20.0,
        );
        self::assertNotNull($shrinkToken);
        self::assertFalse($recoveringProject->release(
            \str_repeat('e', 64),
            (string)$recoveryToken,
        ));
        self::assertNull($recoveringProject->claim(
            \str_repeat('e', 64),
            'scale_up',
            100.2,
            20.0,
        ));
    }

    public function testImplausibleStoredClaimDurationCannotBlockEmergencyShrink(): void
    {
        $directory = $this->sandbox();
        self::assertTrue(\mkdir($directory, 0700, true));
        $bootIdentity = \str_repeat('7', 64);
        self::assertNotFalse(\file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'capacity-mutation.json',
            (string)\json_encode([
                'schema_version' => 1,
                'claim' => [
                    'owner' => \str_repeat('a', 64),
                    'action' => 'scale_down',
                    'token' => \str_repeat('b', 32),
                    'pid' => 1234,
                    'boot_id' => $bootIdentity,
                    'claimed_at' => 100.0,
                    'hold_until' => 1000000.0,
                ],
                'updated_at' => '2026-07-31T00:00:00Z',
            ], JSON_THROW_ON_ERROR),
        ));

        $coordinator = new HostMemoryPressureCoordinator(
            $directory,
            $bootIdentity,
        );
        self::assertNotNull($coordinator->claim(
            \str_repeat('c', 64),
            'scale_down',
            101.0,
            20.0,
        ));
    }

    public function testCoordinationFailureAllowsEmergencyShrinkButDefersRecovery(): void
    {
        $directory = $this->sandbox();
        self::assertTrue(\mkdir($directory, 0700, true));
        $blockedParent = $directory . DIRECTORY_SEPARATOR . 'regular-file';
        self::assertNotFalse(\file_put_contents($blockedParent, 'blocked'));

        $controller = new \Weline\Server\Service\Memory\MemoryPressureController();
        $controller->configureHostCapacityCoordination(
            new HostMemoryPressureCoordinator(
                $blockedParent . DIRECTORY_SEPARATOR . 'state',
                \str_repeat('6', 64),
            ),
            \str_repeat('e', 64),
        );

        self::assertSame(
            'local-uncoordinated',
            $controller->claimHostCapacityMutation(
                'scale_down',
                100.0,
                20.0,
            ),
        );
        self::assertNull($controller->claimHostCapacityMutation(
            'scale_up',
            100.1,
            20.0,
        ));
    }

    public function testRequiredButUnconfiguredCoordinationDefersRecoveryOnly(): void
    {
        $controller = new \Weline\Server\Service\Memory\MemoryPressureController();
        $controller->requireHostCapacityCoordination();

        self::assertSame(
            'local-uncoordinated',
            $controller->claimHostCapacityMutation(
                'scale_down',
                100.0,
                20.0,
            ),
        );
        self::assertNull($controller->claimHostCapacityMutation(
            'scale_up',
            100.1,
            20.0,
        ));
    }

    public function testContendedCoordinationLockFailsBoundedlyWithDirectionalFallback(): void
    {
        $directory = $this->sandbox();
        self::assertTrue(\mkdir($directory, 0700, true));
        $lock = \fopen(
            $directory . DIRECTORY_SEPARATOR . 'capacity-mutation.lock',
            'c+b',
        );
        self::assertIsResource($lock);
        self::assertTrue(\flock($lock, LOCK_EX | LOCK_NB));

        $controller = new \Weline\Server\Service\Memory\MemoryPressureController();
        $controller->configureHostCapacityCoordination(
            new HostMemoryPressureCoordinator(
                $directory,
                \str_repeat('8', 64),
            ),
            \str_repeat('f', 64),
        );

        $startedAt = \hrtime(true);
        try {
            self::assertSame(
                'local-uncoordinated',
                $controller->claimHostCapacityMutation(
                    'scale_down',
                    100.0,
                    20.0,
                ),
            );
            self::assertNull($controller->claimHostCapacityMutation(
                'scale_up',
                100.1,
                20.0,
            ));
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
        $elapsedSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        self::assertLessThan(1.5, $elapsedSeconds);
    }

    public function testBootIdentityProbeTimeoutTerminatesChildBoundedly(): void
    {
        $coordinator = new HostMemoryPressureCoordinator(
            $this->sandbox(),
            \str_repeat('9', 64),
        );
        $probe = new \ReflectionMethod(
            HostMemoryPressureCoordinator::class,
            'boundedCommandOutput',
        );
        $startedAt = \hrtime(true);
        try {
            $probe->invoke(
                $coordinator,
                [
                    PHP_BINARY,
                    '-r',
                    'if (function_exists("pcntl_async_signals")) {'
                        . ' pcntl_async_signals(true);'
                        . ' pcntl_signal(SIGTERM, SIG_IGN);'
                        . ' } usleep(5000000);',
                ],
                0.1,
            );
            self::fail('Expected the boot identity probe to time out.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'probe timed out',
                $exception->getMessage(),
            );
        }
        $elapsedSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        self::assertLessThan(2.0, $elapsedSeconds);
    }

    private function sandbox(): string
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-host-memory-pressure-' . \bin2hex(\random_bytes(8));
        $this->sandboxes[] = $directory;
        return $directory;
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            return;
        }
        foreach ((array)@\scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($child) && !\is_link($child)) {
                $this->removeTree($child);
            } else {
                @\unlink($child);
            }
        }
        @\rmdir($path);
    }
}
