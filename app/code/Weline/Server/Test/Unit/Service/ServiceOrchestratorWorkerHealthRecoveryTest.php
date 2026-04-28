<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorWorkerHealthRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', true);
        }
        WlsLogger::reset();
        WlsLogger::getInstance()
            ->setStdoutEnabled(false)
            ->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        WlsLogger::reset();
    }

    public function testShouldAttemptWorkerAccessRecoveryRequiresStartupReadyAndWorkerReadyState(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18081,
        );

        self::assertFalse($this->invokePrivate($orchestrator, 'shouldAttemptWorkerAccessRecovery', [$worker]));

        $this->writePrivate($orchestrator, 'startupAcceptanceComplete', true);
        self::assertTrue($this->invokePrivate($orchestrator, 'shouldAttemptWorkerAccessRecovery', [$worker]));

        $worker->state = ServiceInstance::STATE_STARTING;
        self::assertFalse($this->invokePrivate($orchestrator, 'shouldAttemptWorkerAccessRecovery', [$worker]));
    }

    public function testAttemptWorkerAccessRecoverySucceedsWhenHealthEndpointAccessible(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'startupAcceptanceComplete', true);

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, $errstr ?: 'failed to create local socket server');
        \stream_set_blocking($server, false);
        $name = \stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = (int) \substr((string) \strrchr($name, ':'), 1);
        self::assertGreaterThan(0, $port);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: $port,
        );

        $recovered = $this->invokePrivate(
            $orchestrator,
            'attemptWorkerAccessRecovery',
            [$worker, 'unit_test_unhealthy']
        );

        @\fclose($server);

        self::assertTrue($recovered);
        self::assertGreaterThan(0.0, (float) $worker->getMeta('worker_access_recovery_at', 0.0));
        self::assertSame('unit_test_unhealthy', (string) $worker->getMeta('worker_access_recovery_reason', ''));
    }

    public function testHealthRestartCooldownPreventsImmediateRepeatedRestart(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'context', $this->createContextWithHealthRestartCooldown(5.0));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18081,
        );

        $first = $this->invokePrivate(
            $orchestrator,
            'shouldThrottleHealthRestart',
            [$worker, 'unit_test_reason']
        );
        $second = $this->invokePrivate(
            $orchestrator,
            'shouldThrottleHealthRestart',
            [$worker, 'unit_test_reason']
        );

        self::assertFalse($first);
        self::assertTrue($second);
        self::assertGreaterThan(0.0, (float) $worker->getMeta('health_restart_last_at', 0.0));
        self::assertSame('unit_test_reason', (string) $worker->getMeta('health_restart_last_reason', ''));
    }

    private function createContextWithHealthRestartCooldown(float $cooldown): ServiceContext
    {
        return new ServiceContext(
            instanceName: 'test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            frontend: false,
            envConfig: [
                'wls' => [
                    'orchestrator' => [
                        'health_restart_cooldown_sec' => $cooldown,
                    ],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue($object, $value);
                return;
            }
            $reflection = $reflection->getParentClass();
        }

        self::fail("property {$property} not found");
    }
}

