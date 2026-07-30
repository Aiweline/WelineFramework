<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Supervisor\Client\SupervisorChildClient;

final class MasterLeaseOrchestratorArgsTest extends TestCase
{
    public function testGatewayAgentArgvHidesCredentialWhileLegacyRolesRemainCompatible(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $token = \str_repeat('a', 64);
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
            masterToken: $token,
        );
        $this->writePrivate($orchestrator, 'context', $context);

        $workerCommand = $this->invokePrivate(
            $orchestrator,
            'appendInstanceIdentityArgs',
            ['php worker.php', new ServiceInstance(ControlMessage::ROLE_WORKER, 1, 7, 'launch-ut')],
        );
        $agentCommand = $this->invokePrivate(
            $orchestrator,
            'appendInstanceIdentityArgs',
            ['php agent.php', new ServiceInstance(ControlMessage::ROLE_GATEWAY_AGENT, 1, 7, 'launch-ut')],
        );

        self::assertTrue(\str_contains($workerCommand, '--master-token='));
        self::assertTrue(\str_contains($workerCommand, $token));
        self::assertTrue(\str_contains($agentCommand, '--master-lease-file='));
        self::assertFalse(\str_contains($agentCommand, '--master-token='));
        self::assertFalse(\str_contains($agentCommand, $token));
        self::assertTrue((bool)$this->invokePrivate(
            $orchestrator,
            'childRoleUsesArgvMasterCredential',
            [ControlMessage::ROLE_WORKER],
        ));
        self::assertFalse((bool)$this->invokePrivate(
            $orchestrator,
            'childRoleUsesArgvMasterCredential',
            [ControlMessage::ROLE_GATEWAY_AGENT],
        ));
    }

    public function testProtectedLeaseCredentialIsSharedAndInvalidStatesFailClosed(): void
    {
        $instance = 'gateway-lease-ut-' . \bin2hex(\random_bytes(4));
        $token = \hash('sha256', 'gateway-agent-unit-credential');
        $masterPid = (int)\getmypid();
        $epoch = 7;
        $manager = new MasterLeaseManager();
        $path = $manager->writeRunning($instance, $masterPid, 19091, $epoch, $token);

        try {
            $resolved = $manager->resolveProtectedCredential(
                $path,
                $instance,
                $masterPid,
                $epoch,
                'launch-ut',
                'lease-ut',
                1,
            );
            self::assertTrue(\hash_equals($token, $resolved));

            if (PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(@\chmod($path, 0660));
                self::assertNull(
                    $manager->readProtected($path),
                    'A group-writable Master lease must not expose the Agent credential.',
                );
                self::assertTrue(@\chmod($path, 0640));
            }

            $guard = new ChildMasterGuard(
                masterPid: $masterPid,
                leaseFile: $path,
                masterToken: $resolved,
                selfTag: 'GatewayAgentUnit',
                instance: $instance,
                masterEpoch: $epoch,
                checkIntervalSec: 0.0,
                leaseManager: $manager,
                strictLeaseFreshness: true,
            );
            self::assertFalse($guard->shouldExit(true));

            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid + 1,
                    $epoch,
                    'launch-ut',
                    'lease-ut',
                    1,
                ),
            );
            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid,
                    $epoch + 1,
                    'launch-ut',
                    'lease-ut',
                    1,
                ),
            );
            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid,
                    $epoch,
                    '',
                    'lease-ut',
                    1,
                ),
            );

            $lease = $manager->readProtected($path);
            self::assertIsArray($lease);
            $lease['updated_at'] = \microtime(true) - MasterLeaseManager::HEARTBEAT_STALE_SEC - 1.0;
            $this->writeLeasePayload($path, $lease);
            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid,
                    $epoch,
                    'launch-ut',
                    'lease-ut',
                    1,
                ),
            );
            self::assertTrue($guard->shouldExit(true));

            $manager->writeRunning($instance, $masterPid, 19091, $epoch, $token);
            $lease = $manager->readProtected($path);
            self::assertIsArray($lease);
            $lease['master_token'] = '';
            $this->writeLeasePayload($path, $lease);
            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid,
                    $epoch,
                    'launch-ut',
                    'lease-ut',
                    1,
                ),
            );

            $this->writeLeasePayload($path, '{malformed');
            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid,
                    $epoch,
                    'launch-ut',
                    'lease-ut',
                    1,
                ),
            );

            @\unlink($path);
            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid,
                    $epoch,
                    'launch-ut',
                    'lease-ut',
                    1,
                ),
            );
        } finally {
            @\unlink($path);
            @\rmdir(\dirname($path));
        }
    }

    public function testSupervisorHelloUsesExplicitCredentialAndRejectsEmptyStrictMode(): void
    {
        $token = \hash('sha256', 'gateway-agent-supervisor-unit-credential');
        $reflection = new \ReflectionClass(SupervisorChildClient::class);
        /** @var SupervisorChildClient $client */
        $client = $reflection->newInstanceWithoutConstructor();
        $client->setHelloAuthSecret($token, true);
        $resolved = $this->invokePrivate($client, 'resolveHelloAuthSecret', []);
        self::assertTrue(\is_string($resolved) && \hash_equals($token, $resolved));

        /** @var SupervisorChildClient $missing */
        $missing = $reflection->newInstanceWithoutConstructor();
        $this->assertCredentialRejected(
            static function () use ($missing): void {
                $missing->setHelloAuthSecret('', true);
            },
        );
    }

    private function assertCredentialRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected credential resolution to fail closed.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
    }

    /** @param array<string,mixed>|string $payload */
    private function writeLeasePayload(string $path, array|string $payload): void
    {
        $contents = \is_array($payload)
            ? (string)\json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : $payload;
        if (@\file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write unit lease payload.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @\chmod($path, 0640);
        }
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
