<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Service\Control\HybridControlPlaneServer;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;

final class HybridControlPlaneServerTest extends TestCase
{
    public function testHybridServerAcceptsArrayCallablesForLegacyCallbacks(): void
    {
        $legacy = new MasterControlServer();
        $hybrid = new HybridControlPlaneServer(
            legacyServer: $legacy,
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
        $legacy = new MasterControlServer();
        $hybrid = new HybridControlPlaneServer(
            legacyServer: $legacy,
            endpointResolver: new ControlEndpointResolver(BP, 28000, 1000),
            supervisorEnabled: true,
            channelId: 'channel-ut-instance',
        );
        $hybrid->setExpectedInstanceCode('ut-instance');

        $messages = [];
        $disconnects = [];
        $hybrid->onMessage(function (array $msg, int $clientId, object $server) use (&$messages): void {
            unset($server);
            $messages[] = [$msg, $clientId];
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

            $hybrid->closeClient($readyMessages[0][1]);
            self::assertCount(1, $disconnects);
            self::assertSame($readyMessages[0][1], $disconnects[0][0]);
            self::assertSame(ControlMessage::ROLE_WORKER, $disconnects[0][1]['role'] ?? null);
            self::assertSame(18081, $disconnects[0][1]['port'] ?? null);
        } finally {
            $client->close();
            $hybrid->close();
        }
    }

    public function testHybridServerCountServiceClientsIncludesSupervisorSessions(): void
    {
        $legacy = new MasterControlServer();
        $hybrid = new HybridControlPlaneServer(
            legacyServer: $legacy,
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
