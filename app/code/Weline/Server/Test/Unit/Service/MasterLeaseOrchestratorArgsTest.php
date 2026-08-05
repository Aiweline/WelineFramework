<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;
use Weline\Server\Service\MasterChildCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Supervisor\Client\SupervisorChildClient;

final class MasterLeaseOrchestratorArgsTest extends TestCase
{
    public function testAllManagedChildArgvHidesCredential(): void
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

        self::assertTrue(\str_contains($workerCommand, '--master-lease-file='));
        self::assertFalse(\str_contains($workerCommand, '--master-token='));
        self::assertFalse(\str_contains($workerCommand, $token));
        self::assertTrue(\str_contains($agentCommand, '--master-lease-file='));
        self::assertFalse(\str_contains($agentCommand, '--master-token='));
        self::assertFalse(\str_contains($agentCommand, $token));
        $orchestratorSource = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/ServiceOrchestrator.php',
        );
        self::assertStringNotContainsString(
            '--master-token=',
            $orchestratorSource,
            'POSIX command strings and Windows detached argv must never publish the Master credential.',
        );
    }

    public function testMasterStopsImmediatelyAfterAnotherGenerationOwnsItsLease(): void
    {
        $instance = 'master-lease-fence-ut-' . \bin2hex(\random_bytes(4));
        $ownerToken = \str_repeat('e', 64);
        $foreignToken = \str_repeat('f', 64);
        $manager = $this->testLeaseManager();
        $path = $manager->writeRunning($instance, 32101, 19201, 8, $foreignToken);
        $orchestrator = new ServiceOrchestrator();
        $context = new ServiceContext(
            instanceName: $instance,
            epoch: 7,
            controlPort: 19200,
            masterPid: 32100,
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
            masterLeaseFile: $path,
            masterToken: $ownerToken,
        );

        try {
            $this->writePrivate($orchestrator, 'context', $context);
            $this->writePrivate($orchestrator, 'running', true);
            $this->invokePrivate($orchestrator, 'touchMasterLeaseIfDue', [(\hrtime(true) / 1_000_000_000)]);

            self::assertSame(
                'master_lease_ownership_lost',
                $this->readPrivate($orchestrator, 'pendingStopReason'),
            );
            self::assertTrue($this->readPrivate($orchestrator, 'pendingStopSkipDrain'));
            self::assertSame(1, $this->readPrivate($orchestrator, 'masterLeaseTouchFailureCount'));
            $lease = $manager->readProtected($path);
            self::assertIsArray($lease);
            self::assertSame(32101, $lease['master_pid'] ?? null);
            self::assertSame($foreignToken, $lease['master_token'] ?? null);
        } finally {
            @\unlink($path);
            @\unlink(MasterLeaseManager::lockPathForInstance($instance));
            @\rmdir(\dirname($path));
        }
    }

    public function testProtectedLeaseCredentialIsSubjectBoundAndInvalidStatesFailClosed(): void
    {
        $instance = 'gateway-lease-ut-' . \bin2hex(\random_bytes(4));
        $token = \hash('sha256', 'gateway-agent-unit-credential');
        $masterPid = (int)\getmypid();
        $epoch = 7;
        $runtimeIdentity = $this->testRuntimeIdentity();
        $manager = new MasterLeaseManager($runtimeIdentity);
        $store = new MasterChildCredentialStore($manager, $runtimeIdentity);
        $path = $manager->writeRunning($instance, $masterPid, 19091, $epoch, $token);
        $store->authorizeServices($path, $instance, $masterPid, $epoch, $token, [[
            'role' => ControlMessage::ROLE_GATEWAY_AGENT,
            'slot_id' => ControlMessage::ROLE_GATEWAY_AGENT . '#1',
            'launch_id' => 'launch-ut',
            'lease_id' => 'lease-ut',
            'generation' => 1,
            'pid' => $masterPid,
        ]]);

        try {
            $resolved = $manager->resolveProtectedCredential(
                $path,
                $instance,
                $masterPid,
                $epoch,
                'launch-ut',
                'lease-ut',
                1,
                ControlMessage::ROLE_GATEWAY_AGENT,
                ControlMessage::ROLE_GATEWAY_AGENT . '#1',
            );
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $resolved);
            self::assertFalse(\hash_equals($token, $resolved));
            $arguments = [
                'child.php',
                '--master-pid=' . $masterPid,
                '--epoch=' . $epoch,
                '--launch-id=launch-ut',
                '--master-lease-file=' . $path,
                '--lease-id=lease-ut',
                '--slot-id=' . ControlMessage::ROLE_GATEWAY_AGENT . '#1',
                '--slot-generation=1',
            ];
            self::assertSame(
                $resolved,
                $manager->resolveProtectedCredentialFromArguments($arguments, $instance),
            );
            $runtimeCredential = $manager->resolveProtectedRuntimeCredentialFromArguments(
                $arguments,
                $instance,
            );
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $runtimeCredential);
            self::assertFalse(\hash_equals($token, $runtimeCredential));
            self::assertFalse(\hash_equals($resolved, $runtimeCredential));

            if (PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(@\chmod($path, 0660));
                self::assertNull(
                    $manager->readProtected($path),
                    'A group-writable Master lease must not expose the Agent credential.',
                );
                self::assertTrue(@\chmod($path, 0600));
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
                    ControlMessage::ROLE_GATEWAY_AGENT,
                    ControlMessage::ROLE_GATEWAY_AGENT . '#1',
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
                    ControlMessage::ROLE_GATEWAY_AGENT,
                    ControlMessage::ROLE_GATEWAY_AGENT . '#1',
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
                    ControlMessage::ROLE_GATEWAY_AGENT,
                    ControlMessage::ROLE_GATEWAY_AGENT . '#1',
                ),
            );

            $lease = $manager->readProtected($path);
            self::assertIsArray($lease);
            $lease['updated_monotonic'] = 1.0;
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
                    ControlMessage::ROLE_GATEWAY_AGENT,
                    ControlMessage::ROLE_GATEWAY_AGENT . '#1',
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
                    ControlMessage::ROLE_GATEWAY_AGENT,
                    ControlMessage::ROLE_GATEWAY_AGENT . '#1',
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
                    ControlMessage::ROLE_GATEWAY_AGENT,
                    ControlMessage::ROLE_GATEWAY_AGENT . '#1',
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
                    ControlMessage::ROLE_GATEWAY_AGENT,
                    ControlMessage::ROLE_GATEWAY_AGENT . '#1',
                ),
            );
        } finally {
            @\unlink($path);
            @\unlink(MasterLeaseManager::lockPathForInstance($instance));
            @\unlink(MasterChildCredentialStore::pathForInstance($instance));
            @\unlink(MasterChildCredentialStore::lockPathForInstance($instance));
            @\rmdir(\dirname($path));
        }
    }

    public function testFullRestartEpochAdvanceIsIdentityBoundAndImmediatelyUsable(): void
    {
        $instance = 'master-epoch-cas-ut-' . \bin2hex(\random_bytes(4));
        $token = \hash('sha256', 'master-epoch-cas-unit-credential');
        $masterPid = (int)\getmypid();
        $controlPort = 19092;
        $runtimeIdentity = $this->testRuntimeIdentity();
        $manager = new MasterLeaseManager($runtimeIdentity);
        $store = new MasterChildCredentialStore($manager, $runtimeIdentity);
        $path = $manager->writeRunning($instance, $masterPid, $controlPort, 3, $token);
        $store->authorizeServices($path, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#1',
            'launch_id' => 'launch-old',
            'lease_id' => 'lease-old',
            'generation' => 1,
            'pid' => $masterPid,
        ]]);

        try {
            $manager->advanceRunningEpoch(
                $instance,
                $masterPid,
                $controlPort,
                3,
                4,
                $token,
            );

            $this->assertCredentialRejected(
                static fn (): string => $manager->resolveProtectedCredential(
                    $path,
                    $instance,
                    $masterPid,
                    3,
                    'launch-old',
                    'lease-old',
                    1,
                    ControlMessage::ROLE_WORKER,
                    ControlMessage::ROLE_WORKER . '#1',
                ),
            );
            $store->authorizeServices($path, $instance, $masterPid, 4, $token, [[
                'role' => ControlMessage::ROLE_WORKER,
                'slot_id' => ControlMessage::ROLE_WORKER . '#1',
                'launch_id' => 'launch-new',
                'lease_id' => 'lease-new',
                'generation' => 2,
                'pid' => $masterPid,
            ]]);
            $newCredential = $manager->resolveProtectedCredential(
                $path,
                $instance,
                $masterPid,
                4,
                'launch-new',
                'lease-new',
                2,
                ControlMessage::ROLE_WORKER,
                ControlMessage::ROLE_WORKER . '#1',
            );
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $newCredential);
            self::assertFalse(\hash_equals($token, $newCredential));

            foreach ([
                [$masterPid + 1, $controlPort, 4, 5, $token],
                [$masterPid, $controlPort + 1, 4, 5, $token],
                [$masterPid, $controlPort, 3, 4, $token],
                [$masterPid, $controlPort, 4, 6, $token],
                [$masterPid, $controlPort, 4, 5, \hash('sha256', 'foreign-token')],
            ] as [$pid, $port, $expected, $next, $candidateToken]) {
                $this->assertCredentialRejected(
                    static fn (): string => $manager->advanceRunningEpoch(
                        $instance,
                        $pid,
                        $port,
                        $expected,
                        $next,
                        $candidateToken,
                    ),
                );
            }

            $manager->touchRunning($instance, $masterPid, $controlPort, 4, $token);
            $lease = $manager->readProtected($path);
            self::assertIsArray($lease);
            self::assertSame(4, $lease['master_epoch'] ?? null);
        } finally {
            @\unlink($path);
            @\unlink(MasterLeaseManager::lockPathForInstance($instance));
            @\unlink(MasterChildCredentialStore::pathForInstance($instance));
            @\unlink(MasterChildCredentialStore::lockPathForInstance($instance));
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
        GatewayProjectStateFilesystem::atomicWrite($path, $contents, 0600);
    }

    private function testLeaseManager(): MasterLeaseManager
    {
        return new MasterLeaseManager($this->testRuntimeIdentity());
    }

    private function testRuntimeIdentity(): MasterLeaseRuntimeIdentity
    {
        $namespace = 'pid:[4026532444]';
        return new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: static fn (): string => \str_repeat('6', 64),
            monotonicClock: static fn (): float => \hrtime(true) / 1_000_000_000,
            processInfoResolver: static fn (int $pid): array => [
                'exists' => $pid > 0,
                'name' => $pid > 0 ? 'php' : '',
                'command' => $pid > 0 ? 'php bin/w --name=unit-master-' . $pid : '',
                'start_time' => $pid > 0 ? 'unit-birth-' . $pid : '',
            ],
            managedProcessVerifier: static fn (int $pid, string $instance): bool => $pid > 0,
            pidNamespaceResolver: static fn (int $pid): ?string => $pid > 0 ? $namespace : null,
        );
    }


    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
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
