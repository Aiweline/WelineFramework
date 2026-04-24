<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerAppendOnlyRecordsTest extends TestCase
{
    private ServerInstanceManager $manager;
    private string $instanceName;
    private string $instanceFile;

    protected function setUp(): void
    {
        $this->manager = new ServerInstanceManager();
        $this->instanceName = 'ut-append-records-' . \bin2hex(\random_bytes(4));
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

    public function testUpdateServicesAppendsNewLaunchRecordAndCurrentViewUsesLatest(): void
    {
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'master_pid' => 1001,
            'control_port' => 19000,
            'host' => '127.0.0.1',
            'port' => 9443,
            'count' => 1,
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [[
                        'role' => 'worker',
                        'display_name' => 'HTTP Worker',
                        'instance_id' => 1,
                        'pid' => 2001,
                        'port' => 19001,
                        'state' => ServiceInstance::STATE_READY,
                        'launch_id' => 'old-launch',
                    ]],
                ],
            ],
        ]);

        $this->manager->updateServices($this->instanceName, [
            new ServiceInfo(
                role: 'worker',
                displayName: 'HTTP Worker',
                instanceId: 1,
                pid: 2002,
                port: 19002,
                state: ServiceInstance::STATE_READY,
                priority: 20,
                launchId: 'new-launch',
            ),
        ]);

        $data = $this->readInstanceFile();
        $records = $data['services']['worker']['instances'] ?? [];
        self::assertCount(2, $records);
        self::assertSame('old-launch', $records[0]['launch_id'] ?? null);
        self::assertSame(2001, $records[0]['pid'] ?? null);
        self::assertSame('new-launch', $records[1]['launch_id'] ?? null);
        self::assertSame(2002, $records[1]['pid'] ?? null);

        $info = $this->manager->getInstanceInfo($this->instanceName, false);
        self::assertNotNull($info);
        self::assertCount(1, $info->getWorkers());
        self::assertSame(19002, $info->getWorkers()[0]->port);
    }

    public function testSaveInstancePreservesServiceTableAndAppendsRuntimeRecord(): void
    {
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'master_pid' => 1001,
            'control_port' => 19000,
            'services' => [
                'dispatcher' => [
                    'display_name' => 'Dispatcher',
                    'instances' => [[
                        'role' => 'dispatcher',
                        'display_name' => 'Dispatcher',
                        'instance_id' => 1,
                        'pid' => 3001,
                        'port' => 9443,
                        'state' => ServiceInstance::STATE_READY,
                        'launch_id' => 'dispatcher-old',
                    ]],
                ],
            ],
            'instance_records' => [[
                'master_pid' => 1001,
                'control_port' => 19000,
                'started_timestamp' => 1,
            ]],
        ]);

        $this->manager->saveInstance($this->instanceName, [
            'master_pid' => 1002,
            'control_port' => 19010,
            'host' => '127.0.0.1',
            'port' => 9443,
            'count' => 1,
            'started_timestamp' => 2,
            'startup_phase' => 'bootstrapping',
        ]);

        $data = $this->readInstanceFile();
        self::assertArrayHasKey('dispatcher', $data['services'] ?? []);
        self::assertSame('dispatcher-old', $data['services']['dispatcher']['instances'][0]['launch_id'] ?? null);
        self::assertSame(3001, $data['services']['dispatcher']['instances'][0]['pid'] ?? null);
        self::assertCount(2, $data['instance_records'] ?? []);
        self::assertSame(1001, $data['instance_records'][0]['master_pid'] ?? null);
        self::assertSame(1002, $data['instance_records'][1]['master_pid'] ?? null);
        self::assertSame(19010, $data['instance_records'][1]['control_port'] ?? null);
        self::assertSame(1002, $data['master_pid'] ?? null);
        self::assertSame('bootstrapping', $data['lifecycle_state'] ?? null);
        self::assertSame(1002, $data['current_snapshot']['master_pid'] ?? null);
        self::assertSame('bootstrapping', $data['current_snapshot']['lifecycle_state'] ?? null);
        self::assertArrayHasKey('dispatcher', $data['current_snapshot']['services'] ?? []);
    }

    public function testDeleteInstanceMarksStoppedInsteadOfRemovingFile(): void
    {
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'master_pid' => 1001,
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [[
                        'role' => 'worker',
                        'display_name' => 'HTTP Worker',
                        'instance_id' => 1,
                        'pid' => 2001,
                        'port' => 19001,
                        'state' => ServiceInstance::STATE_READY,
                    ]],
                ],
            ],
        ]);

        self::assertTrue($this->manager->deleteInstance($this->instanceName));
        self::assertFileExists($this->instanceFile);

        $data = $this->readInstanceFile();
        self::assertSame('stopped', $data['lifecycle_state'] ?? null);
        self::assertSame('deleted', $data['stopped_reason'] ?? null);
        self::assertSame('stopped', $data['services']['worker']['instances'][0]['state'] ?? null);
        self::assertSame('stopped', $data['current_snapshot']['lifecycle_state'] ?? null);
        self::assertSame('deleted', $data['current_snapshot']['stopped_reason'] ?? null);
    }

    public function testCurrentSnapshotWinsOverHistoricalRuntimeRecords(): void
    {
        $this->writeInstanceFile([
            'name' => $this->instanceName,
            'master_pid' => 99999991,
            'control_port' => 1,
            'host' => '127.0.0.1',
            'port' => 9443,
            'count' => 1,
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [[
                        'role' => 'worker',
                        'display_name' => 'HTTP Worker',
                        'instance_id' => 1,
                        'pid' => 99999992,
                        'port' => 19001,
                        'state' => ServiceInstance::STATE_READY,
                        'launch_id' => 'old-launch',
                    ]],
                ],
            ],
            'instance_records' => [[
                'master_pid' => 99999991,
                'control_port' => 1,
                'port' => 9443,
                'count' => 1,
                'started_timestamp' => 1,
            ]],
            'current_snapshot' => [
                'master_pid' => 99999993,
                'control_port' => 29000,
                'host' => '127.0.0.1',
                'port' => 9444,
                'count' => 1,
                'services' => [
                    'worker' => [
                        'display_name' => 'HTTP Worker',
                        'instances' => [[
                            'role' => 'worker',
                            'display_name' => 'HTTP Worker',
                            'instance_id' => 1,
                            'pid' => 99999994,
                            'port' => 29001,
                            'state' => ServiceInstance::STATE_READY,
                            'launch_id' => 'current-launch',
                        ]],
                    ],
                ],
            ],
        ]);

        $info = $this->manager->getInstanceInfo($this->instanceName, false);

        self::assertNotNull($info);
        self::assertSame(99999993, $info->masterPid);
        self::assertSame(29000, $info->controlPort);
        self::assertSame(9444, $info->port);
        self::assertSame(29001, $info->getWorkers()[0]->port);
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
        $data = \json_decode((string)\file_get_contents($this->instanceFile), true);

        self::assertIsArray($data);

        return $data;
    }
}
