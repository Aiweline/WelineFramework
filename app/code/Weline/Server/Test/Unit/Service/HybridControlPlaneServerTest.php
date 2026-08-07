<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Service\Control\HybridControlPlaneServer;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Lease\SlotLease;
use Weline\Server\Supervisor\SupervisorRuntime;

final class HybridControlPlaneServerTest extends TestCase
{
    public function testHybridServerAcceptsArrayCallablesForControlCallbacks(): void
    {
        $controlServer = new MasterControlServer();
        $hybrid = new HybridControlPlaneServer(
            controlServer: $controlServer,
            endpointResolver: new ControlEndpointResolver(BP, 28200, 1000),
            supervisorEnabled: false,
        );

        $collector = new class {
            /** @var list<array{0: array<string, mixed>, 1: int}> */
            public array $messages = [];
            /** @var list<array{0: int, 1: array<string, mixed>}> */
            public array $disconnects = [];

            public function handleMessage(array $msg, int $clientId, object $server): void
            {
                unset($server);
                $this->messages[] = [$msg, $clientId];
            }

            public function handleDisconnect(int $clientId, array $clientInfo, object $server): void
            {
                unset($server);
                $this->disconnects[] = [$clientId, $clientInfo];
            }
        };

        $hybrid->onMessage([$collector, 'handleMessage']);
        $hybrid->onDisconnect([$collector, 'handleDisconnect']);

        self::assertTrue($hybrid->start('127.0.0.1', 0));

        $client = @\stream_socket_client('tcp://127.0.0.1:' . $hybrid->getPort(), $errno, $errstr, 3);
        self::assertNotFalse($client, $errstr ?: 'Failed to connect to HybridControlPlaneServer test socket');

        try {
            \fwrite($client, ControlMessage::command(ControlMessage::ACTION_STATUS));

            $this->pollUntil(static fn() => \count($collector->messages) >= 1, $hybrid);
            self::assertSame(ControlMessage::TYPE_COMMAND, $collector->messages[0][0]['type'] ?? null);
            self::assertSame(ControlMessage::ACTION_STATUS, $collector->messages[0][0]['action'] ?? null);

            @\fclose($client);

            $this->pollUntil(static fn() => \count($collector->disconnects) >= 1, $hybrid);
            self::assertSame('control', $collector->disconnects[0][1]['role'] ?? null);
        } finally {
            if (\is_resource($client)) {
                @\fclose($client);
            }
            $hybrid->close();
        }
    }

    public function testHybridServerBridgesSupervisorHelloReadyAndDisconnect(): void
    {
        $controlServer = new MasterControlServer();
        $resolver = new ControlEndpointResolver(BP, 28000, 1000);
        $runtime = new SupervisorRuntime(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: $resolver,
        );
        $hybrid = new HybridControlPlaneServer(
            controlServer: $controlServer,
            endpointResolver: $resolver,
            supervisorEnabled: true,
            channelId: 'channel-ut-instance',
            supervisorRuntime: $runtime,
        );
        $hybrid->setExpectedInstanceCode('ut-instance');

        $messages = [];
        $disconnects = [];
        $readyLeaseStateBeforeMasterAck = null;
        $hybrid->onMessage(function (array $msg, int $clientId, object $server) use (
            &$messages,
            &$readyLeaseStateBeforeMasterAck,
            $runtime,
        ): void {
            $messages[] = [$msg, $clientId];
            if (($msg['type'] ?? '') !== ControlMessage::TYPE_READY) {
                return;
            }
            $lease = $runtime->supervisor()->leases()->get((string)($msg['slot_id'] ?? ''));
            $readyLeaseStateBeforeMasterAck = $lease?->state;
            $server->sendTo($clientId, ControlMessage::readyAck(
                leaseId: (string)($msg['lease_id'] ?? ''),
                generation: (int)($msg['generation'] ?? 0),
                workerId: (int)($msg['worker_id'] ?? 0),
                port: (int)($msg['port'] ?? 0),
                msgId: (string)($msg['msg_id'] ?? ''),
                slotId: (string)($msg['slot_id'] ?? ''),
            ));
        });
        $hybrid->onDisconnect(function (int $clientId, array $clientInfo, object $server) use (&$disconnects): void {
            unset($server);
            $disconnects[] = [$clientId, $clientInfo];
        });

        self::assertTrue($hybrid->start('127.0.0.1', 0));
        $endpointUri = $hybrid->supervisorEndpointUri();
        self::assertNotNull($endpointUri);

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 28000, 1000),
            progressCallback: static function () use ($hybrid): void {
                $hybrid->poll(0, 10000);
            },
        );

        try {
            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: ControlMessage::ROLE_WORKER,
                pid: 12001,
                port: 18081,
                workerId: 1,
                launchId: 'launch-1',
                instanceCode: 'ut-instance',
                msgId: 'hello-1',
            ));
            $this->pollUntil(static fn() => \count($messages) >= 1, $hybrid);
            self::assertSame(ControlMessage::TYPE_REGISTER, $messages[0][0]['type'] ?? null);
            self::assertSame(ControlMessage::ROLE_WORKER, $messages[0][0]['role'] ?? null);
            self::assertSame(0, $messages[0][0]['port'] ?? null);
            self::assertGreaterThanOrEqual(1000000, $messages[0][1]);

            self::assertTrue($client->sendReady(
                role: ControlMessage::ROLE_WORKER,
                workerId: 1,
                port: 18081,
                launchId: 'launch-1',
                msgId: 'ready-1',
            ));
            $this->pollUntil(
                static fn() => \count(\array_filter($messages, static fn(array $item): bool => ($item[0]['type'] ?? null) === ControlMessage::TYPE_READY)) >= 1,
                $hybrid
            );
            self::assertGreaterThanOrEqual(2, \count($messages));
            $readyMessages = \array_values(\array_filter(
                $messages,
                static fn(array $item): bool => ($item[0]['type'] ?? null) === ControlMessage::TYPE_READY
            ));
            self::assertNotSame([], $readyMessages);
            self::assertSame(18081, $readyMessages[0][0]['port'] ?? null);
            self::assertArrayHasKey('topology', $readyMessages[0][0]);
            self::assertArrayHasKey('homepage_fpc', $readyMessages[0][0]);
            self::assertSame(SlotLease::STATE_LEASED, $readyLeaseStateBeforeMasterAck);
            self::assertSame(
                SlotLease::STATE_READY,
                $runtime->supervisor()->leases()->get('worker#1')?->state,
            );

            self::assertTrue($client->send(ControlMessage::telemetry(
                instance: 'forged-instance',
                host: 'example.test',
                status: 200,
                latencyMs: 5,
                bytesOut: 128,
                ts: 1234567890,
            )));
            self::assertTrue($client->flushPendingWrites(0.25));
            $this->pollUntil(
                static function () use (&$messages): bool {
                    return \count(\array_filter(
                        $messages,
                        static fn(array $item): bool => ($item[0]['type'] ?? null) === ControlMessage::TYPE_TELEMETRY
                    )) >= 1;
                },
                $hybrid
            );
            $telemetryMessages = \array_values(\array_filter(
                $messages,
                static fn(array $item): bool => ($item[0]['type'] ?? null) === ControlMessage::TYPE_TELEMETRY
            ));
            self::assertSame('ut-instance', $telemetryMessages[0][0]['instance'] ?? null);

            self::assertTrue($client->send(ControlMessage::workerPoolAck(
                port: 18081,
                inPool: true,
                slotId: 'worker#1',
                leaseId: 'forged-worker-pool-lease',
                generation: 999,
            )));
            self::assertTrue($client->flushPendingWrites(0.25));
            for ($i = 0; $i < 5; $i++) {
                $hybrid->poll(0, 10000);
            }
            self::assertSame([], \array_values(\array_filter(
                $messages,
                static fn(array $item): bool => ($item[0]['type'] ?? null) === ControlMessage::TYPE_WORKER_POOL_ACK,
            )), 'worker sessions must not forge Dispatcher pool acknowledgements');

            self::assertTrue($client->send(ControlMessage::exitReason('unit-exit', 0)));
            self::assertTrue($client->send(ControlMessage::exited(
                ControlMessage::ROLE_WORKER,
                12001,
                18081,
                1,
                'exit-1',
            )));
            self::assertTrue($client->flushPendingWrites(0.25));
            $deadline = \microtime(true) + 2.0;
            while ($disconnects === [] && \microtime(true) < $deadline) {
                $hybrid->poll(0, 10000);
                \usleep(10000);
            }
            self::assertNotSame(
                [],
                $disconnects,
                'EXITED must close the Supervisor session immediately; observed_types='
                . \json_encode(\array_column(\array_column($messages, 0), 'type'))
                . ', service_clients=' . $hybrid->countServiceClients(),
            );
            self::assertCount(1, $disconnects);
            self::assertSame($readyMessages[0][1], $disconnects[0][0]);
            self::assertSame(ControlMessage::ROLE_WORKER, $disconnects[0][1]['role'] ?? null);
            self::assertSame(18081, $disconnects[0][1]['port'] ?? null);
            self::assertSame('client_exited', $disconnects[0][1]['disconnect_reason'] ?? null);
        } finally {
            $client->close();
            $hybrid->close();
        }
    }

    public function testHybridServerCountServiceClientsIncludesSupervisorSessions(): void
    {
        $controlServer = new MasterControlServer();
        $hybrid = new HybridControlPlaneServer(
            controlServer: $controlServer,
            endpointResolver: new ControlEndpointResolver(BP, 28100, 1000),
            supervisorEnabled: true,
            channelId: 'channel-ut-instance',
        );
        $hybrid->setExpectedInstanceCode('ut-instance');
        $hybrid->onMessage(static function (): void {});
        $hybrid->onDisconnect(static function (): void {});
        self::assertTrue($hybrid->start('127.0.0.1', 0));

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 28100, 1000),
            progressCallback: static function () use ($hybrid): void {
                $hybrid->poll(0, 10000);
            },
        );

        try {
            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: ControlMessage::ROLE_DISPATCHER,
                pid: 12002,
                port: 1443,
                workerId: 0,
                launchId: 'launch-2',
                instanceCode: 'ut-instance',
                msgId: 'hello-2',
            ));
            $this->pollUntil(static fn() => $hybrid->countServiceClients() >= 1, $hybrid);
            self::assertSame(1, $hybrid->countServiceClients());
        } finally {
            $client->close();
            $hybrid->close();
        }
    }

    public function testGatewayAgentSupervisorSessionOnlyForwardsGatewayLifecycleCommands(): void
    {
        $controlServer = new MasterControlServer();
        $resolver = new ControlEndpointResolver(BP, 28300, 1000);
        $runtime = new SupervisorRuntime(
            instanceName: 'ut-gateway-agent',
            channelId: 'channel-ut-gateway-agent',
            endpointResolver: $resolver,
        );
        $hybrid = new HybridControlPlaneServer(
            controlServer: $controlServer,
            endpointResolver: $resolver,
            supervisorEnabled: true,
            channelId: 'channel-ut-gateway-agent',
            supervisorRuntime: $runtime,
        );
        $hybrid->setExpectedInstanceCode('ut-gateway-agent');

        $messages = [];
        $hybrid->onMessage(function (array $msg, int $clientId, object $server) use (&$messages): void {
            $messages[] = [$msg, $clientId];
            if (($msg['type'] ?? '') !== ControlMessage::TYPE_READY) {
                return;
            }
            $server->sendTo($clientId, ControlMessage::readyAck(
                leaseId: (string)($msg['lease_id'] ?? ''),
                generation: (int)($msg['generation'] ?? 0),
                workerId: (int)($msg['worker_id'] ?? 0),
                port: (int)($msg['port'] ?? 0),
                msgId: (string)($msg['msg_id'] ?? ''),
                slotId: (string)($msg['slot_id'] ?? ''),
            ));
        });
        $hybrid->onDisconnect(static function (): void {});
        self::assertTrue($hybrid->start('127.0.0.1', 0));

        $client = new SupervisorChildClient(
            instanceName: 'ut-gateway-agent',
            channelId: 'channel-ut-gateway-agent',
            endpointResolver: $resolver,
            progressCallback: static function () use ($hybrid): void {
                $hybrid->poll(0, 10000);
            },
        );

        try {
            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: ControlMessage::ROLE_GATEWAY_AGENT,
                pid: 12003,
                port: 0,
                workerId: 1,
                launchId: 'gateway-agent-launch-1',
                instanceCode: 'ut-gateway-agent',
                msgId: 'gateway-agent-hello-1',
            ));
            self::assertTrue($client->sendReady(
                role: ControlMessage::ROLE_GATEWAY_AGENT,
                workerId: 1,
                port: 0,
                launchId: 'gateway-agent-launch-1',
                msgId: 'gateway-agent-ready-1',
            ));

            $allowedActions = [
                ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE,
                ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN,
                ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE,
                ControlMessage::ACTION_GATEWAY_BACKEND_ENABLE,
                ControlMessage::ACTION_GATEWAY_NATIVE_DRAIN,
            ];
            foreach ($allowedActions as $action) {
                self::assertTrue($client->send(ControlMessage::command(
                    $action,
                    '',
                    ['port' => 23456],
                )));
            }
            self::assertTrue($client->flushPendingWrites(0.25));
            $this->pollUntil(
                static function () use (&$messages, $allowedActions): bool {
                    $commands = \array_filter(
                        $messages,
                        static fn(array $item): bool =>
                            ($item[0]['type'] ?? null) === ControlMessage::TYPE_COMMAND,
                    );
                    return \count($commands) === \count($allowedActions);
                },
                $hybrid,
            );

            $commands = \array_values(\array_filter(
                $messages,
                static fn(array $item): bool =>
                    ($item[0]['type'] ?? null) === ControlMessage::TYPE_COMMAND,
            ));
            self::assertSame($allowedActions, \array_column(\array_column($commands, 0), 'action'));
            foreach ($commands as [$message, $clientId]) {
                self::assertGreaterThanOrEqual(1000000, $clientId);
                self::assertSame(ControlMessage::ROLE_GATEWAY_AGENT, $message['source_role'] ?? null);
                self::assertSame(12003, $message['source_pid'] ?? null);
                self::assertSame($clientId, $message['source_client_id'] ?? null);
            }

            self::assertTrue($client->send(ControlMessage::command(ControlMessage::ACTION_STOP)));
            self::assertTrue($client->flushPendingWrites(0.25));
            for ($i = 0; $i < 5; $i++) {
                $hybrid->poll(0, 10000);
            }
            $forbidden = \array_filter(
                $messages,
                static fn(array $item): bool =>
                    ($item[0]['action'] ?? null) === ControlMessage::ACTION_STOP,
            );
            self::assertSame([], \array_values($forbidden));
        } finally {
            $client->close();
            $hybrid->close();
        }
    }

    public function testSupervisorPingPongUsesLaunchBoundMonotonicObservation(): void
    {
        $resolver = new ControlEndpointResolver(BP, 28400, 1000);
        $runtime = new SupervisorRuntime(
            instanceName: 'ut-monotonic-pong',
            channelId: 'channel-ut-monotonic-pong',
            endpointResolver: $resolver,
        );
        $hybrid = new HybridControlPlaneServer(
            controlServer: new MasterControlServer(),
            endpointResolver: $resolver,
            supervisorEnabled: true,
            channelId: 'channel-ut-monotonic-pong',
            supervisorRuntime: $runtime,
        );
        $hybrid->setExpectedInstanceCode('ut-monotonic-pong');
        $hybrid->onMessage(static function (): void {});
        $hybrid->onDisconnect(static function (): void {});
        self::assertTrue($hybrid->start('127.0.0.1', 0));

        $client = new SupervisorChildClient(
            instanceName: 'ut-monotonic-pong',
            channelId: 'channel-ut-monotonic-pong',
            endpointResolver: $resolver,
            progressCallback: static function () use ($hybrid): void {
                $hybrid->poll(0, 10000);
            },
        );

        try {
            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: ControlMessage::ROLE_WORKER,
                pid: 12004,
                port: 18084,
                workerId: 4,
                launchId: 'launch-monotonic-supervisor',
                instanceCode: 'ut-monotonic-pong',
                msgId: 'hello-monotonic-supervisor',
            ));

            $pingMessage = ControlMessage::ping();
            self::assertTrue($hybrid->sendToInstance('launch-monotonic-supervisor', $pingMessage));
            $receivedPing = null;
            $this->pollUntil(
                static function () use ($client, &$receivedPing): bool {
                    foreach ($client->handleReadable() as $message) {
                        if (($message['type'] ?? '') === ControlMessage::TYPE_PING) {
                            $receivedPing = $message;
                            return true;
                        }
                    }
                    return false;
                },
                $hybrid,
            );
            self::assertIsArray($receivedPing);
            self::assertTrue($client->send(ControlMessage::pongForPing($receivedPing)));
            self::assertTrue($client->flushPendingWrites(0.25));

            $observation = null;
            $this->pollUntil(
                static function () use ($hybrid, &$observation): bool {
                    $observation = $hybrid->getLastPongObservation('launch-monotonic-supervisor');
                    return \is_array($observation);
                },
                $hybrid,
            );
            self::assertIsArray($observation);
            self::assertSame(
                (float)($receivedPing['monotonic_timestamp'] ?? 0.0),
                $observation['ping_monotonic'],
            );
            self::assertSame(
                (string)($receivedPing['host_boot_id'] ?? ''),
                $observation['host_boot_id'],
            );
        } finally {
            $client->close();
            $hybrid->close();
        }
    }

    private function pollUntil(callable $assertion, HybridControlPlaneServer $server, float $timeoutSec = 2.0): void
    {
        $deadline = \microtime(true) + $timeoutSec;
        while (\microtime(true) < $deadline) {
            $server->poll(0, 10000);
            if ($assertion()) {
                return;
            }
            \usleep(10000);
        }

        self::fail('Expected hybrid control-plane condition was not met in time.');
    }
}
