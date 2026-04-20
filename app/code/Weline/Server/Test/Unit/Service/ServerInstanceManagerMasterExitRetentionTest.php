<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerMasterExitRetentionTest extends TestCase
{
    private ServerInstanceManager $manager;
    private string $instanceName;
    private string $instanceFile;

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
        if (\is_file($this->instanceFile)) {
            @\unlink($this->instanceFile);
        }
    }

    public function testRetainsInstanceRecordWhenManagedChildProcessesStillRun(): void
    {
        $currentPid = \getmypid();
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'pid' => 11111,
            'master_pid' => 11111,
            'count' => 1,
            'port' => 443,
            'worker_pids' => [$currentPid],
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [[
                        'role' => 'worker',
                        'display_name' => 'HTTP Worker',
                        'instance_id' => 1,
                        'pid' => $currentPid,
                        'port' => 10443,
                        'state' => 'ready',
                        'priority' => 20,
                        'worker_id' => 1,
                        'name' => 'worker-1',
                        'started_at' => 0.0,
                        'ipc_client_id' => null,
                        'metadata' => [
                            'process_name' => 'weline-wls-worker-' . $this->instanceName . '-1',
                        ],
                    ]],
                ],
            ],
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

    public function testDeletesInstanceRecordWhenNoManagedProcessRemains(): void
    {
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'pid' => 22222,
            'master_pid' => 22222,
            'count' => 1,
            'port' => 443,
            'worker_pids' => [],
            'services' => [],
        ]);

        $retained = $this->manager->finalizeAfterMasterExit($this->instanceName, 22222);

        self::assertFalse($retained);
        self::assertFileDoesNotExist($this->instanceFile);
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
