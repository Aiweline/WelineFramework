<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Supervisor;
use Weline\Server\Supervisor\SupervisorRuntime;
use Weline\Server\Supervisor\SupervisorServer;

final class SupervisorChildClientTest extends TestCase
{
    public function testReadyIntentDoesNotCountAsConfirmedUntilAckArrives(): void
    {
        $runtime = $this->createRuntime('ut-instance');
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', 0));

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
            endpoint: $endpoint,
            progressCallback: static function () use ($server): void {
                $server->poll(0, 10000);
            },
        );

        try {
            self::assertFalse($client->isReadyStateConfirmed());

            $client->markReadyState(true);
            self::assertFalse($client->isReadyStateConfirmed());

            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: 'worker',
                pid: 12001,
                port: 18081,
                workerId: 1,
                launchId: 'launch-1',
                instanceCode: 'ut-instance',
                msgId: 'hello-1',
            ));
            self::assertFalse($client->isReadyStateConfirmed());

            self::assertTrue($client->sendReady(
                role: 'worker',
                workerId: 1,
                port: 18081,
                launchId: 'launch-1',
                msgId: 'ready-1',
            ));
            self::assertTrue($client->isReadyStateConfirmed());
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testReconnectRequiresFreshReadyAckWhilePreservingReadyIntent(): void
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($probe, (string) $errstr);
        $probeName = \stream_socket_get_name($probe, false);
        self::assertIsString($probeName);
        $parts = \explode(':', $probeName);
        $port = (int) \end($parts);
        @\fclose($probe);

        $runtime = $this->createRuntime('ut-instance');
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', $port));

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
            endpoint: $endpoint,
            progressCallback: static function () use (&$server): void {
                $server?->poll(0, 10000);
            },
        );

        try {
            $client->markReadyState(true);
            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: 'worker',
                pid: 12002,
                port: 18082,
                workerId: 2,
                launchId: 'launch-2',
                instanceCode: 'ut-instance',
                msgId: 'hello-2',
            ));
            self::assertTrue($client->sendReady(
                role: 'worker',
                workerId: 2,
                port: 18082,
                launchId: 'launch-2',
                msgId: 'ready-2',
            ));
            self::assertTrue($client->isReadyStateConfirmed());

            $server->close();
            for ($i = 0; $i < 10; $i++) {
                $client->handleReadable();
                if (!$client->isConnected()) {
                    break;
                }
                \usleep(10000);
            }

            self::assertFalse($client->isConnected());
            self::assertFalse($client->isReadyStateConfirmed());

            $server = new SupervisorServer($runtime);
            $server->start(ControlEndpoint::tcp('127.0.0.1', $port));

            self::assertTrue($client->tryReconnect());
            self::assertTrue($client->isConnected());
            self::assertTrue($client->isReadyStateConfirmed());
        } finally {
            $client->close();
            $server?->close();
        }
    }

    private function createRuntime(string $instanceName): SupervisorRuntime
    {
        return new SupervisorRuntime(
            instanceName: $instanceName,
            channelId: 'channel-' . $instanceName,
            endpointResolver: new ControlEndpointResolver('/srv/weline', 27000, 1000),
            supervisor: new Supervisor(new LeaseRegistry(
                static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}"
            )),
        );
    }
}
