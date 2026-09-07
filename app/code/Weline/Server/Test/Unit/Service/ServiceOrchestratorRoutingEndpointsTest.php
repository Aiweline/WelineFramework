<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\ServiceRegistry;
use Weline\Server\Shared\Service\SharedMemoryService;

final class ServiceOrchestratorRoutingEndpointsTest extends TestCase
{
    protected function tearDown(): void
    {
        RoutingPolicyRegistry::clear();
    }

    public function testSharedRuntimeEndpointsReachWorkerMemoryConstructorWithoutLocalInstances(): void
    {
        $session = ['host' => '192.0.2.10', 'port' => 36277];
        $memory = ['host' => '192.0.2.20', 'port' => 36278];
        $context = $this->context(['wls' => ['edge' => ['adapter' => 'wls'], 'shared_state' => ['runtime' => ['session' => $session, 'memory' => $memory]]]]);
        $snapshot = $this->snapshot(new ServiceRegistry(), $context);
        self::assertSame($session, $snapshot['endpoints']['session']);
        self::assertSame($memory, $snapshot['endpoints']['memory']);

        RoutingPolicyRegistry::update($snapshot);
        $service = new SharedMemoryService();
        $client = (new \ReflectionProperty($service, 'client'))->getValue($service);
        $pool = (new \ReflectionProperty($client, 'pool'))->getValue($client);
        self::assertSame($memory['host'], (new \ReflectionProperty($pool, 'host'))->getValue($pool));
        self::assertSame($memory['port'], (new \ReflectionProperty($pool, 'port'))->getValue($pool));
        self::assertSame(['idle' => 0, 'busy' => 0, 'total' => 0], $pool->getPoolMetrics());
    }

    public function testFinalLaunchRuntimeOverridesStaleFileEndpoints(): void
    {
        $staleEnv = ['wls' => [
            'edge' => ['adapter' => 'wls'],
            'shared_state' => ['runtime' => [
                'session' => ['host' => '192.0.2.1', 'port' => 27277],
                'memory' => ['host' => '192.0.2.2', 'port' => 27278],
            ]],
        ]];
        $launch = \Weline\Server\Service\SharedStateRuntimeOptions::fromCliArgs([
            '--session-host=192.0.2.10', '--session-port=36277',
            '--memory-host=192.0.2.20', '--memory-port=36278',
        ], 'routing-endpoint-test', $staleEnv);
        $class = new \ReflectionClass(MasterProcess::class);
        $master = $class->newInstanceWithoutConstructor();
        $class->getProperty('config')->setValue($master, [
            'session_server_port' => $launch->getSession()['port'],
            'memory_server_port' => $launch->getMemory()['port'],
            'shared_state' => [
                'session' => $launch->getSession(),
                'memory' => $launch->getMemory(),
            ],
        ]);
        $env = $class->getMethod('applySharedStateRuntimeConfig')->invoke($master, $staleEnv);
        self::assertSame($launch->getSession(), $env['wls']['shared_state']['runtime']['session']);
        self::assertSame($launch->getMemory(), $env['wls']['shared_state']['runtime']['memory']);
        $snapshot = $this->snapshot(new ServiceRegistry(), $this->context($env));
        self::assertSame(['host' => '192.0.2.10', 'port' => 36277], $snapshot['endpoints']['session']);
        self::assertSame(['host' => '192.0.2.20', 'port' => 36278], $snapshot['endpoints']['memory']);
    }

    public function testMissingRuntimeUsesProjectScopedDefaultEndpoints(): void
    {
        $offset = MasterProcess::getProjectPortOffset();
        $snapshot = $this->snapshot(new ServiceRegistry(), null);
        self::assertSame(['host' => '127.0.0.1', 'port' => 19970 + $offset], $snapshot['endpoints']['session']);
        self::assertSame(['host' => '127.0.0.1', 'port' => 19971 + $offset], $snapshot['endpoints']['memory']);
    }

    public function testLocalReadyInstanceStillWinsBeforeRuntimeFallback(): void
    {
        $registry = new ServiceRegistry();
        $registry->addInstance(new ServiceInstance(role: ControlMessage::ROLE_MEMORY_SERVER, instanceId: 1, port: 35271, state: ServiceInstance::STATE_STARTING));
        $registry->addInstance(new ServiceInstance(role: ControlMessage::ROLE_MEMORY_SERVER, instanceId: 2, port: 35272, state: ServiceInstance::STATE_READY));
        $context = $this->context(['wls' => ['edge' => ['adapter' => 'wls'], 'shared_state' => ['runtime' => ['memory' => ['host' => '192.0.2.20', 'port' => 36278]]]]]);
        $snapshot = $this->snapshot($registry, $context);
        self::assertSame(['host' => '127.0.0.1', 'port' => 35272], $snapshot['endpoints']['memory']);
        $registry->removeInstance(ControlMessage::ROLE_MEMORY_SERVER, 2);
        self::assertSame(['host' => '127.0.0.1', 'port' => 35271], $this->snapshot($registry, $context)['endpoints']['memory']);
    }

    private function snapshot(ServiceRegistry $registry, ?ServiceContext $context): array
    {
        $class = new \ReflectionClass(ServiceOrchestrator::class);
        $orchestrator = $class->newInstanceWithoutConstructor();
        $class->getProperty('registry')->setValue($orchestrator, $registry);
        $class->getProperty('context')->setValue($orchestrator, $context);
        return $class->getMethod('buildRoutingPolicySnapshot')->invoke($orchestrator);
    }

    private function context(array $envConfig): ServiceContext
    {
        return new ServiceContext(
            instanceName: 'routing-endpoint-test', epoch: 1, controlPort: 19000, masterPid: 12345,
            host: '127.0.0.1', mainPort: 9981, sslEnabled: false, sslCert: '', sslKey: '',
            runtimeSelection: RuntimeSelection::fromArray([
                'requested_topology' => 'auto', 'effective_topology' => 'dispatcher',
                'topology_source' => 'unit-test', 'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select', 'ssl_engine' => 'stream', 'listener_mode' => 'single',
                'policy_compatible' => true, 'reason_codes' => ['unit_test'], 'reason' => 'unit test runtime selection',
            ]), daemon: true, debug: false, windowMode: false, envConfig: $envConfig,
        );
    }
}
