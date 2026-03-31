<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;

final class StartWorkerPortAllocationTest extends TestCase
{
    /** @var list<string> */
    private array $pathsToDelete = [];

    public function testFindAvailableWorkerPortBaseSkipsOccupiedPortsEvenWhenTheyBelongToWeline(): void
    {
        $start = new class extends Start {
            /** @var array<int, bool> */
            public array $allocatedPorts = [];

            protected function isWorkerPortAllocated(int $port): bool
            {
                return $this->allocatedPorts[$port] ?? false;
            }

            protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
            {
                unset($ignoreInstanceName);
                return [];
            }
        };

        $start->allocatedPorts = [
            19983 => true,
        ];

        self::assertSame(
            19984,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19983, 2)
        );
    }

    public function testFindAvailableWorkerPortBaseKeepsPreferredRangeWhenAllPortsAreFree(): void
    {
        $start = new class extends Start {
            protected function isWorkerPortAllocated(int $port): bool
            {
                return false;
            }

            protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
            {
                unset($ignoreInstanceName);
                return [];
            }
        };

        self::assertSame(
            19982,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19982, 2)
        );
    }

    public function testFindAvailableWorkerPortBaseSkipsReservedPortsFromOtherInstanceRuntimeFiles(): void
    {
        $runtimeDir = $this->createTempDir();
        \file_put_contents(
            $runtimeDir . DIRECTORY_SEPARATOR . 'api.json',
            (string) \json_encode([
                'name' => 'api',
                'worker_port' => 19983,
                'count' => 2,
                'master_mode' => MasterProcess::MODE_LEGACY,
                'started_timestamp' => \time(),
            ], JSON_PRETTY_PRINT)
        );

        $start = new class($runtimeDir) extends Start {
            public function __construct(private readonly string $runtimeDir)
            {
            }

            protected function isWorkerPortAllocated(int $port): bool
            {
                unset($port);
                return false;
            }

            protected function getInstanceRuntimeDir(): string
            {
                return $this->runtimeDir . DIRECTORY_SEPARATOR;
            }

            protected function isWorkerPortReservationActive(array $instanceData, string $instanceFile = ''): bool
            {
                unset($instanceData, $instanceFile);
                return true;
            }
        };

        self::assertSame(
            19985,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19982, 2, 10, 'default')
        );
    }

    public function testFindAvailableWorkerPortBaseSkipsExplicitlyReservedPortsSuchAsControlPort(): void
    {
        $start = new class extends Start {
            protected function isWorkerPortAllocated(int $port): bool
            {
                unset($port);
                return false;
            }

            protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
            {
                unset($ignoreInstanceName);
                return [];
            }
        };

        self::assertSame(
            19983,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19982, 2, 10, 'default', [19982])
        );
    }

    public function testWorkerPortAllocationLockPreventsConcurrentAcquisitionUntilReleased(): void
    {
        $lockFile = $this->createTempFile('worker-port-lock');

        $createProbe = static fn() => new class($lockFile) extends Start {
            public function __construct(private readonly string $lockFile)
            {
            }

            public function acquire(): bool
            {
                return $this->acquireWorkerPortAllocationLock(1);
            }

            public function release(): void
            {
                $this->releaseWorkerPortAllocationLock();
            }

            protected function getWorkerPortAllocationLockFilePath(): string
            {
                return $this->lockFile;
            }
        };

        $first = $createProbe();
        $second = $createProbe();
        $third = $createProbe();

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());

        $first->release();

        self::assertTrue($third->acquire());
        $third->release();
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->pathsToDelete) as $path) {
            if (\is_file($path)) {
                @\unlink($path);
                continue;
            }

            if (\is_dir($path)) {
                @\rmdir($path);
            }
        }

        $this->pathsToDelete = [];
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    private function createTempDir(): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-start-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0777, true);
        $this->pathsToDelete[] = $dir;

        return $dir;
    }

    private function createTempFile(string $prefix): string
    {
        $file = \tempnam(\sys_get_temp_dir(), $prefix);
        if (!\is_string($file)) {
            self::fail('Failed to create temp file.');
        }

        $this->pathsToDelete[] = $file;

        return $file;
    }
}
