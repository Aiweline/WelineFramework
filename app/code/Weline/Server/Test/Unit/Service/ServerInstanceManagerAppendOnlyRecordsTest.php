<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Instance endpoint record contract.
 *
 * 原 append-only service topology（services / instance_records / current_snapshot）
 * 已从 instance JSON 移除：该文件只保存 Master endpoint/config。服务拓扑来自
 * Master registry 与 IPC status，updateServices() 不再写盘。
 */
final class ServerInstanceManagerAppendOnlyRecordsTest extends TestCase
{
    private ServerInstanceManager $manager;
    private string $instanceName;
    private string $instanceFile;

    protected function setUp(): void
    {
        $this->manager = new ServerInstanceManager();
        $this->instanceName = 'ut-endpoint-record-' . \bin2hex(\random_bytes(4));
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

    public function testUpdateServicesDoesNotWriteTopologyToEndpointRecord(): void
    {
        $this->writeInstanceFile($this->endpoint());
        $before = (string)\file_get_contents($this->instanceFile);

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

        self::assertSame(
            $before,
            (string)\file_get_contents($this->instanceFile),
            '服务拓扑不得写入 instance endpoint 文件',
        );
    }

    public function testSaveInstanceDropsServiceTopologyAndPreservesEndpointFields(): void
    {
        $this->writeInstanceFile($this->endpoint([
            'services' => [
                'dispatcher' => [
                    'display_name' => 'Dispatcher',
                    'instances' => [[
                        'role' => 'dispatcher',
                        'instance_id' => 1,
                        'pid' => 3001,
                        'port' => 9443,
                        'state' => ServiceInstance::STATE_READY,
                    ]],
                ],
            ],
            'instance_records' => [['master_pid' => 1001]],
            'current_snapshot' => ['master_pid' => 1001],
        ]));

        $this->manager->saveInstance($this->instanceName, [
            'master_pid' => 1002,
            'control_port' => 19010,
            'startup_phase' => 'bootstrapping',
        ]);

        $data = $this->readInstanceFile();
        self::assertSame(1002, $data['master_pid'] ?? null);
        self::assertSame(19010, $data['control_port'] ?? null);
        self::assertSame('bootstrapping', $data['lifecycle_state'] ?? null);
        self::assertArrayNotHasKey('services', $data);
        self::assertArrayNotHasKey('instance_records', $data);
        self::assertArrayNotHasKey('current_snapshot', $data);
    }

    public function testDeleteInstanceMarksStoppedAndKeepsEndpointFile(): void
    {
        $this->writeInstanceFile($this->endpoint([
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [[
                        'role' => 'worker',
                        'instance_id' => 1,
                        'pid' => 2001,
                        'port' => 19001,
                        'state' => ServiceInstance::STATE_READY,
                    ]],
                ],
            ],
        ]));

        self::assertTrue($this->manager->deleteInstance($this->instanceName));
        self::assertFileExists($this->instanceFile);

        $data = $this->readInstanceFile();
        self::assertSame('stopped', $data['lifecycle_state'] ?? null);
        self::assertSame('deleted', $data['stopped_reason'] ?? null);
        self::assertSame(0, $data['master_pid'] ?? null);
        self::assertArrayNotHasKey('services', $data);
    }

    public function testGetInstanceInfoReadsCanonicalEndpointWithoutServiceTopology(): void
    {
        $this->writeInstanceFile($this->endpoint([
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [[
                        'role' => 'worker',
                        'instance_id' => 1,
                        'pid' => 2001,
                        'port' => 19001,
                        'state' => ServiceInstance::STATE_READY,
                    ]],
                ],
            ],
        ]));

        $info = $this->manager->getInstanceInfo($this->instanceName, false);

        self::assertNotNull($info);
        self::assertSame(1001, $info->masterPid);
        self::assertSame(19000, $info->controlPort);
        self::assertSame(9443, $info->port);
        self::assertIsArray($info->getWorkers());
    }

    public function testGetInstanceInfoRejectsEndpointWithoutRuntimeSelection(): void
    {
        $data = $this->endpoint();
        unset($data['runtime_selection']);
        $this->writeInstanceFile($data);

        $this->expectException(\RuntimeException::class);
        $this->manager->getInstanceInfo($this->instanceName, false);
    }

    /** @return array<string, mixed> */
    private function endpoint(array $overrides = []): array
    {
        return \array_merge([
            'schema_version' => RuntimeSelection::ENDPOINT_SCHEMA_VERSION,
            'runtime_selection' => $this->runtimeSelection(),
            'name' => $this->instanceName,
            'instance_name' => $this->instanceName,
            'master_pid' => 1001,
            'pid' => 1001,
            'master_enabled' => true,
            'control_port' => 19000,
            'host' => '127.0.0.1',
            'port' => 9443,
            'count' => 1,
            'started_at' => '2026-10-21 00:00:00',
            'started_timestamp' => 1,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function runtimeSelection(): array
    {
        return RuntimeSelection::fromArray([
            'requested_topology' => 'direct',
            'effective_topology' => 'direct',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => PHP_OS_FAMILY === 'Windows' ? 'worker_ports' : 'shared_fd',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ])->toArray();
    }

    /** @param array<string, mixed> $data */
    private function writeInstanceFile(array $data): void
    {
        ServerInstanceManager::atomicWriteJsonStatic($this->instanceFile, $data, 5);
    }

    /** @return array<string, mixed> */
    private function readInstanceFile(): array
    {
        $data = \json_decode((string)\file_get_contents($this->instanceFile), true);

        self::assertIsArray($data);

        return $data;
    }
}
