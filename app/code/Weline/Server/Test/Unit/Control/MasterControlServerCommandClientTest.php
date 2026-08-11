<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;

final class MasterControlServerCommandClientTest extends TestCase
{
    public function testWindowsNativeSocketBridgeIsDisabledByDefault(): void
    {
        $server = new MasterControlServer();
        self::assertTrue($server->start('127.0.0.1', 0));

        try {
            self::assertFalse($server->isWindowsNativeSocketBridgeEnabled());
            self::assertFalse($server->isUsingWindowsNativeSocketBridge());
        } finally {
            $server->close();
        }
    }

    public function testCommandClientIsClassifiedAsControlBeforeDisconnect(): void
    {
        $server = new MasterControlServer();
        self::assertTrue($server->start('127.0.0.1', 0));

        $client = @\stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
        self::assertNotFalse($client, $errstr ?: 'Failed to connect to MasterControlServer test socket');

        $disconnectInfo = null;
        $server->onDisconnect(static function (int $clientId, array $clientInfo) use (&$disconnectInfo): void {
            $disconnectInfo = $clientInfo;
        });

        try {
            \fwrite($client, ControlMessage::command(ControlMessage::ACTION_STATUS));

            $connectedClients = $this->waitForConnectedClients($server, 2.0);
            self::assertCount(1, $connectedClients);
            $clientInfo = \array_values($connectedClients)[0];
            self::assertSame('control', $clientInfo['role']);

            @\fclose($client);

            $this->waitForCondition(
                static function () use (&$disconnectInfo): bool {
                    return \is_array($disconnectInfo);
                },
                2.0,
                static fn () => $server->poll(0, 10000)
            );

            self::assertIsArray($disconnectInfo);
            self::assertSame('control', $disconnectInfo['role'] ?? null);
            self::assertSame('peer_eof', $disconnectInfo['disconnect_reason'] ?? null);
        } finally {
            if (\is_resource($client)) {
                @\fclose($client);
            }
            $server->close();
        }
    }

    public function testTransientControlLifecycleLogsAreThrottledByHost(): void
    {
        $server = new MasterControlServer();
        $this->writePrivate($server, 'clients', [
            101 => ['role' => 'control', 'peer_name' => '127.0.0.1:50001'],
            102 => ['role' => 'control', 'peer_name' => '127.0.0.1:50002'],
            201 => ['role' => 'worker', 'peer_name' => '127.0.0.1:50003'],
        ]);

        $method = new \ReflectionMethod(MasterControlServer::class, 'shouldSuppressTransientClientLifecycleLog');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($server, 101, 'write_connection_closed'));
        self::assertTrue($method->invoke($server, 102, 'write_connection_closed'));
        self::assertFalse($method->invoke($server, 201, 'write_connection_closed'));

        $this->writePrivate($server, 'transientClientLifecycleLoggedAt', [
            'control:write_connection_closed:127.0.0.1' => \time() + 3600.0,
        ]);
        self::assertFalse(
            $method->invoke($server, 101, 'write_connection_closed'),
            'legacy/future wall values must not suppress monotonic lifecycle logging forever',
        );
    }

    public function testOnlyComparableMonotonicPongRefreshesHealthObservation(): void
    {
        $server = new MasterControlServer();
        $this->writePrivate($server, 'clients', [
            101 => [
                'role' => ControlMessage::ROLE_WORKER,
                'pid' => 12001,
                'worker_id' => 1,
                'peer_name' => '127.0.0.1:50001',
                'launch_id' => 'launch-monotonic-pong',
                'message_count' => 0,
                'last_message_type' => '',
            ],
        ]);
        $dispatch = new \ReflectionMethod(MasterControlServer::class, 'dispatchDecodedControlMessage');
        $dispatch->setAccessible(true);

        $ping = ControlMessage::decode(ControlMessage::ping());
        self::assertIsArray($ping);
        $validPong = ControlMessage::decode(ControlMessage::pongForPing($ping));
        self::assertIsArray($validPong);
        $dispatch->invoke($server, 101, $validPong);

        $accepted = $server->getLastPongObservation('launch-monotonic-pong');
        self::assertIsArray($accepted);
        self::assertSame(
            (float)($ping['monotonic_timestamp'] ?? 0.0),
            $accepted['ping_monotonic'],
        );

        $future = $validPong;
        $future['ping_monotonic'] = ControlMessage::monotonicSeconds() + 60.0;
        $future['pong_monotonic'] = $future['ping_monotonic'];
        $dispatch->invoke($server, 101, $future);
        self::assertSame($accepted, $server->getLastPongObservation('launch-monotonic-pong'));

        $legacy = ControlMessage::decode(ControlMessage::pong((float)($ping['timestamp'] ?? 1.0)));
        self::assertIsArray($legacy);
        $dispatch->invoke($server, 101, $legacy);
        self::assertSame(
            $accepted,
            $server->getLastPongObservation('launch-monotonic-pong'),
            'legacy wall-clock pong must not refresh health evidence',
        );
    }

    public function testManagedChildClassicTcpRegisterFailsClosedAndAuthenticatesConnection(): void
    {
        $server = new MasterControlServer();
        $server->setExpectedInstanceCode('unit-managed-child');
        $credential = \str_repeat('a', 64);
        $expectedTuple = [
            'instance' => 'unit-managed-child',
            'role' => ControlMessage::ROLE_GATEWAY_AGENT,
            'slot_id' => ControlMessage::ROLE_GATEWAY_AGENT . '#1',
            'launch_nonce' => 'launch-managed-child',
            'lease_id' => 'lease-managed-child',
            'generation' => 7,
            'pid' => 12007,
        ];
        $server->setManagedChildCredentialResolver(
            static fn (array $message): string => $message === $expectedTuple ? $credential : '',
        );
        self::assertTrue($server->start('127.0.0.1', 0));

        $messages = [];
        $disconnectReasons = [];
        $server->onMessage(static function (array $message) use (&$messages): void {
            $messages[] = $message;
        });
        $server->onDisconnect(static function (int $clientId, array $clientInfo) use (&$disconnectReasons): void {
            unset($clientId);
            $disconnectReasons[] = (string)($clientInfo['disconnect_reason'] ?? '');
        });

        try {
            foreach (['', \str_repeat('b', 64)] as $invalidCredential) {
                $client = $this->connect($server);
                \fwrite($client, $this->managedChildRegister($invalidCredential));
                $this->waitForCondition(
                    static function () use (&$disconnectReasons): bool {
                        return \count($disconnectReasons) >= 1;
                    },
                    2.0,
                    static fn () => $server->poll(0, 10000),
                );
                self::assertSame('managed_child_auth_failed', \array_shift($disconnectReasons));
                self::assertSame([], $messages, 'Rejected credentials must never reach the Orchestrator handler.');
                @\fclose($client);
            }

            $client = $this->connect($server);
            \fwrite($client, $this->managedChildRegister($credential));
            $this->waitForCondition(
                static function () use (&$messages): bool {
                    return \count($messages) === 1;
                },
                2.0,
                static fn () => $server->poll(0, 10000),
            );
            self::assertSame(ControlMessage::TYPE_REGISTER, $messages[0]['type'] ?? null);
            self::assertArrayNotHasKey('managed_child_credential', $messages[0]);
            $connected = $server->getConnectedClients();
            self::assertCount(1, $connected);
            $agents = $server->getClientsByRole(ControlMessage::ROLE_GATEWAY_AGENT);
            self::assertCount(1, $agents);
            self::assertTrue((bool)(\array_values($agents)[0]['managed_child_authenticated'] ?? false));

            \fwrite($client, ControlMessage::encode([
                'type' => ControlMessage::TYPE_READY,
                'role' => ControlMessage::ROLE_GATEWAY_AGENT,
            ]));
            $this->waitForCondition(
                static function () use (&$messages): bool {
                    return \count($messages) === 2;
                },
                2.0,
                static fn () => $server->poll(0, 10000),
            );
            \fwrite($client, ControlMessage::command(ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE));
            $this->waitForCondition(
                static function () use (&$messages): bool {
                    return \count($messages) === 3;
                },
                2.0,
                static fn () => $server->poll(0, 10000),
            );
            self::assertSame(ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE, $messages[2]['action'] ?? null);
            @\fclose($client);
        } finally {
            $server->close();
        }
    }

    public function testReadyBeforeRegisterCannotReachOrchestratorHandler(): void
    {
        $server = new MasterControlServer();
        $server->setManagedChildCredentialResolver(
            static fn (array $message): string => \str_repeat('d', 64),
        );
        self::assertTrue($server->start('127.0.0.1', 0));
        $messages = [];
        $disconnectReason = '';
        $server->onMessage(static function (array $message) use (&$messages): void {
            $messages[] = $message;
        });
        $server->onDisconnect(static function (int $clientId, array $clientInfo) use (&$disconnectReason): void {
            unset($clientId);
            $disconnectReason = (string)($clientInfo['disconnect_reason'] ?? '');
        });
        $client = $this->connect($server);

        try {
            \fwrite($client, ControlMessage::encode([
                'type' => ControlMessage::TYPE_READY,
                'role' => ControlMessage::ROLE_GATEWAY_AGENT,
                'pid' => 12017,
                'worker_id' => 1,
                'epoch' => 17,
                'launch_id' => 'forged-launch',
                'slot_id' => ControlMessage::ROLE_GATEWAY_AGENT . '#1',
                'lease_id' => 'forged-lease',
                'generation' => 17,
            ]));
            $this->waitForCondition(
                static function () use (&$disconnectReason): bool {
                    return $disconnectReason !== '';
                },
                2.0,
                static fn () => $server->poll(0, 10000),
            );
            self::assertSame('ready_before_register', $disconnectReason);
            self::assertSame([], $messages);
            self::assertSame([], $server->getConnectedClients());
        } finally {
            @\fclose($client);
            $server->close();
        }
    }

    public function testControlClientCarriesManagedChildCredentialAcrossReconnect(): void
    {
        $server = new MasterControlServer();
        $server->setExpectedInstanceCode('unit-control-client');
        $credential = \str_repeat('c', 64);
        $server->setManagedChildCredentialResolver(
            static function (array $message) use ($credential): string {
                return $message === [
                    'instance' => 'unit-control-client',
                    'role' => ControlMessage::ROLE_WORKER,
                    'slot_id' => ControlMessage::ROLE_WORKER . '#1',
                    'launch_nonce' => 'launch-control-client',
                    'lease_id' => 'lease-control-client',
                    'generation' => 11,
                    'pid' => 12011,
                ] ? $credential : '';
            },
        );
        self::assertTrue($server->start('127.0.0.1', 0));
        $messages = [];
        $server->onMessage(static function (array $message) use (&$messages): void {
            $messages[] = $message;
        });
        $hadArgv = \array_key_exists('argv', $GLOBALS);
        $originalArgv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = [
            'phpunit',
            '--slot-id=' . ControlMessage::ROLE_WORKER . '#1',
            '--lease-id=lease-control-client',
            '--slot-generation=11',
        ];
        $client = new ControlClient();
        $client->setManagedChildCredential($credential);

        try {
            self::assertTrue($client->connect('127.0.0.1', $server->getPort()));
            self::assertTrue($client->register(
                role: ControlMessage::ROLE_WORKER,
                pid: 12011,
                workerId: 1,
                epoch: 11,
                launchId: 'launch-control-client',
                instanceCode: 'unit-control-client',
            ));
            self::assertTrue($client->flushPendingWrites(0.2));
            $this->waitForCondition(
                static function () use (&$messages): bool {
                    return \count($messages) === 1;
                },
                2.0,
                static fn () => $server->poll(0, 10000),
            );
            self::assertArrayNotHasKey('managed_child_credential', $messages[0]);

            $clientId = (int)\array_key_first($server->getConnectedClients());
            self::assertGreaterThan(0, $clientId);
            $server->closeClient($clientId);
            $this->waitForCondition(
                static fn (): bool => !$client->isConnected(),
                2.0,
                static fn () => $client->handleReadable(),
            );
            self::assertTrue($client->tryReconnect());
            self::assertTrue($client->flushPendingWrites(0.2));
            $this->waitForCondition(
                static function () use (&$messages): bool {
                    return \count($messages) === 2;
                },
                2.0,
                static fn () => $server->poll(0, 10000),
            );
            self::assertArrayNotHasKey('managed_child_credential', $messages[1]);
            self::assertTrue((bool)(\array_values(
                $server->getClientsByRole(ControlMessage::ROLE_WORKER),
            )[0]['managed_child_authenticated'] ?? false));
        } finally {
            $client->close();
            $server->close();
            if ($hadArgv) {
                $GLOBALS['argv'] = $originalArgv;
            } else {
                unset($GLOBALS['argv']);
            }
        }
    }

    /** @return resource */
    private function connect(MasterControlServer $server)
    {
        $client = @\stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
        self::assertNotFalse($client, $errstr ?: 'Failed to connect to MasterControlServer test socket');

        return $client;
    }

    private function managedChildRegister(string $credential): string
    {
        return ControlMessage::register(
            role: ControlMessage::ROLE_GATEWAY_AGENT,
            pid: 12007,
            workerId: 1,
            epoch: 7,
            launchId: 'launch-managed-child',
            instanceCode: 'unit-managed-child',
            slotId: ControlMessage::ROLE_GATEWAY_AGENT . '#1',
            leaseId: 'lease-managed-child',
            generation: 7,
            managedChildCredential: $credential,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function waitForConnectedClients(MasterControlServer $server, float $timeoutSec): array
    {
        $clients = [];
        $this->waitForCondition(
            static function () use ($server, &$clients): bool {
                $clients = $server->getConnectedClients();

                return $clients !== [];
            },
            $timeoutSec,
            static fn () => $server->poll(0, 10000)
        );

        return $clients;
    }

    private function waitForCondition(callable $condition, float $timeoutSec, ?callable $tick = null): void
    {
        $deadline = \microtime(true) + $timeoutSec;
        while (\microtime(true) < $deadline) {
            if ($tick !== null) {
                $tick();
            }

            if ($condition()) {
                return;
            }

            \usleep(10000);
        }

        self::fail('Condition was not satisfied before timeout.');
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
