<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerMasterExitRetentionTest extends TestCase
{
    private ServerInstanceManager $manager;
    private string $instanceName;
    private string $instanceFile;
    /** @var string[] */
    private array $registeredProcessNames = [];

    protected function setUp(): void
    {
        $this->manager = new ServerInstanceManager();
        $this->instanceName = 'ut-master-exit-' . \bin2hex(\random_bytes(4));
        $this->instanceFile = $this->manager->getInstanceFile($this->instanceName);

        $dir = \dirname($this->instanceFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->registeredProcessNames as $processName) {
            Processer::removePidFile($processName);
        }
        if (\is_dir($this->instanceFile . '.lock')) {
            @\rmdir($this->instanceFile . '.lock');
        } elseif (\is_file($this->instanceFile . '.lock')) {
            @\unlink($this->instanceFile . '.lock');
        }
        if (\is_file($this->instanceFile)) {
            @\unlink($this->instanceFile);
        }
        $serverDirectory = \dirname(\dirname($this->instanceFile));
        foreach ([
            $this->manager->getPidFile($this->instanceName),
            MasterProcess::getServiceExceptionFile($this->instanceName),
            $serverDirectory . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR
                . 'start_' . $this->instanceName . '.lock',
            $serverDirectory . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR
                . 'lifecycle_' . $this->instanceName . '.lock',
        ] as $artifact) {
            if (\is_file($artifact) || \is_link($artifact)) {
                @\unlink($artifact);
            }
        }
    }

    public function testRetainsInstanceRecordWhenManagedChildProcessesStillRun(): void
    {
        $currentPid = \getmypid();
        $workerProcessName = '--name=' . MasterProcess::buildScopedProcessName(
            'weline-wls-worker',
            $this->instanceName,
            1
        );
        Processer::setPid($workerProcessName, $currentPid);
        $this->registeredProcessNames[] = $workerProcessName;

        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'pid' => 11111,
            'master_pid' => 11111,
            'launcher_pid' => $currentPid,
            'count' => 1,
            'port' => 443,
        ]);

        $retained = $this->manager->finalizeAfterMasterExit($this->instanceName, 11111);

        self::assertTrue($retained);
        self::assertFileExists($this->instanceFile);

        $data = $this->readInstanceFile();
        self::assertSame(0, $data['master_pid'] ?? null);
        self::assertSame(0, $data['pid'] ?? null);
        self::assertSame('master_exited_children_retained', $data['lifecycle_state'] ?? null);
        self::assertSame([$currentPid], $data['retained_pids'] ?? null);
        self::assertSame(1, $data['retained_pid_count'] ?? null);
    }

    public function testMarksInstanceRecordStoppedWhenNoManagedProcessRemains(): void
    {
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'pid' => 22222,
            'master_pid' => 22222,
            'count' => 1,
            'port' => 443,
        ]);

        $retained = $this->manager->finalizeAfterMasterExit($this->instanceName, 22222);

        self::assertFalse($retained);
        self::assertFileExists($this->instanceFile);

        $data = $this->readInstanceFile();
        self::assertSame(0, $data['master_pid'] ?? null);
        self::assertSame(0, $data['pid'] ?? null);
        self::assertSame('stopped', $data['lifecycle_state'] ?? null);
        self::assertSame('stale_cleanup', $data['stopped_reason'] ?? null);
    }

    public function testInitialMasterExitPublicationFailurePreservesRecordAndStopsCleanup(): void
    {
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'pid' => 33333,
            'master_pid' => 33333,
            'count' => 1,
            'port' => 443,
        ]);
        self::assertTrue(@\unlink($this->instanceFile . '.lock'));
        self::assertTrue(@\mkdir($this->instanceFile . '.lock', 0700));

        $failure = null;
        try {
            $this->manager->finalizeAfterMasterExit($this->instanceName, 33333);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('Master exit state', $failure->getMessage());

        self::assertFileExists($this->instanceFile);
        $data = $this->readInstanceFile();
        self::assertSame(33333, $data['master_pid'] ?? null);
        self::assertSame(33333, $data['pid'] ?? null);
        self::assertArrayNotHasKey('stopped_reason', $data);
    }

    public function testRetainedChildrenPublicationFailureIsObservable(): void
    {
        $currentPid = \getmypid();
        $workerProcessName = '--name=' . MasterProcess::buildScopedProcessName(
            'weline-wls-worker',
            $this->instanceName,
            1
        );
        Processer::setPid($workerProcessName, $currentPid);
        $this->registeredProcessNames[] = $workerProcessName;

        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'pid' => 44444,
            'master_pid' => 44444,
            'launcher_pid' => $currentPid,
            'count' => 1,
            'port' => 443,
        ]);

        $manager = new class extends ServerInstanceManager {
            public int $atomicUpdateCalls = 0;

            protected function atomicUpdateJson(string $file, callable $updater): bool
            {
                ++$this->atomicUpdateCalls;
                if ($this->atomicUpdateCalls === 2) {
                    return false;
                }

                return parent::atomicUpdateJson($file, $updater);
            }
        };

        $failure = null;
        try {
            $manager->finalizeAfterMasterExit($this->instanceName, 44444);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString(
            'retained WLS child process state',
            $failure->getMessage(),
        );

        self::assertSame(2, $manager->atomicUpdateCalls);
        $data = $this->readInstanceFile();
        self::assertSame('master_exited', $data['lifecycle_state'] ?? null);
        self::assertArrayNotHasKey('retained_pids', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeInstanceFile(array $data): void
    {
        ServerInstanceManager::atomicWriteJsonStatic($this->instanceFile, $data, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function readInstanceFile(): array
    {
        $data = \json_decode((string) \file_get_contents($this->instanceFile), true);

        self::assertIsArray($data);

        return $data;
    }
}
