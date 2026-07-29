<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;

final class MasterLeaseOrchestratorArgsTest extends TestCase
{
    public function testAppendInstanceIdentityArgsCarriesMasterLeaseArguments(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $context = new ServiceContext(
            instanceName: 'unit-instance',
            epoch: 7,
            controlPort: 19091,
            masterPid: (int)\getmypid(),
            host: '127.0.0.1',
            mainPort: 9502,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: RuntimeSelection::fromArray([
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
            ]),
            daemon: false,
            debug: true,
            windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
            masterLeaseFile: '/tmp/wls master lease.json',
            masterToken: 'unit-master-token'
        );
        $this->writePrivate($orchestrator, 'context', $context);

        $cmd = $this->invokePrivate(
            $orchestrator,
            'appendInstanceIdentityArgs',
            [
                'php worker.php',
                new ServiceInstance(ControlMessage::ROLE_WORKER, 1, 7, 'launch-ut'),
            ]
        );

        self::assertStringContainsString('--master-lease-file=', $cmd);
        self::assertStringContainsString('/tmp/wls master lease.json', $cmd);
        self::assertStringContainsString('--master-token=', $cmd);
        self::assertStringContainsString('unit-master-token', $cmd);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }
}
